<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\WaitlistEntry;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Page produit Shiro : règles de véracité (PROMPT-REFONTE §2 et §5.4) et liste d'attente (RGPD).
 */
final class TradingPageTest extends WebTestCase
{
    private const RISK_WARNING = 'Le trading de CFD et de Forex comporte un risque élevé de perte en capital.';

    public function testPageShowsStatusRiskWarningAndContextualisedBacktests(): void
    {
        $client = static::createClient();
        $client->request('GET', '/trading');

        self::assertResponseIsSuccessful();
        $text = $client->getCrawler()->filter('body')->text();

        self::assertStringContainsString('Shiro', $text);
        self::assertStringContainsString('Aucun historique réel vérifié', $text);
        self::assertStringContainsString(self::RISK_WARNING, $text);

        // Chaque série publiée : période, nombre de trades, risque, nature (simulation).
        foreach (['Janvier 2016 → octobre 2026' => '709', 'Janvier 2021 → octobre 2026' => '376', 'Janvier → octobre 2026' => '55'] as $period => $trades) {
            self::assertStringContainsString($period, $text);
            self::assertStringContainsString('Trades' . $trades, $text);
        }
        self::assertSame(3, $client->getCrawler()->filter('[data-panel]')->count());
        self::assertStringContainsString('0,5 à 1,5 % du solde par trade', $text);
        self::assertStringContainsString('Drawdown max', $text);
        self::assertStringContainsString('Backtest : simulation', $text);
        self::assertStringContainsString("n'est pas incluse dans le testeur", $text);
        self::assertSelectorExists('#limites');
        self::assertSelectorCount(3, '[data-chart][data-src]:not([data-chart="kelly"])');
    }

    #[DataProvider('forbiddenContent')]
    public function testForbiddenOrMisleadingContentIsAbsent(string $forbidden): void
    {
        $client = static::createClient();
        $client->request('GET', '/trading');

        self::assertStringNotContainsString($forbidden, $client->getCrawler()->filter('body')->text());
    }

    /** @return iterable<string, array{string}> */
    public static function forbiddenContent(): iterable
    {
        yield 'ancien rendement' => ['+68'];
        yield 'ratio de Sharpe du testeur (trompeur)' => ['Sharpe'];
        yield 'ancien drawdown' => ['<12'];
        yield 'urgence artificielle' => ['nombre limité'];
        yield 'placeholder' => ['TODO'];
        yield 'ratio de Sharpe MT5' => ['36,25'];
        // Anti rétro-ingénierie : rien qui désigne le mécanisme, le moment ou les jours de trade.
        foreach (self::SECRETS as $secret) {
            yield 'secret : ' . $secret => [$secret];
        }
    }

    /** Termes qui permettraient de reconstituer la stratégie. Aussi vérifiés sur l'accueil. */
    public const SECRETS = ['fixing', 'gotobi', '09:55', '00:55', '01:20', '25 min', 'dates exactes', 'décalées',
        '5 secondes', 'seconde près', 'Ito', 'Krohn', 'Bessho', 'Tokyo'];

    public function testPublicChartDataIsMonthlyOnly(): void
    {
        foreach (glob(\dirname(__DIR__, 2) . '/public/data/shiro/*.json') ?: [] as $file) {
            $json = (string) file_get_contents($file);
            // Aucune date au jour près (AAAA-MM-JJ) : seules des valeurs mensuelles sont publiées.
            self::assertDoesNotMatchRegularExpression('/\d{4}-\d{2}-\d{2}/', $json, basename($file));
            self::assertStringNotContainsString('nominal', $json, basename($file));
        }
    }

    public function testWaitlistRequiresCsrfToken(): void
    {
        $client = static::createClient();
        $client->request('POST', '/trading/waitlist', ['email' => 'a@exemple.fr', 'consent' => '1']);

        self::assertResponseStatusCodeSame(400);
    }

    public function testWaitlistRequiresConsent(): void
    {
        $client = static::createClient();
        $this->submit($client, ['email' => 'sans-consentement@exemple.fr']);

        self::assertResponseStatusCodeSame(400);
        self::assertNull($this->findEntry('sans-consentement@exemple.fr'));
    }

    public function testWaitlistStoresConsentAndAnswersIdenticallyForDuplicates(): void
    {
        $client = static::createClient();
        $email = 'inscription-' . bin2hex(random_bytes(4)) . '@exemple.fr';

        $this->submit($client, ['email' => $email, 'consent' => '1']);
        self::assertResponseIsSuccessful();
        $first = $client->getResponse()->getContent();

        $entry = $this->findEntry($email);
        self::assertNotNull($entry);
        self::assertNotNull($entry->getConsentAt());

        // Même réponse si l'adresse est déjà inscrite : pas d'énumération possible.
        $this->submit($client, ['email' => $email, 'consent' => '1']);
        self::assertResponseIsSuccessful();
        self::assertSame($first, $client->getResponse()->getContent());
    }

    public function testHoneypotSilentlyDropsBots(): void
    {
        $client = static::createClient();
        $email = 'robot-' . bin2hex(random_bytes(4)) . '@exemple.fr';

        $this->submit($client, ['email' => $email, 'consent' => '1', 'website' => 'http://spam.example']);

        self::assertResponseIsSuccessful();
        self::assertNull($this->findEntry($email));
    }

    /** @param array<string, string> $fields */
    private function submit(KernelBrowser $client, array $fields): void
    {
        $crawler = $client->request('GET', '/trading');
        $token = $crawler->filter('form[data-waitlist] input[name="_token"]')->attr('value');
        $client->request('POST', '/trading/waitlist', $fields + ['_token' => (string) $token]);
    }

    private function findEntry(string $email): ?WaitlistEntry
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->getRepository(WaitlistEntry::class)->findOneBy(['email' => $email]);
    }
}
