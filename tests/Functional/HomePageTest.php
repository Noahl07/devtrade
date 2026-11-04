<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Accueil refondu (Phase 2) : contenu, conformité, absence de ressources tierces.
 */
final class HomePageTest extends WebTestCase
{
    public function testHomeRendersNewLayout(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a.skip-link[href="#contenu"]');
        self::assertSelectorExists('header.site-header nav[aria-label="Navigation principale"]');
        self::assertSelectorExists('dialog#site-menu');
        self::assertSelectorTextContains('h1', 'Du logiciel sur mesure');
        self::assertSelectorExists('[data-hero-scene]');
        self::assertSelectorExists('footer.site-footer [role="note"]');
    }

    public function testHomeHasNoThirdPartyResourcesNorLegacyCss(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');
        $html = (string) $client->getResponse()->getContent();

        foreach (['fonts.googleapis.com', 'fonts.gstatic.com', 'cdn.jsdelivr.net', 'cdnjs.cloudflare.com', '/build/app.css'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $html, $forbidden);
        }
    }

    #[DataProvider('forbiddenContent')]
    public function testHomeHasNoPerformanceClaims(string $forbidden): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertStringNotContainsString($forbidden, (string) $client->getResponse()->getContent());
    }

    /** @return iterable<string, array{string}> */
    public static function forbiddenContent(): iterable
    {
        yield 'ancien rendement' => ['+68'];
        yield 'Sharpe' => ['Sharpe'];
        yield 'note JSON-LD' => ['aggregateRating'];
        yield 'avis JSON-LD' => ['"review"'];
        yield 'promesse' => ['revenu passif'];
        yield 'garantie' => ['garanti'];
        foreach (TradingPageTest::SECRETS as $secret) {
            yield 'secret : ' . $secret => [$secret];
        }
    }

    public function testHomeShowsHonestEmptyStateWithoutProjects(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        // Base de test vide : aucun projet inventé, un état vide explicite.
        self::assertSelectorTextContains('.works-empty', 'Études de cas en cours de rédaction');
    }

    /** Pages pas encore refondues : toujours accessibles avec le nouveau layout. */
    #[DataProvider('legacyPages')]
    public function testLegacyPagesStillRender(string $url): void
    {
        $client = static::createClient();
        $client->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('header.site-header');
        self::assertSelectorExists('footer.site-footer');
    }

    /** @return iterable<string, array{string}> */
    public static function legacyPages(): iterable
    {
        yield 'contact' => ['/contact'];
        yield 'connexion' => ['/login'];
        yield 'inscription' => ['/register'];
        yield 'documentation' => ['/documentation'];
        yield 'portfolio' => ['/portfolio'];
        yield 'premium' => ['/premium'];
        yield 'cgu' => ['/conditions-utilisation'];
    }
}
