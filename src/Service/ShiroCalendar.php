<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Prochaines dates de trade de Shiro (données : config/packages/shiro.yaml, paramètre shiro.calendar).
 */
final class ShiroCalendar
{
    /** @param list<array{date: string, type: string}> $calendar */
    public function __construct(
        #[Autowire('%shiro.calendar%')] private readonly array $calendar,
    ) {
    }

    /**
     * Dates à venir, regroupées par mois (libellé français, ex. « novembre 2026 »).
     *
     * @return array<string, list<array{date: \DateTimeImmutable, type: string}>>
     */
    public function upcomingByMonth(?\DateTimeImmutable $today = null): array
    {
        $today ??= new \DateTimeImmutable('today');
        $formatter = new \IntlDateFormatter('fr_FR', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, null, null, 'MMMM y');

        $upcoming = [];
        foreach ($this->calendar as $entry) {
            $date = new \DateTimeImmutable($entry['date']);
            if ($date >= $today) {
                $upcoming[(string) $formatter->format($date)][] = ['date' => $date, 'type' => $entry['type']];
            }
        }

        return $upcoming;
    }
}
