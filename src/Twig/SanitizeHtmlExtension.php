<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Filtre |sanitize_html : conserve une liste blanche de balises/attributs
 * et supprime scripts, handlers on*, iframes, URLs javascript:/data:, etc.
 *
 * Schémas : un sous-ensemble du SVG est autorisé (formes, texte, dégradés, marqueurs), sans script,
 * sans lien externe (pas de use/image/foreignObject/animations) ni url() autre qu'une référence interne « url(#id) ».
 *
 * Styles en ligne : jamais conservés tels quels. Les couleurs et alignements des anciens articles
 * deviennent des classes du design system (is-positive, is-negative, is-accent, is-muted, ta-center, ta-right) ;
 * sur un schéma, seule la largeur maximale en px est gardée.
 *
 * Libellés de schéma traduisibles : un élément de classe « doc-label » garde une position
 * « left: N%; top: N% » (nombres uniquement), pour poser du texte HTML par-dessus le dessin SVG.
 */
class SanitizeHtmlExtension extends AbstractExtension
{
    private const ALLOWED_TAGS = [
        'p', 'br', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'b', 'em', 'i', 'u', 's',
        'blockquote', 'ul', 'ol', 'li', 'a', 'img', 'code', 'pre', 'span', 'div', 'table',
        'thead', 'tbody', 'tr', 'th', 'td', 'sub', 'sup', 'small', 'figure', 'figcaption',
        'aside', 'details', 'summary', 'dl', 'dt', 'dd', 'mark', 'kbd', 'abbr', 'caption',
    ];

    /** Éléments SVG autorisés (noms en minuscules : le parseur HTML les normalise ainsi). */
    private const SVG_TAGS = [
        'svg', 'g', 'defs', 'title', 'desc', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'path',
        'text', 'tspan', 'lineargradient', 'radialgradient', 'stop', 'marker', 'pattern', 'clippath',
    ];

    private const SVG_ATTRS = [
        'viewbox', 'width', 'height', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'd', 'points',
        'fill', 'fill-opacity', 'fill-rule', 'stroke', 'stroke-width', 'stroke-opacity', 'stroke-dasharray',
        'stroke-dashoffset', 'stroke-linecap', 'stroke-linejoin', 'opacity', 'transform', 'id', 'class',
        'text-anchor', 'dominant-baseline', 'alignment-baseline', 'font-size', 'font-family', 'font-weight',
        'font-style', 'letter-spacing', 'dx', 'dy', 'offset', 'stop-color', 'stop-opacity', 'gradientunits',
        'gradienttransform', 'fx', 'fy', 'markerwidth', 'markerheight', 'markerunits', 'refx', 'refy', 'orient',
        'marker-start', 'marker-mid', 'marker-end', 'preserveaspectratio', 'patternunits', 'clip-path', 'xmlns',
        'role', 'aria-label', 'aria-hidden',
    ];

    private const ALLOWED_ATTRS = [
        'a'   => ['href', 'title', 'target', 'rel'],
        'img' => ['src', 'alt', 'title', 'width', 'height'],
        'abbr' => ['title'],
        'th'  => ['scope', 'colspan', 'rowspan'],
        'td'  => ['colspan', 'rowspan'],
        '*'   => ['class'],
    ];

    /** Balises supprimées avec tout leur contenu. */
    private const DROP_WITH_CONTENT = [
        'script', 'style', 'iframe', 'object', 'embed', 'form', 'math', 'link', 'meta', 'base', 'noscript',
        'template', 'foreignobject', 'use', 'image', 'animate', 'animatetransform', 'animatemotion', 'set',
        'feimage', 'filter', 'mask', 'switch', 'textarea', 'select', 'input', 'button',
    ];

    /** Seule position acceptée sur un libellé de schéma. */
    private const LABEL_POSITION = '/^\s*left:\s*(-?\d{1,3}(?:\.\d{1,2})?)%;\s*top:\s*(-?\d{1,3}(?:\.\d{1,2})?)%;?\s*$/';

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
        $this->clean($body, false);

        $out = '';
        foreach ($body->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        return $out;
    }

    private function clean(\DOMNode $node, bool $inSvg): void
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
            $isSvg = $inSvg ? in_array($tag, self::SVG_TAGS, true) : $tag === 'svg';
            // Dans un schéma, seuls les éléments SVG (et le lien a, contrôlé plus bas) sont acceptés.
            $allowedTag = $isSvg || (!$inSvg && in_array($tag, self::ALLOWED_TAGS, true)) || ($inSvg && $tag === 'a');

            if (!$allowedTag) {
                if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                    $node->removeChild($child);
                    continue;
                }
                // Autres : on garde le texte/enfants nettoyés
                $this->clean($child, $inSvg);
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }

            $style = $child->getAttribute('style');
            $classes = $this->classesFromStyle($style, $tag);
            $allowed = $isSvg
                ? self::SVG_ATTRS
                : array_merge(self::ALLOWED_ATTRS['*'], self::ALLOWED_ATTRS[$tag] ?? []);

            foreach (iterator_to_array($child->attributes) as $attr) {
                $name = strtolower($attr->name);
                if (!in_array($name, $allowed, true) || !$this->isSafeAttributeValue($attr->value)) {
                    $child->removeAttribute($attr->name);
                    continue;
                }
                if (in_array($name, ['href', 'src'], true) && !$this->isSafeUrl($attr->value, $name === 'src')) {
                    $child->removeAttribute($attr->name);
                }
            }

            if ($tag === 'svg') {
                $classes[] = 'doc-figure';
                $maxWidth = $this->svgMaxWidth($style);
                if ($maxWidth !== null) {
                    $child->setAttribute('style', sprintf('max-width: %dpx', $maxWidth));
                }
            }

            if (!$isSvg && preg_match(self::LABEL_POSITION, $style, $position)
                && in_array('doc-label', preg_split('/\s+/', $child->getAttribute('class')) ?: [], true)) {
                $child->setAttribute('style', sprintf('left: %s%%; top: %s%%', $position[1], $position[2]));
            }

            if ($classes !== []) {
                $existing = preg_split('/\s+/', trim($child->getAttribute('class'))) ?: [];
                $child->setAttribute('class', implode(' ', array_values(array_unique(array_filter([...$existing, ...$classes])))));
            }

            if ($tag === 'a' && $child->hasAttribute('target')) {
                $child->setAttribute('rel', 'noopener noreferrer');
            }

            $this->clean($child, $inSvg || $tag === 'svg');
        }
    }

    /**
     * Couleurs et alignements des anciens articles → classes du design system.
     *
     * @return list<string>
     */
    private function classesFromStyle(string $style, string $tag): array
    {
        if ($style === '' || $tag === 'svg') {
            return [];
        }
        $classes = [];
        $style = strtolower($style);
        if (preg_match('/text-align\s*:\s*(center|right)/', $style, $m)) {
            $classes[] = 'ta-' . $m[1];
        }
        if (preg_match('/(?:^|;)\s*color\s*:\s*([^;]+)/', $style, $m)) {
            $color = str_replace(' ', '', $m[1]);
            $classes[] = match (true) {
                str_contains($color, '3eb489'), str_contains($color, '62,180,137'), str_contains($color, '2e8b73') => 'is-positive',
                str_contains($color, 'e05555'), str_contains($color, '224,85,85'), str_contains($color, 'd9534f') => 'is-negative',
                str_contains($color, 'd4a94b'), str_contains($color, '212,169,75'), str_contains($color, 'c8a45c') => 'is-accent',
                default => 'is-muted',
            };
        }

        return $classes;
    }

    private function svgMaxWidth(string $style): ?int
    {
        if (preg_match('/max-width\s*:\s*(\d{2,4})px/i', $style, $m)) {
            return min((int) $m[1], 1200);
        }

        return null;
    }

    /** Aucun attribut ne doit pouvoir déclencher de requête externe ni de script. */
    private function isSafeAttributeValue(string $value): bool
    {
        $v = strtolower(preg_replace('/[\x00-\x20]+/', '', html_entity_decode($value)) ?? '');
        if (str_contains($v, 'javascript:') || str_contains($v, 'vbscript:') || str_contains($v, 'expression(')) {
            return false;
        }
        // url(...) seulement vers une référence interne (dégradé, marqueur) : url(#id)
        if (preg_match_all('/url\(([^)]*)\)/', $v, $matches)) {
            foreach ($matches[1] as $target) {
                if (!str_starts_with(trim($target, '\'"'), '#')) {
                    return false;
                }
            }
        }

        return true;
    }

    private function isSafeUrl(string $url, bool $isImage): bool
    {
        $url = preg_replace('/[\x00-\x20]+/', '', html_entity_decode($url)) ?? '';
        if ($url === '' || str_starts_with($url, '/') || str_starts_with($url, '#')) {
            return !str_starts_with($url, '//');
        }
        if (preg_match('#^(https?:|mailto:)#i', $url)) {
            return !($isImage && stripos($url, 'mailto:') === 0);
        }
        // URLs relatives sans schéma
        return !preg_match('#^[a-z][a-z0-9+.-]*:#i', $url);
    }
}
