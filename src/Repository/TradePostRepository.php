<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TradePost;
use App\Entity\TradeReaction;
use App\Entity\TradeReport;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TradePost>
 */
class TradePostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TradePost::class);
    }

    /**
     * Publications validées, filtrées par thème ; tri « recents » ou « populaires » (réactions des 30 derniers jours).
     *
     * @return array{items: list<TradePost>, total: int}
     */
    public function findPublished(?string $theme, string $tri, int $page, int $parPage = 12): array
    {
        $qb = $this->createQueryBuilder('p')
            ->where('p.status = :publie')
            ->setParameter('publie', TradePost::PUBLIE);
        if ($theme !== null) {
            $qb->andWhere('p.theme = :theme')->setParameter('theme', $theme);
        }
        $total = (int) (clone $qb)->select('COUNT(p.id)')->getQuery()->getSingleScalarResult();

        if ($tri === 'populaires') {
            $qb->leftJoin('p.reactions', 'r', 'WITH', 'r.createdAt >= :depuis')
                ->setParameter('depuis', new \DateTimeImmutable('-30 days'))
                ->addSelect('COUNT(r.id) AS HIDDEN nb')
                ->groupBy('p.id')
                ->orderBy('nb', 'DESC')
                ->addOrderBy('p.createdAt', 'DESC');
        } else {
            $qb->orderBy('p.createdAt', 'DESC');
        }
        $items = $qb->setFirstResult(($page - 1) * $parPage)->setMaxResults($parPage)->getQuery()->getResult();

        return ['items' => $items, 'total' => $total];
    }

    /** @return list<TradePost> */
    public function findByAuthor(User $user): array
    {
        return $this->findBy(['author' => $user], ['createdAt' => 'DESC']);
    }

    /** @return list<TradePost> */
    public function findPending(): array
    {
        return $this->findBy(['status' => TradePost::EN_ATTENTE], ['createdAt' => 'ASC']);
    }

    public function countPending(): int
    {
        return $this->count(['status' => TradePost::EN_ATTENTE]);
    }

    /**
     * Dernières publications validées d'un thème lié à un module de la documentation.
     *
     * @return list<TradePost>
     */
    public function findForCategory(string $categorySlug, int $limit = 3): array
    {
        $themes = array_keys(array_filter(TradePost::THEMES, static fn (array $t): bool => in_array($categorySlug, $t[1], true)));
        if ($themes === []) {
            return [];
        }

        return $this->createQueryBuilder('p')
            ->where('p.status = :publie')
            ->andWhere('p.theme IN (:themes)')
            ->setParameter('publie', TradePost::PUBLIE)
            ->setParameter('themes', $themes)
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Nombre de réactions par publication et par émoji.
     *
     * @param list<TradePost> $posts
     *
     * @return array<int, array<string, int>>
     */
    public function reactionCounts(array $posts): array
    {
        if ($posts === []) {
            return [];
        }
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(r.post) AS post', 'r.emoji AS emoji', 'COUNT(r.id) AS n')
            ->from(TradeReaction::class, 'r')
            ->where('r.post IN (:posts)')
            ->setParameter('posts', $posts)
            ->groupBy('r.post', 'r.emoji')
            ->getQuery()
            ->getArrayResult();
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['post']][$row['emoji']] = (int) $row['n'];
        }

        return $out;
    }

    /**
     * Émojis déjà choisis par ce membre, par publication.
     *
     * @param list<TradePost> $posts
     *
     * @return array<int, array<string, true>>
     */
    public function reactionsOf(User $user, array $posts): array
    {
        if ($posts === []) {
            return [];
        }
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(r.post) AS post', 'r.emoji AS emoji')
            ->from(TradeReaction::class, 'r')
            ->where('r.post IN (:posts)')
            ->andWhere('r.user = :user')
            ->setParameter('posts', $posts)
            ->setParameter('user', $user)
            ->getQuery()
            ->getArrayResult();
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['post']][$row['emoji']] = true;
        }

        return $out;
    }

    /**
     * Publications signalées, avec le nombre de signalements et les motifs.
     *
     * @return list<array{post: TradePost, count: int, reasons: list<string>}>
     */
    public function findReported(): array
    {
        $reports = $this->getEntityManager()->getRepository(TradeReport::class)->findBy([], ['createdAt' => 'DESC']);
        $out = [];
        foreach ($reports as $report) {
            $id = (int) $report->getPost()->getId();
            $out[$id] ??= ['post' => $report->getPost(), 'count' => 0, 'reasons' => []];
            ++$out[$id]['count'];
            $out[$id]['reasons'][] = $report->getReasonLabel();
        }

        return array_values($out);
    }
}
