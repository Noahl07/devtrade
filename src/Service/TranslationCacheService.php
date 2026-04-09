<?php

namespace App\Service;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Psr\Log\LoggerInterface;

class TranslationCacheService
{
    private const CACHE_TTL  = 60 * 60 * 24 * 30;
    private const CHUNK_SIZE = 450;

    public function __construct(
        private CacheInterface  $cache,
        private LoggerInterface $logger,
        private string          $provider          = 'mymemory',
        private string          $myMemoryEmail     = '',
        private string          $libreTranslateUrl = 'http://localhost:5000',
        private string          $libreTranslateKey = ''
    ) {}

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

    public function translateHtmlPage(string $html, string $targetLocale, string $cacheKey): string
    {
        if ($targetLocale === 'fr') {
            return $html;
        }

        $fullCacheKey = 'page_' . $targetLocale . '_' . $cacheKey;

        try {
            return $this->cache->get($fullCacheKey, function (ItemInterface $item) use ($html, $targetLocale) {
                // Augmenter le timeout PHP uniquement pendant la première traduction
                set_time_limit(300);

                $item->expiresAfter(self::CACHE_TTL);
                return $this->doTranslateHtml($html, $targetLocale);
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
    // Traduction HTML complète (tout le body)
    // ──────────────────────────────────────────────

    private function doTranslateHtml(string $html, string $targetLocale): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $textNodes = [];
        $this->collectTextNodes($dom, $textNodes);

        if (empty($textNodes)) {
            return $html;
        }

        // Filtrer les textes vides / trop courts
        $toTranslate = [];
        foreach ($textNodes as $i => $node) {
            $clean = trim($node->nodeValue);
            if (mb_strlen($clean) > 1) {
                $toTranslate[$i] = $clean;
            }
        }

        if (empty($toTranslate)) {
            return $html;
        }

        // Traduire chaque texte individuellement avec cache
        foreach ($toTranslate as $i => $text) {
            $cacheKey = 'trans_' . $targetLocale . '_' . sha1($text);
            $cached   = $this->getFromCache($cacheKey);

            if ($cached !== null) {
                $translatedText = $cached;
            } else {
                $translatedText = $this->callApi($text, $targetLocale, 'fr') ?? $text;
                $this->saveToCache($cacheKey, $translatedText);
                usleep(200000); // 200ms entre appels pour éviter le rate limit
            }

            // Préserver les espaces autour du texte
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
        $params = ['q' => $text, 'langpair' => $from . '|' . $to];
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
    // Helpers cache
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