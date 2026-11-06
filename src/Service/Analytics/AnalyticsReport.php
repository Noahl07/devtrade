<?php

declare(strict_types=1);

namespace App\Service\Analytics;

use App\Repository\PageViewRepository;

/**
 * Prépare les données du tableau de bord des statistiques (admin).
 */
final class AnalyticsReport
{
    public const PERIODS = [7, 30, 90];
    /** Durée de conservation des pages vues (recommandation CNIL pour la mesure d'audience exemptée de consentement). */
    public const RETENTION = '-13 months';

    private const CHART_WIDTH = 640;
    private const CHART_HEIGHT = 200;

    public function __construct(private readonly PageViewRepository $views)
    {
    }

    public function purgeExpired(?\DateTimeImmutable $now = null): int
    {
        return $this->views->purgeBefore(($now ?? new \DateTimeImmutable())->modify(self::RETENTION));
    }

    /**
     * @return array<string, mixed>
     */
    public function build(int $days, ?\DateTimeImmutable $now = null): array
    {
        $days = in_array($days, self::PERIODS, true) ? $days : 30;
        $today = ($now ?? new \DateTimeImmutable())->setTime(0, 0);
        $since = $today->modify(sprintf('-%d days', $days - 1));

        $totals = $this->views->dailyTotals($since);
        $series = [];
        for ($d = $since; $d <= $today; $d = $d->modify('+1 day')) {
            $key = $d->format('Y-m-d');
            $series[] = ['date' => $d, 'views' => $totals[$key]['views'] ?? 0, 'visitors' => $totals[$key]['visitors'] ?? 0];
        }

        $views = array_sum(array_column($series, 'views'));
        $visitors = array_sum(array_column($series, 'visitors'));

        return [
            'days' => $days,
            'periods' => self::PERIODS,
            'since' => $since,
            'views' => $views,
            'visitors' => $visitors,
            'pages_per_visitor' => $visitors > 0 ? round($views / $visitors, 1) : 0.0,
            'chart' => $this->chart($series),
            'pages' => $this->views->topBy('path', $since, 15),
            'referrers' => $this->withShares($this->views->topBy('referrer', $since, 10)),
            'devices' => $this->withShares($this->views->topBy('device', $since, 5)),
            'browsers' => $this->withShares($this->views->topBy('browser', $since, 8)),
            'languages' => $this->withShares($this->views->topBy('language', $since, 8)),
        ];
    }

    /**
     * Coordonnées d'un histogramme (vues) et d'une courbe (visiteurs), calculées ici pour un SVG sans JavaScript.
     *
     * @param list<array{date: \DateTimeImmutable, views: int, visitors: int}> $series
     *
     * @return array<string, mixed>
     */
    private function chart(array $series): array
    {
        $n = count($series);
        $max = max(1, ...array_column($series, 'views'));
        $slot = self::CHART_WIDTH / max(1, $n);
        $bars = [];
        $points = [];
        foreach ($series as $i => $day) {
            $h = $day['views'] / $max * (self::CHART_HEIGHT - 10);
            $x = $i * $slot;
            $bars[] = [
                'x' => round($x + $slot * 0.15, 1),
                'y' => round(self::CHART_HEIGHT - $h, 1),
                'width' => round($slot * 0.7, 1),
                'height' => round($h, 1),
                'title' => sprintf('%s : %d vues, %d visiteurs', $day['date']->format('d/m'), $day['views'], $day['visitors']),
            ];
            $points[] = round($x + $slot / 2, 1) . ',' . round(self::CHART_HEIGHT - $day['visitors'] / $max * (self::CHART_HEIGHT - 10), 1);
        }

        return [
            'width' => self::CHART_WIDTH,
            'height' => self::CHART_HEIGHT,
            'bars' => $bars,
            'line' => implode(' ', $points),
            'max' => $max,
            'first' => $series[0]['date'] ?? null,
            'last' => $series[$n - 1]['date'] ?? null,
        ];
    }

    /**
     * @param list<array{label: ?string, views: int, visitors: int}> $rows
     *
     * @return list<array{label: ?string, views: int, visitors: int, share: float}>
     */
    private function withShares(array $rows): array
    {
        $total = max(1, array_sum(array_column($rows, 'views')));

        return array_map(static fn (array $r): array => $r + ['share' => round($r['views'] / $total * 100, 1)], $rows);
    }
}
