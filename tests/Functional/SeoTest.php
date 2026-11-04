<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Référencement (Phase 5) : image de partage, icônes, sitemap cohérent avec les règles d'indexation. */
final class SeoTest extends WebTestCase
{
    public function testShareImageAndIconsExist(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        $publicDir = static::getContainer()->getParameter('kernel.project_dir') . '/public';
        foreach (['meta[property="og:image"]' => 'content', 'link[rel="icon"]' => 'href', 'link[rel="apple-touch-icon"]' => 'href'] as $selector => $attribute) {
            $url = (string) $client->getCrawler()->filter($selector)->attr($attribute);
            $path = (string) parse_url($url, PHP_URL_PATH);
            self::assertFileExists($publicDir . $path, $selector);
        }
        [$width, $height] = (array) getimagesize($publicDir . '/images/og-default.png');
        self::assertSame([1200, 630], [$width, $height]);
    }

    public function testSitemapListsOnlyIndexablePages(): void
    {
        $client = static::createClient();
        $client->request('GET', '/sitemap.xml');
        $xml = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('/premium</loc>', $xml);
        // Pages en noindex ou derrière une connexion : jamais dans le sitemap
        foreach (['/register', '/login', '/compte', '/documentation/article/'] as $excluded) {
            self::assertStringNotContainsString($excluded, $xml, $excluded);
        }
    }

    public function testRobotsPointsToSitemapAndBlocksPrivateAreas(): void
    {
        $client = static::createClient();
        $client->request('GET', '/robots.txt');
        $robots = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('Sitemap: http', $robots);
        foreach (['/admin', '/compte', '/documentation/article/'] as $path) {
            self::assertStringContainsString("Disallow: $path", $robots);
        }
    }
}
