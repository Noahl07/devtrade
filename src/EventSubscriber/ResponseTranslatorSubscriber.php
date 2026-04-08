<?php

namespace App\EventSubscriber;

use App\Service\TranslationCacheService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Intercepte chaque réponse HTML et traduit le contenu
 * si la locale est différente de 'fr'.
 *
 * Stratégie :
 *  - Extrait uniquement le contenu de <main> pour ne pas traduire
 *    les URLs, les attributs HTML, les scripts, etc.
 *  - Le switcher FR|EN (nav/footer) est exclu via data-no-translate
 *  - Cache par URL + locale → l'API n'est appelée qu'une seule fois par page
 */
class ResponseTranslatorSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private TranslationCacheService $translator
    ) {}

    public static function getSubscribedEvents(): array
    {
        // Priorité négative = s'exécute après le rendu Twig
        return [KernelEvents::RESPONSE => ['onKernelResponse', -10]];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        $request  = $event->getRequest();
        $response = $event->getResponse();
        $locale   = $request->getLocale();

        // Rien à faire si FR ou si ce n'est pas du HTML
        if ($locale === 'fr') {
            return;
        }

        $contentType = $response->headers->get('Content-Type', '');
        if (!str_contains($contentType, 'text/html')) {
            return;
        }

        // Ignorer les routes admin, profiler, ajax
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

        // Clé de cache = route + params + locale
        // (pas sha1 du HTML pour éviter de recalculer à chaque fois)
        $cacheKey = 'page_' . $locale . '_' . sha1($route . serialize($request->query->all()));

        $translatedHtml = $this->translator->translateHtmlPage($html, $locale, $cacheKey);

        $response->setContent($translatedHtml);
    }
}