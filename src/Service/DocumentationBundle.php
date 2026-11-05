<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Document;
use App\Entity\DocumentCategory;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Lot d'import de la documentation (fichier .json.gz produit par « app:documentation:files bundle »).
 *
 * decode() → valide le format ; plan() → compare avec la base ; apply() → crée et met à jour en une transaction.
 * Rien n'est jamais supprimé : les articles présents en base mais absents du lot sont seulement signalés.
 * Le contenu HTML n'est pas nettoyé ici : il l'est à l'affichage par |sanitize_html, comme tout article.
 */
final class DocumentationBundle
{
    public const FORMAT = 'devtrade-documentation';
    public const VERSION = 1;
    public const MAX_COMPRESSED_BYTES = 8 * 1024 * 1024;
    private const MAX_DECODED_BYTES = 40 * 1024 * 1024;
    private const SLUG = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';
    private const ACCESS = ['free', 'premium'];
    private const COMPARED = ['title', 'excerpt', 'readingTime', 'accessLevel', 'position', 'module', 'content'];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** Fichier compressé (ou JSON brut) → lot validé. */
    public function decode(string $raw): array
    {
        if (str_starts_with($raw, "\x1f\x8b")) {
            $raw = @gzdecode($raw, self::MAX_DECODED_BYTES);
            if ($raw === false) {
                throw new \InvalidArgumentException('Archive illisible ou trop volumineuse une fois décompressée.');
            }
        }
        try {
            $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('Le fichier n\'est pas un lot de documentation valide (JSON illisible).');
        }

        return $this->validate(is_array($data) ? $data : []);
    }

    public function encode(array $bundle): string
    {
        return (string) gzencode(json_encode($bundle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 9);
    }

    /**
     * @return array{modules: array<string, array<string, mixed>>, articles: array<string, array<string, mixed>>}
     */
    public function validate(array $data): array
    {
        if (($data['format'] ?? null) !== self::FORMAT || ($data['version'] ?? null) !== self::VERSION) {
            throw new \InvalidArgumentException('Ce fichier n\'est pas un lot de documentation devtrade (format ou version inattendu).');
        }
        if (!is_array($data['modules'] ?? null) || !is_array($data['articles'] ?? null) || count($data['articles']) > 2000) {
            throw new \InvalidArgumentException('Lot incomplet : modules ou articles manquants.');
        }

        $modules = [];
        foreach ($data['modules'] as $i => $m) {
            $where = sprintf('module n° %d', $i + 1);
            $slug = $this->slug($m['slug'] ?? null, 120, $where);
            $modules[$slug] = [
                'slug' => $slug,
                'name' => $this->text($m['name'] ?? null, 1, 100, "$where : nom"),
                'description' => $this->optionalText($m['description'] ?? null, 5000, "$where : description"),
                'icon' => $this->optionalText($m['icon'] ?? null, 50, "$where : icône"),
                'accessLevel' => $this->access($m['accessLevel'] ?? null, "$where : accès", true),
                'position' => $this->integer($m['position'] ?? null, 0, 1000, "$where : position"),
            ];
        }

        $articles = [];
        foreach ($data['articles'] as $i => $a) {
            $where = sprintf('article n° %d', $i + 1);
            $slug = $this->slug($a['slug'] ?? null, 220, $where);
            if (isset($articles[$slug])) {
                throw new \InvalidArgumentException(sprintf('Article « %s » présent deux fois dans le lot.', $slug));
            }
            $module = (string) ($a['module'] ?? '');
            if (!isset($modules[$module])) {
                throw new \InvalidArgumentException(sprintf('%s (%s) : module « %s » inconnu du lot.', $where, $slug, $module));
            }
            $articles[$slug] = [
                'slug' => $slug,
                'title' => $this->text($a['title'] ?? null, 1, 200, "$slug : titre"),
                'excerpt' => $this->optionalText($a['excerpt'] ?? null, 400, "$slug : résumé"),
                'readingTime' => ($a['readingTime'] ?? null) === null ? null : $this->integer($a['readingTime'], 1, 300, "$slug : temps de lecture"),
                'accessLevel' => $this->access($a['accessLevel'] ?? null, "$slug : accès"),
                'position' => $this->integer($a['position'] ?? null, 0, 10000, "$slug : position"),
                'module' => $module,
                'content' => $this->text($a['content'] ?? null, 1, 1_000_000, "$slug : contenu"),
                'published' => (bool) ($a['published'] ?? true),
            ];
        }

        return ['modules' => $modules, 'articles' => $articles];
    }

    /**
     * Ce que l'import changerait.
     *
     * @return array{modulesNew: list<array<string, mixed>>, modulesMoved: list<array{slug: string, name: string, from: int, to: int}>,
     *               articlesNew: list<array<string, mixed>>, articlesUpdated: list<array{slug: string, title: string, fields: array<string, array{0: mixed, 1: mixed}>}>,
     *               unchanged: int, onlyInDatabase: list<array{slug: string, title: string}>}
     */
    public function plan(array $bundle): array
    {
        $categories = $this->categories();
        $documents = $this->documents();

        $plan = ['modulesNew' => [], 'modulesMoved' => [], 'articlesNew' => [], 'articlesUpdated' => [], 'unchanged' => 0, 'onlyInDatabase' => []];
        foreach ($bundle['modules'] as $slug => $module) {
            if (!isset($categories[$slug])) {
                $plan['modulesNew'][] = $module;
            } elseif ($categories[$slug]->getPosition() !== $module['position']) {
                $plan['modulesMoved'][] = ['slug' => $slug, 'name' => $categories[$slug]->getName(), 'from' => $categories[$slug]->getPosition(), 'to' => $module['position']];
            }
        }
        foreach ($bundle['articles'] as $slug => $article) {
            $document = $documents[$slug] ?? null;
            if ($document === null) {
                $plan['articlesNew'][] = $article;
                continue;
            }
            $current = [
                'title' => $document->getTitle(),
                'excerpt' => $document->getExcerpt(),
                'readingTime' => $document->getReadingTime(),
                'accessLevel' => $document->getAccessLevel(),
                'position' => $document->getPosition(),
                'module' => $document->getCategory()?->getSlug(),
                'content' => self::normalize((string) $document->getContent()),
            ];
            $fields = [];
            foreach (self::COMPARED as $field) {
                $new = $field === 'content' ? self::normalize($article['content']) : $article[$field];
                if ((string) $current[$field] !== (string) $new) {
                    $fields[$field] = [$current[$field], $new];
                }
            }
            if ($fields === []) {
                ++$plan['unchanged'];
            } else {
                $plan['articlesUpdated'][] = ['slug' => $slug, 'title' => $article['title'], 'fields' => $fields];
            }
        }
        foreach ($documents as $slug => $document) {
            if (!isset($bundle['articles'][$slug])) {
                $plan['onlyInDatabase'][] = ['slug' => $slug, 'title' => (string) $document->getTitle()];
            }
        }

        return $plan;
    }

    /** Applique le lot en une transaction. Retourne le plan exécuté. */
    public function apply(array $bundle, ?User $author = null): array
    {
        return $this->em->wrapInTransaction(function () use ($bundle, $author): array {
            $plan = $this->plan($bundle);
            $existing = $this->categories();

            foreach ($plan['modulesNew'] as $m) {
                $this->em->persist((new DocumentCategory())->setSlug($m['slug'])->setName($m['name'])->setDescription($m['description'])
                    ->setIcon($m['icon'])->setAccessLevel($m['accessLevel'] === 'public' ? 'free' : $m['accessLevel'])->setPosition($m['position']));
            }
            foreach ($plan['modulesMoved'] as $m) {
                $existing[$m['slug']]->setPosition($m['to']);
            }
            $this->em->flush();

            $categories = $this->categories();
            $now = new \DateTimeImmutable();
            foreach ($plan['articlesNew'] as $a) {
                $document = (new Document())->setSlug($a['slug'])->setCreatedAt($now)->setAuthor($author)
                    ->setIsPublished($a['published'])->setPublishedAt($a['published'] ? \DateTime::createFromImmutable($now) : null);
                $this->hydrate($document, $a, $categories);
                $this->em->persist($document);
            }
            $documents = $this->documents();
            foreach ($plan['articlesUpdated'] as $u) {
                $document = $documents[$u['slug']];
                $this->hydrate($document, $bundle['articles'][$u['slug']], $categories);
                $document->setUpdatedAt(\DateTime::createFromImmutable($now));
            }
            $this->em->flush();

            return $plan;
        });
    }

    public static function normalize(string $html): string
    {
        return rtrim(str_replace("\r\n", "\n", $html));
    }

    /** @return array<string, DocumentCategory> */
    private function categories(): array
    {
        $map = [];
        foreach ($this->em->createQueryBuilder()->select('c')->from(DocumentCategory::class, 'c')->getQuery()->getResult() as $category) {
            $map[(string) $category->getSlug()] = $category;
        }

        return $map;
    }

    /** @return array<string, Document> */
    private function documents(): array
    {
        $map = [];
        foreach ($this->em->createQueryBuilder()->select('d', 'c')->from(Document::class, 'd')->leftJoin('d.category', 'c')->getQuery()->getResult() as $document) {
            $map[(string) $document->getSlug()] = $document;
        }

        return $map;
    }

    /** @param array<string, DocumentCategory> $categories */
    private function hydrate(Document $document, array $a, array $categories): void
    {
        $document->setTitle($a['title'])->setExcerpt($a['excerpt'])->setReadingTime($a['readingTime'])->setAccessLevel($a['accessLevel'])
            ->setPosition($a['position'])->setCategory($categories[$a['module']])->setContent(self::normalize($a['content']));
    }

    private function slug(mixed $value, int $max, string $where): string
    {
        if (!is_string($value) || strlen($value) > $max || !preg_match(self::SLUG, $value)) {
            throw new \InvalidArgumentException(sprintf('%s : identifiant (slug) invalide.', $where));
        }

        return $value;
    }

    private function text(mixed $value, int $min, int $max, string $where): string
    {
        if (!is_string($value) || mb_strlen(trim($value)) < $min || mb_strlen($value) > $max) {
            throw new \InvalidArgumentException(sprintf('%s : texte manquant ou trop long (%d caractères maximum).', $where, $max));
        }

        return $value;
    }

    private function optionalText(mixed $value, int $max, string $where): ?string
    {
        return $value === null || $value === '' ? null : $this->text($value, 0, $max, $where);
    }

    private function integer(mixed $value, int $min, int $max, string $where): int
    {
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new \InvalidArgumentException(sprintf('%s : nombre attendu entre %d et %d.', $where, $min, $max));
        }

        return $value;
    }

    private function access(mixed $value, string $where, bool $legacyAllowed = false): string
    {
        // Un module existant peut porter un ancien niveau (« public ») : il est conservé tel quel, jamais créé ainsi.
        if (is_string($value) && (in_array($value, self::ACCESS, true) || ($legacyAllowed && $value === 'public'))) {
            return $value;
        }

        throw new \InvalidArgumentException(sprintf('%s : niveau d\'accès « %s » non géré.', $where, is_scalar($value) ? (string) $value : '?'));
    }
}
