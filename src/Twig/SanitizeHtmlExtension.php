<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Filtre |sanitize_html : conserve une liste blanche de balises/attributs
 * et supprime scripts, handlers on*, iframes, URLs javascript:/data:, etc.
 */
class SanitizeHtmlExtension extends AbstractExtension
{
    private const ALLOWED_TAGS = [
        'p', 'br', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'b', 'em', 'i', 'u', 's',
        'blockquote', 'ul', 'ol', 'li', 'a', 'img', 'code', 'pre', 'span', 'div', 'table',
        'thead', 'tbody', 'tr', 'th', 'td', 'sub', 'sup', 'small', 'figure', 'figcaption',
    ];

    private const ALLOWED_ATTRS = [
        'a'   => ['href', 'title', 'target', 'rel'],
        'img' => ['src', 'alt', 'title', 'width', 'height'],
        '*'   => ['class'],
    ];

    public function getFilters(): array
    {
        return [new TwigFilter('sanitize_html', [$this, 'sanitize'], ['is_safe' => ['html']])];
    }

    public function sanitize(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $prev = libxml_use_internal_errors(true);
        $doc  = new \DOMDocument();
        $doc->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $body = $doc->getElementsByTagName('body')->item(0);
        if (!$body) {
            return '';
        }
        $this->clean($body);

        $out = '';
        foreach ($body->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        return $out;
    }

    private function clean(\DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMComment) {
                $node->removeChild($child);
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);
            if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                // Balises dangereuses : suppression complète avec contenu
                if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'svg', 'math', 'link', 'meta', 'base', 'noscript'], true)) {
                    $node->removeChild($child);
                    continue;
                }
                // Autres : on garde le texte/enfants nettoyés
                $this->clean($child);
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }

            $allowed = array_merge(self::ALLOWED_ATTRS['*'], self::ALLOWED_ATTRS[$tag] ?? []);
            foreach (iterator_to_array($child->attributes) as $attr) {
                $name = strtolower($attr->name);
                if (!in_array($name, $allowed, true)) {
                    $child->removeAttribute($attr->name);
                    continue;
                }
                if (in_array($name, ['href', 'src'], true) && !$this->isSafeUrl($attr->value, $name === 'src')) {
                    $child->removeAttribute($attr->name);
                }
            }

            if ($tag === 'a' && $child->hasAttribute('target')) {
                $child->setAttribute('rel', 'noopener noreferrer');
            }

            $this->clean($child);
        }
    }

    private function isSafeUrl(string $url, bool $isImage): bool
    {
        $url = preg_replace('/[\x00-\x20]+/', '', html_entity_decode($url)) ?? '';
        if ($url === '' || str_starts_with($url, '/') || str_starts_with($url, '#')) {
            return true;
        }
        if (preg_match('#^(https?:|mailto:)#i', $url)) {
            return !($isImage && stripos($url, 'mailto:') === 0);
        }
        // URLs relatives sans schéma
        return !preg_match('#^[a-z][a-z0-9+.-]*:#i', $url);
    }
}
