<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Twig\SanitizeHtmlExtension;
use PHPUnit\Framework\TestCase;

final class SanitizeHtmlExtensionTest extends TestCase
{
    private SanitizeHtmlExtension $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new SanitizeHtmlExtension();
    }

    public function testRemovesScriptsAndEventHandlers(): void
    {
        $out = $this->sanitizer->sanitize('<p onclick="x()">ok</p><script>alert(1)</script><img src="x.png" onerror="alert(1)">');

        self::assertStringNotContainsString('script', $out);
        self::assertStringNotContainsString('onclick', $out);
        self::assertStringNotContainsString('onerror', $out);
        self::assertStringContainsString('<p>ok</p>', $out);
    }

    public function testRemovesDangerousUrls(): void
    {
        $out = $this->sanitizer->sanitize('<a href="javascript:alert(1)">a</a><a href="  JaVaScRiPt:alert(1)">b</a><img src="data:image/svg+xml,x">');

        self::assertStringNotContainsStringIgnoringCase('javascript', $out);
        self::assertStringNotContainsString('data:', $out);
    }

    public function testKeepsSafeContent(): void
    {
        $html = '<h2>Titre</h2><p><strong>gras</strong> <a href="https://devtrade.fr" title="site">lien</a></p><ul><li>un</li></ul>';

        self::assertSame($html, $this->sanitizer->sanitize($html));
    }

    public function testExternalLinksWithTargetGetNoopener(): void
    {
        $out = $this->sanitizer->sanitize('<a href="https://exemple.fr" target="_blank">x</a>');

        self::assertStringContainsString('rel="noopener noreferrer"', $out);
    }

    /** Les schémas SVG des articles doivent s'afficher (régression phase 4 : ils étaient supprimés). */
    public function testKeepsSvgDiagrams(): void
    {
        $out = $this->sanitizer->sanitize('<svg viewBox="0 0 100 50" width="100%" style="display:block;max-width:560px;margin:1.5rem auto"><defs><marker id="m" markerWidth="6" markerHeight="6" refX="3" refY="3" orient="auto"><path d="M0 0L6 3L0 6Z" fill="#3EB489"/></marker></defs><rect x="1" y="2" width="3" height="4" rx="2" fill="rgba(62,180,137,0.2)" stroke="#3EB489" stroke-width="1.5"/><line x1="0" y1="0" x2="9" y2="9" marker-end="url(#m)" stroke-dasharray="3 3"/><text x="5" y="6" text-anchor="middle" font-size="10" font-weight="bold">ASIE</text><polyline points="0,0 5,5"/></svg>');

        foreach (['<svg', 'viewbox="0 0 100 50"', '<rect', 'fill="rgba(62,180,137,0.2)"', '<text', 'ASIE', 'marker-end="url(#m)"', '<polyline', '<marker'] as $expected) {
            self::assertStringContainsStringIgnoringCase($expected, $out);
        }
        self::assertStringContainsString('class="doc-figure"', $out);
        self::assertStringContainsString('max-width: 560px', $out, 'Seule la largeur maximale du schéma est conservée');
        self::assertStringNotContainsString('margin', $out);
    }

    public function testRemovesDangerousSvgContent(): void
    {
        $out = $this->sanitizer->sanitize('<svg><script>alert(1)</script><foreignObject><p>x</p></foreignObject><use href="https://evil/x.svg#a"/><image href="https://evil/x.png"/><animate attributeName="href" to="javascript:alert(1)"/><a href="javascript:alert(2)"><text>t</text></a><rect onload="alert(3)" fill="url(https://evil/x)" style="fill:red"/></svg>');

        foreach (['script', 'foreignobject', '<use', '<image', '<animate', 'javascript', 'onload', 'evil', 'style='] as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase($forbidden, $out, $forbidden);
        }
    }

    /** Les couleurs en ligne des anciens articles deviennent des classes du design system. */
    public function testInlineStylesBecomeDesignSystemClasses(): void
    {
        $out = $this->sanitizer->sanitize('<table style="width:100%"><tr style="border-bottom:1px solid red"><td style="text-align:center;padding:8px;color:#3EB489">+2</td><td style="color:#e05555">-1</td><td style="color:#D4A94B">x</td><td style="color:rgba(232,234,240,0.6)">y</td></tr></table>');

        self::assertStringNotContainsString('style=', $out);
        self::assertStringContainsString('<td class="ta-center is-positive">+2</td>', $out);
        self::assertStringContainsString('<td class="is-negative">-1</td>', $out);
        self::assertStringContainsString('<td class="is-accent">x</td>', $out);
        self::assertStringContainsString('<td class="is-muted">y</td>', $out);
    }

    /** Libellés de schéma en HTML (traduisibles) : seule une position en % est conservée, et seulement sur .doc-label. */
    public function testDiagramLabelsKeepOnlyAPercentagePosition(): void
    {
        $out = $this->sanitizer->sanitize(
            '<span class="doc-label is-accent" style="left:12.5%;top:40%">Londres</span>'
            . '<span class="doc-label" style="left:10%;top:5%;background:url(https://x.test/a.png)">A</span>'
            . '<span class="doc-label" style="left:calc(1px);top:2%">B</span>'
            . '<span class="autre" style="left:10%;top:5%">C</span>'
        );

        self::assertStringContainsString('<span class="doc-label is-accent" style="left: 12.5%; top: 40%">Londres</span>', $out);
        self::assertStringContainsString('<span class="doc-label">A</span>', $out);
        self::assertStringContainsString('<span class="doc-label">B</span>', $out);
        self::assertStringContainsString('<span class="autre">C</span>', $out);
        self::assertStringNotContainsString('url(', $out);
    }
}
