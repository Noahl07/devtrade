<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PageView;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PageView>
 */
class PageViewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PageView::class);
    }

    /** Supprime les vues plus anciennes que la date donnée ; renvoie le nombre de lignes supprimées. */
    public function purgeBefore(\DateTimeImmutable $limit): int
    {
        return (int) $this->createQueryBuilder('v')
            ->delete()
            ->where('v.viewedAt < :limit')
            ->setParameter('limit', $limit)
            ->getQuery()
            ->execute();
    }

    /**
     * Vues et visiteurs (uniques par jour) pour chaque jour depuis $since.
     *
     * @return array<string, array{views: int, visitors: int}> clé : date AAAA-MM-JJ
     */
    public function dailyTotals(\DateTimeImmutable $since): array
    {
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT TO_CHAR(viewed_at, \'YYYY-MM-DD\') AS day, COUNT(*) AS views, COUNT(DISTINCT visitor) AS visitors
             FROM page_view WHERE viewed_at >= :since GROUP BY day ORDER BY day',
            ['since' => $since->format('Y-m-d H:i:s')],
        )->fetchAllAssociative();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['day']] = ['views' => (int) $row['views'], 'visitors' => (int) $row['visitors']];
        }

        return $out;
    }

    /**
     * Classement d'un champ (path, referrer, device, browser, language) depuis $since.
     *
     * @return list<array{label: ?string, views: int, visitors: int}>
     */
    public function topBy(string $field, \DateTimeImmutable $since, int $limit = 10): array
    {
        if (!in_array($field, ['path', 'referrer', 'device', 'browser', 'language'], true)) {
            throw new \InvalidArgumentException('Champ non autorisé.');
        }

        // Le nom de champ vient de la liste blanche ci-dessus ; les valeurs passent en paramètres.
        $rows = $this->createQueryBuilder('v')
            ->select("v.$field AS label", 'COUNT(v.id) AS views', 'COUNT(DISTINCT v.visitor) AS visitors')
            ->where('v.viewedAt >= :since')
            ->setParameter('since', $since)
            ->groupBy("v.$field")
            ->orderBy('views', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $r): array => [
            'label' => $r['label'] !== null ? (string) $r['label'] : null,
            'views' => (int) $r['views'],
            'visitors' => (int) $r['visitors'],
        ], $rows);
    }
}
