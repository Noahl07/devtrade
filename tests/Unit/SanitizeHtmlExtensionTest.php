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
}
