<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\Document;
use App\Entity\DocumentCategory;
use App\Entity\Project;
use App\Repository\DocumentCategoryRepository;
use App\Repository\DocumentRepository;
use App\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Création et modification des contenus depuis l'admin : validation, slugs uniques, enregistrement.
 * Chaque méthode renvoie les erreurs par champ ; rien n'est enregistré tant qu'il en reste.
 */
final class ContentEditor
{
    public const ACCESS_LEVELS = ['free' => 'Gratuit (compte requis)', 'premium' => 'Premium'];
    public const PROJECT_TYPES = ['fullstack' => 'Fullstack', 'saas' => 'SaaS', 'vitrine' => 'Site vitrine', 'immobilier' => 'Immobilier', 'autre' => 'Autre'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SluggerInterface $slugger,
        private readonly DocumentRepository $documents,
        private readonly DocumentCategoryRepository $categories,
        private readonly ProjectRepository $projects,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, string>
     */
    public function saveDocument(Document $document, array $data): array
    {
        $errors = [];
        $title = $this->text($data, 'title');
        $this->length($errors, 'title', $title, 1, 200);
        $this->length($errors, 'excerpt', $this->text($data, 'excerpt'), 0, 400);
        $access = $this->choice($errors, 'accessLevel', $data, self::ACCESS_LEVELS);
        $readingTime = $this->optionalInt($errors, 'readingTime', $data, 1, 240);

        $category = ctype_digit((string) ($data['category'] ?? '')) ? $this->categories->find((int) $data['category']) : null;
        if ($category === null) {
            $errors['category'] = 'Choisissez un module.';
        }

        $slug = $this->slug($errors, $data, $title, 220, $document->getId(), fn (string $s) => $this->documents->findOneBy(['slug' => $s]));

        if ($errors !== []) {
            return $errors;
        }

        $publish = !empty($data['isPublished']);
        $isNew = $document->getId() === null;
        $document
            ->setTitle($title)
            ->setSlug($slug)
            ->setContent((string) ($data['content'] ?? ''))
            ->setExcerpt($this->text($data, 'excerpt') ?: null)
            ->setAccessLevel($access)
            ->setReadingTime($readingTime)
            ->setCategory($category);

        if ($isNew) {
            $document->setCreatedAt(new \DateTimeImmutable());
            $document->setPosition($this->documents->getMaxPositionInCategory($category) + 1);
            $this->em->persist($document);
        } else {
            $document->setUpdatedAt(new \DateTime());
        }
        if ($publish && !$document->isPublished()) {
            $document->setPublishedAt(new \DateTime());
        }
        $document->setIsPublished($publish);
        $this->em->flush();

        return [];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, string>
     */
    public function saveCategory(DocumentCategory $category, array $data): array
    {
        $errors = [];
        $name = $this->text($data, 'name');
        $this->length($errors, 'name', $name, 1, 100);
        $this->length($errors, 'icon', $this->text($data, 'icon'), 0, 50);
        $access = $this->choice($errors, 'accessLevel', $data, self::ACCESS_LEVELS);
        $slug = $this->slug($errors, $data, $name, 120, $category->getId(), fn (string $s) => $this->categories->findOneBy(['slug' => $s]));

        if ($errors !== []) {
            return $errors;
        }

        $category
            ->setName($name)
            ->setSlug($slug)
            ->setDescription($this->text($data, 'description') ?: null)
            ->setIcon($this->text($data, 'icon') ?: null)
            ->setAccessLevel($access);

        if ($category->getId() === null) {
            $max = (int) $this->categories->createQueryBuilder('c')->select('MAX(c.position)')->getQuery()->getSingleScalarResult();
            $category->setPosition($max + 1);
            $this->em->persist($category);
        }
        $this->em->flush();

        return [];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, string>
     */
    public function saveProject(Project $project, array $data): array
    {
        $errors = [];
        $title = $this->text($data, 'title');
        $this->length($errors, 'title', $title, 1, 150);
        $this->length($errors, 'shortDesc', $this->text($data, 'shortDesc'), 1, 255);
        if (trim((string) ($data['description'] ?? '')) === '') {
            $errors['description'] = 'Obligatoire.';
        }
        $type = $this->choice($errors, 'type', $data, self::PROJECT_TYPES);
        $urls = [];
        foreach (['imageUrl', 'projectUrl', 'githubUrl'] as $field) {
            $urls[$field] = $this->url($errors, $field, $this->text($data, $field), $field === 'imageUrl');
        }
        $position = $this->optionalInt($errors, 'position', $data, 0, 9999) ?? 0;
        $slug = $this->slug($errors, $data, $title, 170, $project->getId(), fn (string $s) => $this->projects->findOneBy(['slug' => $s]));

        if ($errors !== []) {
            return $errors;
        }

        $techStack = array_values(array_unique(array_filter(array_map(
            static fn (string $t): string => mb_substr(trim($t), 0, 40),
            explode(',', (string) ($data['techStack'] ?? '')),
        ))));

        $project
            ->setTitle($title)
            ->setSlug($slug)
            ->setType($type)
            ->setShortDesc($this->text($data, 'shortDesc'))
            ->setDescription((string) $data['description'])
            ->setTechStack($techStack)
            ->setImageUrl($urls['imageUrl'])
            ->setProjectUrl($urls['projectUrl'])
            ->setGithubUrl($urls['githubUrl'])
            ->setIsFeatured(!empty($data['isFeatured']))
            ->setIsVisible(!empty($data['isVisible']))
            ->setPosition($position);

        if ($project->getId() === null) {
            $project->setCreatedAt(new \DateTimeImmutable());
            $this->em->persist($project);
        }
        $this->em->flush();

        return [];
    }

    /**
     * Réordonne les éléments dans l'ordre reçu. Les ids inconnus ou d'un autre module sont ignorés.
     *
     * @param class-string<Document|DocumentCategory> $class
     * @param list<mixed>                              $ids
     */
    public function reorder(string $class, array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        $entities = $this->em->getRepository($class)->findBy(['id' => $ids]);
        $byId = [];
        foreach ($entities as $entity) {
            $byId[$entity->getId()] = $entity;
        }

        // Articles : tous doivent appartenir au même module, sinon l'ordre n'a pas de sens.
        if ($class === Document::class && count(array_unique(array_map(static fn (Document $d): ?int => $d->getCategory()?->getId(), $entities))) > 1) {
            throw new \InvalidArgumentException('Les articles à réordonner doivent appartenir au même module.');
        }

        $position = 0;
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $byId[$id]->setPosition(++$position);
            }
        }
        $this->em->flush();

        return $position;
    }

    /** @param array<string, mixed> $data */
    private function text(array $data, string $field): string
    {
        return trim((string) ($data[$field] ?? ''));
    }

    /** @param array<string, string> $errors */
    private function length(array &$errors, string $field, string $value, int $min, int $max): void
    {
        $length = mb_strlen($value);
        if ($min > 0 && $length === 0) {
            $errors[$field] = 'Obligatoire.';
        } elseif ($length > $max) {
            $errors[$field] = sprintf('%d caractères maximum (actuellement %d).', $max, $length);
        }
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed>  $data
     * @param array<string, string> $choices
     */
    private function choice(array &$errors, string $field, array $data, array $choices): string
    {
        $value = (string) ($data[$field] ?? '');
        if (!array_key_exists($value, $choices)) {
            $errors[$field] = 'Valeur non autorisée.';
        }

        return $value;
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed>  $data
     */
    private function optionalInt(array &$errors, string $field, array $data, int $min, int $max): ?int
    {
        $raw = trim((string) ($data[$field] ?? ''));
        if ($raw === '') {
            return null;
        }
        if (!preg_match('/^\d+$/', $raw) || (int) $raw < $min || (int) $raw > $max) {
            $errors[$field] = sprintf('Nombre entre %d et %d.', $min, $max);

            return null;
        }

        return (int) $raw;
    }

    /**
     * Seules les URL http(s) sont acceptées : une URL « javascript: » s'exécuterait au clic sur le site public.
     * Pour une image, un chemin local (« /images/... ») est aussi accepté.
     *
     * @param array<string, string> $errors
     */
    private function url(array &$errors, string $field, string $value, bool $allowLocalPath = false): ?string
    {
        if ($value === '') {
            return null;
        }
        if ($allowLocalPath && preg_match('#^/(?!/)[A-Za-z0-9._~/%-]+$#', $value) === 1 && mb_strlen($value) <= 255) {
            return $value;
        }
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true) || filter_var($value, FILTER_VALIDATE_URL) === false || mb_strlen($value) > 255) {
            $errors[$field] = 'Adresse http(s) complète attendue (255 caractères maximum).';

            return null;
        }

        return $value;
    }

    /**
     * Slug saisi (normalisé) ou dérivé du titre. Création : suffixe -2, -3… si déjà pris.
     * Modification d'un slug existant : erreur s'il est déjà utilisé ailleurs (on ne renomme pas en silence).
     *
     * @param array<string, string>      $errors
     * @param array<string, mixed>       $data
     * @param callable(string): ?object  $find
     */
    private function slug(array &$errors, array $data, string $source, int $max, ?int $currentId, callable $find): string
    {
        $typed = $this->text($data, 'slug');
        $base = mb_substr($this->slugger->slug($typed !== '' ? $typed : $source)->lower()->toString(), 0, $max - 4);
        if ($base === '') {
            $errors['slug'] ??= 'Impossible de construire une adresse à partir de ce texte.';

            return '';
        }

        $isTaken = static function (string $slug) use ($find, $currentId): bool {
            $owner = $find($slug);

            return $owner !== null && $owner->getId() !== $currentId;
        };

        if ($typed !== '' && $currentId !== null) {
            if ($isTaken($base)) {
                $errors['slug'] = 'Cette adresse est déjà utilisée.';
            }

            return $base;
        }

        $slug = $base;
        for ($i = 2; $isTaken($slug); ++$i) {
            $slug = $base . '-' . $i;
        }

        return $slug;
    }
}
