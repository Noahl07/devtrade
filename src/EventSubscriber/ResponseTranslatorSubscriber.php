<?php

namespace App\EventSubscriber;

use App\Service\TranslationCacheService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class ResponseTranslatorSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private TranslationCacheService $translator
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onKernelResponse', -10]];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        // Désactiver si variable d'env à 0 (utile pour debug prod)
        if ($_ENV['APP_LOCALE_TRANSLATION_ENABLED'] ?? '1' === '0') {
            return;
        }
        
        $request  = $event->getRequest();
        $response = $event->getResponse();
        $locale   = $request->getLocale();

        if ($locale === 'fr') {
            return;
        }

        $contentType = $response->headers->get('Content-Type', '');
        if (!str_contains($contentType, 'text/html')) {
            return;
        }

        $route = $request->attributes->get('_route', '');
        if (
            str_starts_with($route, 'app_admin') ||
            str_starts_with($route, '_wdt') ||
            str_starts_with($route, '_profiler') ||
            $request->isXmlHttpRequest()
        ) {
            return;
        }

        $html = $response->getContent();
        if (empty($html)) {
            return;
        }

        // Clé de cache basée sur la route + locale
        $cacheKey = sha1($route . serialize($request->query->all()));

        $translatedHtml = $this->translator->translateHtmlPage($html, $locale, $cacheKey);
        $response->setContent($translatedHtml);
    }
}