<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Content-Security-Policy des pages HTML.
 *
 * Site public : aucun script en ligne, aucune ressource tierce (polices et scripts auto-hébergés),
 * donc « script-src 'self' » suffit. Les blocs JSON-LD ne sont pas exécutés et ne sont pas concernés.
 * « style-src 'unsafe-inline' » reste nécessaire pour les attributs style (articles, styleguide).
 *
 * /admin : politique assouplie tant que l'admin garde des scripts en ligne, des attributs on*= et
 * Sortable (cdnjs) / Bootstrap Icons (jsdelivr). L'admin est réservé à ROLE_ADMIN.
 *
 * En dev, le profiler Symfony ajoute lui-même des nonces à cette politique pour sa barre d'outils.
 */
final class ContentSecurityPolicySubscriber implements EventSubscriberInterface
{
    private const PUBLIC_POLICY = [
        'default-src' => "'self'",
        'script-src' => "'self'",
        'style-src' => "'self' 'unsafe-inline'",
        'img-src' => "'self' data: https:", // images de projets et d'articles : URL externes possibles
        'font-src' => "'self'",
        'connect-src' => "'self'",
        'object-src' => "'none'",
        'base-uri' => "'self'",
        'form-action' => "'self'",
        'frame-ancestors' => "'self'",
    ];

    private const ADMIN_OVERRIDES = [
        'script-src' => "'self' 'unsafe-inline' https://cdnjs.cloudflare.com",
        'style-src' => "'self' 'unsafe-inline' https://cdn.jsdelivr.net",
        'font-src' => "'self' https://cdn.jsdelivr.net",
    ];

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onResponse', -10]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $response = $event->getResponse();
        if ($response->headers->has('Content-Security-Policy')
            || !str_contains((string) $response->headers->get('Content-Type', 'text/html'), 'text/html')) {
            return;
        }

        $request = $event->getRequest();
        $policy = self::PUBLIC_POLICY;
        if (str_starts_with($request->getPathInfo(), '/admin')) {
            $policy = array_merge($policy, self::ADMIN_OVERRIDES);
        }

        $directives = array_map(static fn (string $name, string $value): string => "$name $value", array_keys($policy), $policy);
        if ($request->isSecure()) {
            $directives[] = 'upgrade-insecure-requests';
        }

        $response->headers->set('Content-Security-Policy', implode('; ', $directives));
    }
}
