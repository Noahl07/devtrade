<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Document;
use App\Entity\DocumentCategory;
use App\Entity\ReadingProgress;
use App\Entity\User;
use App\Repository\ReadingProgressRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Progression de lecture des membres : article ouvert, terminé, meilleur score au quiz.
 */
final class ReadingProgressTracker
{
    public const ACTIONS = ['lu', 'non-lu', 'quiz'];

    public function __construct(
        private readonly ReadingProgressRepository $progress,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Enregistre l'ouverture d'un article (date mise à jour à chaque visite). */
    public function opened(User $user, Document $document): ReadingProgress
    {
        $p = $this->progress->findOneFor($user, $document);
        if ($p === null) {
            $p = new ReadingProgress($user, $document);
            $this->em->persist($p);
        } else {
            $p->setOpenedAt(new \DateTimeImmutable());
        }
        $this->em->flush();

        return $p;
    }

    /**
     * Applique une action venant de l'article : « lu », « non-lu » ou « quiz » (score / total).
     */
    public function apply(User $user, Document $document, string $action, int $score = 0, int $total = 0): ReadingProgress
    {
        $p = $this->progress->findOneFor($user, $document) ?? new ReadingProgress($user, $document);
        $this->em->persist($p);

        match ($action) {
            'lu' => $p->isCompleted() ? null : $p->setCompletedAt(new \DateTimeImmutable()),
            'non-lu' => $p->setCompletedAt(null),
            'quiz' => $total > 0 && $total <= 50 && $score >= 0 && $score <= $total ? $p->recordQuiz($score, $total) : null,
            default => throw new \InvalidArgumentException('Action inconnue.'),
        };
        $this->em->flush();

        return $p;
    }

    /**
     * Avancement par module : articles accessibles, articles terminés.
     *
     * @param list<DocumentCategory> $categories
     * @param array<int, ReadingProgress> $map
     *
     * @return array<string, array{done: int, total: int}>
     */
    public function byCategory(array $categories, array $map): array
    {
        $out = [];
        foreach ($categories as $category) {
            $total = 0;
            $done = 0;
            foreach ($category->getDocuments() as $doc) {
                if (!$doc->isPublished()) {
                    continue;
                }
                ++$total;
                if (isset($map[$doc->getId()]) && $map[$doc->getId()]->isCompleted()) {
                    ++$done;
                }
            }
            $out[(string) $category->getSlug()] = ['done' => $done, 'total' => $total];
        }

        return $out;
    }
}
