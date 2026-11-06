<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\PageView;
use App\Entity\User;
use App\Service\Analytics\AnalyticsReport;
use App\Service\Analytics\PageViewRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Mesure d'audience sans cookie : ce qui est compté, ce qui ne l'est pas, données conservées, tableau de bord. */
final class AnalyticsTest extends WebTestCase
{
    private const BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->createQuery('DELETE FROM ' . PageView::class . ' v')->execute();
    }

    public function testAPublicPageViewIsRecordedWithoutPersonalData(): void
    {
        $this->client->request('GET', '/contact', server: [
            'HTTP_USER_AGENT' => self::BROWSER,
            'HTTP_REFERER' => 'https://www.google.fr/search?q=devtrade',
            'HTTP_ACCEPT_LANGUAGE' => 'en-GB,en;q=0.9,fr;q=0.8',
            'REMOTE_ADDR' => '203.0.113.7',
        ]);
        self::assertResponseIsSuccessful();

        $views = $this->views();
        self::assertCount(1, $views);
        $view = $views[0];
        self::assertSame('/contact', $view->getPath());
        self::assertSame('google', $view->getReferrer());
        self::assertSame('en', $view->getLanguage());
        self::assertSame('ordinateur', $view->getDevice());
        self::assertSame('Chrome', $view->getBrowser());
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $view->getVisitor());
        self::assertStringNotContainsString('203.0.113.7', $view->getVisitor());
        // Seul le cookie de session du formulaire (jeton CSRF) peut apparaître : la mesure n'en pose aucun.
        $names = array_map(static fn ($c): string => $c->getName(), $this->client->getResponse()->headers->getCookies());
        self::assertSame([], array_values(array_filter($names, static fn (string $n): bool => !str_ends_with($n, 'SESSID'))));
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function ignoredRequests(): iterable
    {
        yield 'robot' => [['HTTP_USER_AGENT' => 'Mozilla/5.0 (compatible; Googlebot/2.1)']];
        yield 'Do Not Track' => [['HTTP_USER_AGENT' => self::BROWSER, 'HTTP_DNT' => '1']];
        yield 'Global Privacy Control' => [['HTTP_USER_AGENT' => self::BROWSER, 'HTTP_SEC_GPC' => '1']];
        yield 'préchargement' => [['HTTP_USER_AGENT' => self::BROWSER, 'HTTP_SEC_PURPOSE' => 'prefetch']];
        yield 'sans navigateur' => [['HTTP_USER_AGENT' => '']];
    }

    /** @param array<string, string> $server */
    #[\PHPUnit\Framework\Attributes\DataProvider('ignoredRequests')]
    public function testIgnoredRequestsAreNotRecorded(array $server): void
    {
        $this->client->request('GET', '/contact', server: $server);

        self::assertCount(0, $this->views());
    }

    public function testAdministratorsAndAdminPagesAreNotCounted(): void
    {
        $admin = (new User())->setEmail('stats-' . bin2hex(random_bytes(3)) . '@exemple.fr')->setFirstName('Ad')->setLastName('Min')
            ->setPassword('inutilise')->setRoles(['ROLE_ADMIN'])->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($admin);
        $this->em->flush();
        $this->client->loginUser($admin);

        $this->client->request('GET', '/contact', server: ['HTTP_USER_AGENT' => self::BROWSER]);
        $this->client->request('GET', '/admin/statistiques', server: ['HTTP_USER_AGENT' => self::BROWSER]);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $this->views());
    }

    public function testVisitorFingerprintChangesEveryDay(): void
    {
        $recorder = static::getContainer()->get(PageViewRecorder::class);
        $request = \Symfony\Component\HttpFoundation\Request::create('/', server: ['HTTP_USER_AGENT' => self::BROWSER, 'REMOTE_ADDR' => '198.51.100.4']);

        $recorder->record($request, new \DateTimeImmutable('2026-03-01 10:00'));
        $recorder->record($request, new \DateTimeImmutable('2026-03-01 18:00'));
        $recorder->record($request, new \DateTimeImmutable('2026-03-02 10:00'));

        $visitors = array_map(static fn (PageView $v): string => $v->getVisitor(), $this->views());
        self::assertSame($visitors[0], $visitors[1], 'Même visiteur dans la journée');
        self::assertNotSame($visitors[0], $visitors[2], 'Empreinte renouvelée le lendemain');
    }

    public function testReportAggregatesAndPurgesOldViews(): void
    {
        $now = new \DateTimeImmutable('today 12:00');
        foreach ([['/', 'a'], ['/', 'a'], ['/', 'b'], ['/contact', 'b']] as [$path, $visitor]) {
            $this->em->persist(new PageView($now, $path, str_repeat($visitor, 16), null, 'mobile', 'Safari', 'fr'));
        }
        $this->em->persist(new PageView($now->modify('-14 months'), '/ancien', str_repeat('c', 16), null, 'mobile', 'Safari', 'fr'));
        $this->em->flush();

        $report = static::getContainer()->get(AnalyticsReport::class);
        self::assertSame(1, $report->purgeExpired($now));

        $data = $report->build(7, $now);
        self::assertSame(4, $data['views']);
        self::assertSame(2, $data['visitors']);
        self::assertSame('/', $data['pages'][0]['label']);
        self::assertSame(3, $data['pages'][0]['views']);
        self::assertCount(7, $data['chart']['bars']);
        self::assertSame(100.0, $data['devices'][0]['share']);
    }

    /** @return list<PageView> */
    private function views(): array
    {
        $this->em->clear();

        return $this->em->getRepository(PageView::class)->findBy([], ['id' => 'ASC']);
    }
}
