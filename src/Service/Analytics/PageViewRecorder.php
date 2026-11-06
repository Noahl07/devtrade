<?php

declare(strict_types=1);

namespace App\Service\Analytics;

use App\Entity\PageView;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mesure d'audience sans cookie : enregistre une page vue si la requête s'y prête.
 *
 * Données conservées : date, chemin, empreinte quotidienne du visiteur, domaine de provenance,
 * type d'appareil, navigateur, langue. L'adresse IP n'est jamais stockée : l'empreinte est un HMAC
 * (IP + navigateur) avec un sel qui change chaque jour, ce qui empêche de suivre un visiteur d'un jour à l'autre.
 */
final class PageViewRecorder
{
    private const EXCLUDED_PREFIXES = ['/admin', '/_', '/build', '/assets', '/bundles', '/favicon', '/robots.txt', '/sitemap'];
    private const BOTS = '/bot|crawl|spider|slurp|facebookexternalhit|embedly|preview|monitor|uptime|curl|wget|python|httpclient|headless|lighthouse|pingdom/i';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
    ) {
    }

    public function shouldRecord(Request $request, Response $response): bool
    {
        if (!$request->isMethod('GET') || $response->getStatusCode() !== 200 || $request->isXmlHttpRequest()) {
            return false;
        }
        if (!str_contains((string) $response->headers->get('Content-Type', ''), 'text/html')) {
            return false;
        }
        $path = $request->getPathInfo();
        foreach (self::EXCLUDED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return false;
            }
        }
        // Préchargements du navigateur, robots, refus de suivi (Do Not Track, Global Privacy Control).
        $purpose = strtolower((string) ($request->headers->get('Sec-Purpose') ?? $request->headers->get('Purpose') ?? ''));
        if (str_contains($purpose, 'prefetch') || str_contains($purpose, 'prerender')) {
            return false;
        }
        $ua = (string) $request->headers->get('User-Agent', '');
        if ($ua === '' || preg_match(self::BOTS, $ua) === 1) {
            return false;
        }

        return $request->headers->get('DNT') !== '1' && $request->headers->get('Sec-GPC') !== '1';
    }

    public function record(Request $request, ?\DateTimeImmutable $now = null): void
    {
        $now ??= new \DateTimeImmutable();
        $ua = (string) $request->headers->get('User-Agent', '');

        $view = new PageView(
            viewedAt: $now,
            path: mb_substr($request->getPathInfo(), 0, 255),
            visitor: $this->visitor((string) $request->getClientIp(), $ua, $now),
            referrer: $this->referrer($request),
            device: self::device($ua),
            browser: self::browser($ua),
            language: self::language((string) $request->headers->get('Accept-Language', '')),
        );

        try {
            $this->em->persist($view);
            $this->em->flush();
        } catch (\Throwable $e) {
            // La mesure d'audience ne doit jamais casser une page.
            $this->logger->warning('Page vue non enregistrée', ['exception' => $e::class]);
        }
    }

    private function visitor(string $ip, string $ua, \DateTimeImmutable $now): string
    {
        $salt = hash_hmac('sha256', 'analytics-' . $now->format('Y-m-d'), $this->secret);

        return substr(hash_hmac('sha256', $ip . '|' . $ua, $salt), 0, 16);
    }

    private function referrer(Request $request): ?string
    {
        $host = parse_url((string) $request->headers->get('Referer', ''), PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }
        $host = strtolower(preg_replace('/^www\./', '', $host) ?? $host);
        if ($host === strtolower(preg_replace('/^www\./', '', $request->getHost()) ?? '')) {
            return null; // navigation interne
        }
        if (preg_match('/(^|\.)google\./', $host) === 1) {
            return 'google';
        }
        if (preg_match('/(^|\.)bing\.com$/', $host) === 1) {
            return 'bing';
        }

        return mb_substr($host, 0, 120);
    }

    public static function device(string $ua): string
    {
        if (preg_match('/iPad|Tablet|Android(?!.*Mobile)/i', $ua) === 1) {
            return 'tablette';
        }

        return preg_match('/Mobi|iPhone|Android/i', $ua) === 1 ? 'mobile' : 'ordinateur';
    }

    public static function browser(string $ua): string
    {
        return match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Autre',
        };
    }

    public static function language(string $acceptLanguage): ?string
    {
        if (preg_match('/^\s*([a-z]{2,3})/i', $acceptLanguage, $m) !== 1) {
            return null;
        }

        return strtolower($m[1]);
    }
}
