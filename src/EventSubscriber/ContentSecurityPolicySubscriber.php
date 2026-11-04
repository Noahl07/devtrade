<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Content-Security-Policy des pages HTML.
 *
 * Tout le site, admin compris : aucun script en ligne, aucune ressource tierce (polices et scripts
 * auto-hébergés), donc « script-src 'self' » suffit. Les blocs JSON-LD ne sont pas exécutés et ne sont pas concernés.
 * « style-src 'unsafe-inline' » reste nécessaire pour les attributs style (articles, styleguide).
 *
 * En dev, le profiler Symfony ajoute lui-même des nonces à cette politique pour sa barre d'outils.
 */
final class ContentSecurityPolicySubscriber implements EventSubscriberInterface
{
    private const POLICY = [
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
        $policy = self::POLICY;

        $directives = array_map(static fn (string $name, string $value): string => "$name $value", array_keys($policy), $policy);
        if ($request->isSecure()) {
            $directives[] = 'upgrade-insecure-requests';
        }

        $response->headers->set('Content-Security-Policy', implode('; ', $directives));
    }
}
