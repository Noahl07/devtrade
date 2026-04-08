<?php

namespace App\Service;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Psr\Log\LoggerInterface;

/**
 * Service de traduction automatique via MyMemory API
 * avec cache Symfony par bloc de contenu.
 *
 * - Locale 'fr' → retourne immédiatement, 0 appel API
 * - Cache par sha1(contenu) → traduit une seule fois pour toujours
 * - Fragmente automatiquement les textes > 450 chars (limite MyMemory)
 * - Fallback silencieux : si API hors quota → texte FR affiché, site reste lisible
 *
 * Pour passer sur LibreTranslate self-hosted (Hetzner) :
 *   TRANSLATION_PROVIDER=libretranslate
 *   LIBRETRANSLATE_URL=http://localhost:5000
 */
class TranslationCacheService
{
    private const CACHE_TTL        = 60 * 60 * 24 * 30; // 30 jours
    private const CHUNK_SIZE       = 450;                 // limite MyMemory par requête

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

    /**
     * Traduit un bloc de texte simple (titre, extrait, description...).
     */
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

    /**
     * Traduit plusieurs blocs d'un coup.
     * Ceux déjà en cache ne consomment aucun char API.
     *
     * @param string[] $texts
     * @return string[]
     */
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
     * Traduit une page HTML complète (approche "Chrome translate").
     * Extrait uniquement le texte visible, traduit par blocs, reconstruit le HTML.
     * Cache par cacheKey (= sha1 de route+params) → 1 appel API par page par langue.
     */
    public function translateHtmlPage(string $html, string $targetLocale, string $cacheKey): string
    {
        if ($targetLocale === 'fr') {
            return $html;
        }

        $fullCacheKey = 'page_' . $targetLocale . '_' . $cacheKey;

        try {
            return $this->cache->get($fullCacheKey, function (ItemInterface $item) use ($html, $targetLocale) {
                $item->expiresAfter(self::CACHE_TTL);
                return $this->doTranslateHtml($html, $targetLocale);
            });
        } catch (\Throwable $e) {
            $this->logger->error('[Translation HTML] ' . $e->getMessage());
            return $html; // fallback : page en FR
        }
    }

    /**
     * Invalide le cache d'un contenu (appeler lors de la modif d'un article).
     */
    public function invalidate(string $text, string $targetLocale = 'en'): void
    {
        try {
            $this->cache->delete('trans_' . $targetLocale . '_' . sha1($text));
        } catch (\Throwable) {}
    }

    /**
     * Invalide le cache d'une page entière (appeler lors de la modif d'un article).
     * Passer le même cacheKey que dans translateHtmlPage.
     */
    public function invalidatePage(string $cacheKey, string $targetLocale = 'en'): void
    {
        try {
            $this->cache->delete('page_' . $targetLocale . '_' . $cacheKey);
        } catch (\Throwable) {}
    }

    // ──────────────────────────────────────────────
    // Traduction HTML
    // ──────────────────────────────────────────────

    private function doTranslateHtml(string $html, string $targetLocale): string
    {
        // On utilise DOMDocument pour extraire les nœuds texte
        // et ne traduire que le contenu visible (pas les attributs, scripts, styles)
        $dom = new \DOMDocument('1.0', 'UTF-8');

        // Supprimer les warnings HTML5
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        // Collecter tous les nœuds texte à traduire
        $textNodes = [];
        $this->collectTextNodes($dom, $textNodes);

        if (empty($textNodes)) {
            return $html;
        }

        // Extraire les textes et traduire par batch
        $texts = array_map(fn(\DOMText $node) => $node->nodeValue, $textNodes);

        // Filtrer les textes vides / whitespace only
        $toTranslate = [];
        foreach ($texts as $i => $text) {
            $clean = trim($text);
            if (strlen($clean) > 1) { // ignorer les chars isolés
                $toTranslate[$i] = $clean;
            }
        }

        if (empty($toTranslate)) {
            return $html;
        }

        // Traduire par chunks de 20 textes à la fois (évite les timeouts)
        $chunks = array_chunk($toTranslate, 20, true);
        $translated = [];

        foreach ($chunks as $chunk) {
            $result = $this->translateBatch($chunk, $targetLocale);
            $translated += $result;
            if (count($chunks) > 1) {
                usleep(100000); // 100ms entre chunks
            }
        }

        // Réinjecter les traductions dans les nœuds DOM
        foreach ($translated as $i => $translatedText) {
            // Préserver les espaces originaux autour du texte
            $original = $texts[$i];
            $leading  = strlen($original) - strlen(ltrim($original));
            $trailing = strlen($original) - strlen(rtrim($original));
            $textNodes[$i]->nodeValue =
                substr($original, 0, $leading) .
                $translatedText .
                substr($original, strlen($original) - $trailing);
        }

        $result = $dom->saveHTML();

        // Nettoyer le préfixe XML ajouté
        $result = str_replace('<?xml encoding="UTF-8">', '', $result);

        return $result ?: $html;
    }

    /**
     * Collecte récursivement les nœuds texte visibles du DOM.
     * Ignore : <script>, <style>, <code>, <pre>, attributs, data-no-translate
     */
    private function collectTextNodes(\DOMNode $node, array &$textNodes): void
    {
        // Tags à ignorer complètement
        $skipTags = ['script', 'style', 'code', 'pre', 'textarea', 'meta', 'link'];

        if ($node instanceof \DOMElement) {
            if (in_array(strtolower($node->nodeName), $skipTags)) {
                return;
            }
            // Attribut data-no-translate → skip ce bloc et ses enfants
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
    // Routeur de provider
    // ──────────────────────────────────────────────

    private function callApi(string $text, string $to, string $from): ?string
    {
        return match ($this->provider) {
            'libretranslate' => $this->callLibreTranslate($text, $to, $from),
            default          => $this->callMyMemory($text, $to, $from),
        };
    }

    // ──────────────────────────────────────────────
    // MyMemory
    // ──────────────────────────────────────────────

    private function callMyMemory(string $text, string $to, string $from): ?string
    {
        if (strlen($text) <= self::CHUNK_SIZE) {
            return $this->myMemoryRequest($text, $to, $from);
        }

        $chunks     = $this->splitText($text, self::CHUNK_SIZE);
        $translated = [];
        foreach ($chunks as $chunk) {
            $result       = $this->myMemoryRequest($chunk, $to, $from);
            $translated[] = $result ?? $chunk;
            usleep(150000); // 150ms entre requêtes
        }

        return implode(' ', $translated);
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
            CURLOPT_TIMEOUT        => 10,
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

    // ──────────────────────────────────────────────
    // LibreTranslate (self-hosted Hetzner — futur)
    // ──────────────────────────────────────────────

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
            CURLOPT_TIMEOUT        => 15,
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
    // Helpers cache
    // ──────────────────────────────────────────────

    private function getFromCache(string $key): ?string
    {
        try {
            $result = null;
            $this->cache->get($key, function (ItemInterface $item) use (&$result) {
                $result = null; // cache miss
                $item->expiresAfter(1);
                return null;
            });
            return $result;
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