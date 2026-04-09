<?php

namespace App\Service;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Psr\Log\LoggerInterface;

/**
 * Traduction automatique via MyMemory API avec cache Symfony.
 *
 * Stratégie pour éviter le timeout :
 *  - Ne traduit QUE le contenu de <main> (pas nav/footer/scripts)
 *  - Regroupe tous les textes en un seul appel API par batch de 5
 *  - Cache par sha1(texte) → 0 appel API après la première traduction
 *  - Timeout PHP augmenté à 120s le temps du premier chargement
 */
class TranslationCacheService
{
    private const CACHE_TTL  = 60 * 60 * 24 * 30; // 30 jours
    private const CHUNK_SIZE = 400;                 // chars max par requête MyMemory

    public function __construct(
        private CacheInterface  $cache,
        private LoggerInterface $logger,
        private string          $provider          = 'mymemory',
        private string          $myMemoryEmail     = '',
        private string          $libreTranslateUrl = 'http://localhost:5000',
        private string          $libreTranslateKey = ''
    ) {}

    // ──────────────────────────────────────────────
    // API publique
    // ──────────────────────────────────────────────

    public function translate(string $text, string $targetLocale, string $sourceLocale = 'fr'): string
    {
        if ($targetLocale === $sourceLocale || empty(trim($text))) {
            return $text;
        }

        $cacheKey = 'trans_' . $targetLocale . '_' . sha1($text);

        try {
            return $this->cache->get($cacheKey, function (ItemInterface $item) use ($text, $targetLocale, $sourceLocale) {
                $item->expiresAfter(self::CACHE_TTL);
                return $this->callApi($text, $targetLocale, $sourceLocale) ?? $text;
            });
        } catch (\Throwable $e) {
            $this->logger->error('[Translation] ' . $e->getMessage());
            return $text;
        }
    }

    public function translateBatch(array $texts, string $targetLocale, string $sourceLocale = 'fr'): array
    {
        if ($targetLocale === $sourceLocale) {
            return $texts;
        }

        $results     = [];
        $toTranslate = [];

        foreach ($texts as $i => $text) {
            if (empty(trim($text))) {
                $results[$i] = $text;
                continue;
            }
            $cached = $this->getFromCache('trans_' . $targetLocale . '_' . sha1($text));
            if ($cached !== null) {
                $results[$i] = $cached;
            } else {
                $toTranslate[$i] = $text;
            }
        }

        foreach ($toTranslate as $i => $text) {
            $translated  = $this->callApi($text, $targetLocale, $sourceLocale) ?? $text;
            $results[$i] = $translated;
            $this->saveToCache('trans_' . $targetLocale . '_' . sha1($text), $translated);
        }

        ksort($results);
        return $results;
    }

    /**
     * Traduit une page HTML complète.
     * Extrait uniquement les nœuds texte du <main> pour :
     *  1. Réduire drastiquement le nombre de textes à traduire
     *  2. Ne pas toucher nav/footer/scripts/styles
     *  3. Éviter le timeout PHP
     */
    public function translateHtmlPage(string $html, string $targetLocale, string $cacheKey): string
    {
        if ($targetLocale === 'fr') {
            return $html;
        }

        $fullCacheKey = 'page_' . $targetLocale . '_' . $cacheKey;

        try {
            return $this->cache->get($fullCacheKey, function (ItemInterface $item) use ($html, $targetLocale) {
                // Augmenter le timeout PHP pour le premier chargement
                $previousLimit = ini_get('max_execution_time');
                set_time_limit(180);

                $item->expiresAfter(self::CACHE_TTL);
                $result = $this->doTranslateHtml($html, $targetLocale);

                set_time_limit((int)$previousLimit);
                return $result;
            });
        } catch (\Throwable $e) {
            $this->logger->error('[Translation HTML] ' . $e->getMessage());
            return $html;
        }
    }

    public function invalidate(string $text, string $targetLocale = 'en'): void
    {
        try {
            $this->cache->delete('trans_' . $targetLocale . '_' . sha1($text));
        } catch (\Throwable) {}
    }

    public function invalidatePage(string $cacheKey, string $targetLocale = 'en'): void
    {
        try {
            $this->cache->delete('page_' . $targetLocale . '_' . $cacheKey);
        } catch (\Throwable) {}
    }

    // ──────────────────────────────────────────────
    // Traduction HTML — extrait uniquement <main>
    // ──────────────────────────────────────────────

    private function doTranslateHtml(string $html, string $targetLocale): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        // Trouver le <main> uniquement → évite de traduire nav/footer/head
        $mainNodes = $dom->getElementsByTagName('main');
        if ($mainNodes->length === 0) {
            // Fallback : traduire le <body> si pas de <main>
            $mainNodes = $dom->getElementsByTagName('body');
        }

        if ($mainNodes->length === 0) {
            return $html;
        }

        $mainNode  = $mainNodes->item(0);
        $textNodes = [];
        $this->collectTextNodes($mainNode, $textNodes);

        if (empty($textNodes)) {
            return $html;
        }

        // Filtrer les textes vides ou trop courts
        $toTranslate = [];
        foreach ($textNodes as $i => $node) {
            $clean = trim($node->nodeValue);
            if (mb_strlen($clean) > 2) {
                $toTranslate[$i] = $clean;
            }
        }

        if (empty($toTranslate)) {
            return $html;
        }

        // Vérifier le cache pour chaque texte
        $needsApi = [];
        $cached   = [];
        foreach ($toTranslate as $i => $text) {
            $hit = $this->getFromCache('trans_' . $targetLocale . '_' . sha1($text));
            if ($hit !== null) {
                $cached[$i] = $hit;
            } else {
                $needsApi[$i] = $text;
            }
        }

        // Traduire par batch ceux qui ne sont pas en cache
        $translated = $cached;
        if (!empty($needsApi)) {
            // Regrouper en macro-blocs pour minimiser les appels API
            $apiResults = $this->translateInMacroBlocks($needsApi, $targetLocale);
            foreach ($apiResults as $i => $text) {
                $translated[$i] = $text;
                // Sauvegarder chaque texte en cache individuel
                $this->saveToCache('trans_' . $targetLocale . '_' . sha1($toTranslate[$i]), $text);
            }
        }

        // Réinjecter dans le DOM
        foreach ($translated as $i => $translatedText) {
            $original = $textNodes[$i]->nodeValue;
            $leading  = strlen($original) - strlen(ltrim($original));
            $trailing = strlen($original) - strlen(rtrim($original));
            $textNodes[$i]->nodeValue =
                substr($original, 0, $leading) .
                $translatedText .
                ($trailing > 0 ? substr($original, -$trailing) : '');
        }

        $result = $dom->saveHTML();
        $result = str_replace('<?xml encoding="UTF-8">', '', $result);

        return $result ?: $html;
    }

    /**
     * Regroupe les textes courts en macro-blocs séparés par \n
     * pour minimiser le nombre d'appels API.
     * Ex: 50 textes courts → 5-10 appels au lieu de 50.
     */
    private function translateInMacroBlocks(array $texts, string $targetLocale): array
    {
        $SEPARATOR = ' §§§ '; // séparateur reconnaissable
        $blocks    = [];      // [startIndex => [indices]]
        $current   = [];
        $currentLen = 0;

        foreach ($texts as $i => $text) {
            $len = strlen($text) + strlen($SEPARATOR);
            if ($currentLen + $len > self::CHUNK_SIZE && !empty($current)) {
                $blocks[] = $current;
                $current  = [];
                $currentLen = 0;
            }
            $current[$i] = $text;
            $currentLen += $len;
        }
        if (!empty($current)) {
            $blocks[] = $current;
        }

        $results = [];

        foreach ($blocks as $block) {
            $indices   = array_keys($block);
            $combined  = implode($SEPARATOR, array_values($block));

            $translatedCombined = $this->callApi($combined, $targetLocale, 'fr') ?? $combined;

            // Re-splitter sur le séparateur (MyMemory peut légèrement altérer l'espacement)
            $parts = preg_split('/\s*§§§\s*/u', $translatedCombined);

            foreach ($indices as $j => $i) {
                $results[$i] = $parts[$j] ?? $texts[$i];
            }

            usleep(200000); // 200ms entre blocs pour éviter le rate limit
        }

        return $results;
    }

    private function collectTextNodes(\DOMNode $node, array &$textNodes): void
    {
        $skipTags = ['script', 'style', 'code', 'pre', 'textarea', 'meta', 'link', 'noscript'];

        if ($node instanceof \DOMElement) {
            if (in_array(strtolower($node->nodeName), $skipTags)) {
                return;
            }
            if ($node->hasAttribute('data-no-translate')) {
                return;
            }
        }

        if ($node instanceof \DOMText) {
            $textNodes[] = $node;
            return;
        }

        foreach ($node->childNodes as $child) {
            $this->collectTextNodes($child, $textNodes);
        }
    }

    // ──────────────────────────────────────────────
    // Providers
    // ──────────────────────────────────────────────

    private function callApi(string $text, string $to, string $from): ?string
    {
        return match ($this->provider) {
            'libretranslate' => $this->callLibreTranslate($text, $to, $from),
            default          => $this->callMyMemory($text, $to, $from),
        };
    }

    private function callMyMemory(string $text, string $to, string $from): ?string
    {
        // Fragmenter si trop long
        if (strlen($text) > self::CHUNK_SIZE) {
            $chunks     = $this->splitText($text, self::CHUNK_SIZE);
            $translated = [];
            foreach ($chunks as $chunk) {
                $result       = $this->myMemoryRequest($chunk, $to, $from);
                $translated[] = $result ?? $chunk;
                usleep(150000);
            }
            return implode(' ', $translated);
        }

        return $this->myMemoryRequest($text, $to, $from);
    }

    private function myMemoryRequest(string $text, string $to, string $from): ?string
    {
        $params = [
            'q'        => $text,
            'langpair' => $from . '|' . $to,
        ];
        if (!empty($this->myMemoryEmail)) {
            $params['de'] = $this->myMemoryEmail;
        }

        $url = 'https://api.mymemory.translated.net/get?' . http_build_query($params);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERAGENT      => 'devtrade/1.0',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            $this->logger->error('[MyMemory] HTTP ' . $httpCode);
            return null;
        }

        $data = json_decode($response, true);

        if (isset($data['responseStatus']) && $data['responseStatus'] == 429) {
            $this->logger->warning('[MyMemory] Quota journalier atteint');
            return null;
        }

        return $data['responseData']['translatedText'] ?? null;
    }

    private function callLibreTranslate(string $text, string $to, string $from): ?string
    {
        $body = json_encode([
            'q'       => $text,
            'source'  => $from,
            'target'  => $to,
            'format'  => 'html',
            'api_key' => $this->libreTranslateKey,
        ]);

        $ch = curl_init(rtrim($this->libreTranslateUrl, '/') . '/translate');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            $this->logger->error('[LibreTranslate] HTTP ' . $httpCode);
            return null;
        }

        $data = json_decode($response, true);
        return $data['translatedText'] ?? null;
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    private function getFromCache(string $key): ?string
    {
        try {
            $miss = '__MISS__' . uniqid();
            $val  = $this->cache->get($key, function (ItemInterface $item) use ($miss) {
                $item->expiresAfter(1);
                return $miss;
            });
            return str_starts_with((string)$val, '__MISS__') ? null : $val;
        } catch (\Throwable) {
            return null;
        }
    }

    private function saveToCache(string $key, string $value): void
    {
        try {
            $this->cache->delete($key);
            $this->cache->get($key, function (ItemInterface $item) use ($value) {
                $item->expiresAfter(self::CACHE_TTL);
                return $value;
            });
        } catch (\Throwable) {}
    }

    private function splitText(string $text, int $maxLen): array
    {
        $chunks  = [];
        $words   = explode(' ', $text);
        $current = '';

        foreach ($words as $word) {
            if (strlen($current) + strlen($word) + 1 > $maxLen) {
                if ($current !== '') {
                    $chunks[] = trim($current);
                }
                $current = $word;
            } else {
                $current .= ($current ? ' ' : '') . $word;
            }
        }

        if ($current !== '') {
            $chunks[] = trim($current);
        }

        return $chunks;
    }
}