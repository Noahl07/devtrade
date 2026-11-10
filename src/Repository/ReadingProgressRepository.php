<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Document;
use App\Entity\ReadingProgress;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ReadingProgress>
 */
class ReadingProgressRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReadingProgress::class);
    }

    public function findOneFor(User $user, Document $document): ?ReadingProgress
    {
        return $this->findOneBy(['user' => $user, 'document' => $document]);
    }

    /**
     * Progression du membre, indexée par identifiant d'article.
     *
     * @return array<int, ReadingProgress>
     */
    public function mapFor(User $user): array
    {
        $map = [];
        foreach ($this->findBy(['user' => $user]) as $p) {
            $map[(int) $p->getDocument()->getId()] = $p;
        }

        return $map;
    }

    /** Dernier article ouvert et pas encore terminé (publié). */
    public function findResumable(User $user): ?ReadingProgress
    {
        return $this->createQueryBuilder('p')
            ->join('p.document', 'd')
            ->where('p.user = :user')
            ->andWhere('p.completedAt IS NULL')
            ->andWhere('d.isPublished = true')
            ->setParameter('user', $user)
            ->orderBy('p.openedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Statistiques de lecture par article (pour l'administration) : ouvertures, lectures terminées, score moyen au quiz.
     *
     * @return list<array{id: int, title: string, opened: int, completed: int, quizAvg: float|null}>
     */
    public function statsByDocument(int $limit = 30): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('d.id AS id', 'd.title AS title', 'COUNT(p.id) AS opened', 'COUNT(p.completedAt) AS completed',
                'AVG(CASE WHEN p.quizTotal > 0 THEN p.quizScore * 1.0 / p.quizTotal ELSE :nul END) AS quizAvg')
            ->join('p.document', 'd')
            ->groupBy('d.id', 'd.title')
            ->orderBy('opened', 'DESC')
            ->setParameter('nul', null)
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'title' => (string) $r['title'],
            'opened' => (int) $r['opened'],
            'completed' => (int) $r['completed'],
            'quizAvg' => $r['quizAvg'] !== null ? (float) $r['quizAvg'] : null,
        ], $rows);
    }
}
