<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Content-Security-Policy stricte sur le site public (Phase 5). */
final class SecurityHeadersTest extends WebTestCase
{
    /** @return iterable<string, array{string}> */
    public static function publicPages(): iterable
    {
        foreach (['/', '/trading', '/portfolio', '/documentation', '/contact', '/login', '/register', '/premium', '/risques'] as $url) {
            yield $url => [$url];
        }
    }

    #[DataProvider('publicPages')]
    public function testPublicPagesHaveStrictScriptPolicyAndNoInlineScript(string $url): void
    {
        $client = static::createClient();
        $client->request('GET', $url);

        $csp = (string) $client->getResponse()->headers->get('Content-Security-Policy');
        self::assertStringContainsString("script-src 'self';", $csp);
        self::assertStringContainsString("object-src 'none'", $csp);
        self::assertStringContainsString("frame-ancestors 'self'", $csp);

        // Aucun script exécutable en ligne : seuls les fichiers du build et les blocs JSON-LD (non exécutés).
        $client->getCrawler()->filter('script')->each(static function ($script) use ($url): void {
            $type = (string) $script->attr('type');
            self::assertTrue($script->attr('src') !== null || $type === 'application/ld+json', "Script en ligne sur $url");
        });
        self::assertDoesNotMatchRegularExpression('/\son[a-z]+="/i', (string) $client->getResponse()->getContent(), "Attribut on*= sur $url");
    }

    public function testNonHtmlResponsesAreNotGivenAPolicy(): void
    {
        $client = static::createClient();
        $client->request('GET', '/sitemap.xml');

        self::assertFalse($client->getResponse()->headers->has('Content-Security-Policy'));
    }
}
