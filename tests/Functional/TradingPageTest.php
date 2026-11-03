<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Page produit : règles de véracité (PROMPT-REFONTE §2 et §5.4).
 */
final class TradingPageTest extends WebTestCase
{
    private const RISK_WARNING = 'Le trading de CFD et de Forex comporte un risque élevé de perte en capital.';

    public function testPageShowsStatusRiskWarningAndContextualisedBacktest(): void
    {
        $client = static::createClient();
        $client->request('GET', '/trading');

        self::assertResponseIsSuccessful();
        $text = $client->getCrawler()->filter('body')->text();

        self::assertStringContainsString('Shiro', $text);
        self::assertStringContainsString('Aucun historique réel vérifié', $text);
        self::assertStringContainsString(self::RISK_WARNING, $text);
        // Chaque chiffre publié vient avec sa période, ses trades et son risque.
        self::assertStringContainsString('2016 → 20 octobre 2026', $text);
        self::assertStringContainsString('693', $text);
        self::assertStringContainsString('0,5 % du solde par trade', $text);
        self::assertStringContainsString('Backtest : simulation, pas un résultat réel', $text);
        self::assertSelectorExists('#limites');
    }

    #[DataProvider('forbiddenContent')]
    public function testForbiddenOrUnconfirmedContentIsAbsent(string $forbidden): void
    {
        $client = static::createClient();
        $client->request('GET', '/trading');

        self::assertStringNotContainsString($forbidden, $client->getCrawler()->filter('body')->text());
    }

    /** @return iterable<string, array{string}> */
    public static function forbiddenContent(): iterable
    {
        yield 'ancien rendement' => ['+68'];
        yield 'ancien Sharpe' => ['Sharpe'];
        yield 'ancien drawdown' => ['<12'];
        yield 'urgence artificielle' => ['nombre limité'];
        yield 'série non confirmée 10 ans' => ['+240'];
        yield 'série non confirmée 5 ans' => ['+115'];
        yield 'série non confirmée 2026' => ['18,5'];
        yield 'placeholder' => ['TODO'];
    }
}
