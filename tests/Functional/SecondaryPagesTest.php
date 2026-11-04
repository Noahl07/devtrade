<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Document;
use App\Entity\DocumentCategory;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Pages refondues en Phase 4 : développement, documentation, contact, authentification, pages légales.
 */
final class SecondaryPagesTest extends WebTestCase
{
    /** @return iterable<string, array{string}> */
    public static function redesignedPages(): iterable
    {
        yield 'portfolio' => ['/portfolio'];
        yield 'documentation' => ['/documentation'];
        yield 'contact' => ['/contact'];
        yield 'connexion' => ['/login'];
        yield 'inscription' => ['/register'];
        yield 'mot de passe oublié' => ['/mot-de-passe-oublie'];
        yield 'mentions légales' => ['/mentions-legales'];
        yield 'conditions' => ['/conditions-utilisation'];
        yield 'confidentialité' => ['/confidentialite'];
        yield 'risques' => ['/risques'];
        yield 'premium' => ['/premium'];
    }

    #[DataProvider('redesignedPages')]
    public function testRedesignedPageRendersWithoutLegacyAssets(string $url): void
    {
        $client = static::createClient();
        $client->request('GET', $url);

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('/build/app.css', $html);
        self::assertStringNotContainsString('bootstrap-icons', $html);
        self::assertSelectorExists('h1');
    }

    /** Pas de faux compteur, d'urgence artificielle ni de fausse preuve sociale (PROMPT-REFONTE §2.4). */
    public function testPricingPageHasNoDarkPatterns(): void
    {
        $client = static::createClient();
        $client->request('GET', '/premium');
        $text = $client->getCrawler()->filter('body')->text();

        foreach (['Early Bird', 'plus populaire', 'limitée', 'limité dans le temps', '40 articles', '55 articles', 'temps réel'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $text, $forbidden);
        }
    }

    public function testFooterLinksToEveryLegalPage(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        foreach (['/mentions-legales', '/conditions-utilisation', '/confidentialite', '/risques'] as $url) {
            self::assertSelectorExists(sprintf('footer.site-footer a[href="%s"]', $url));
        }
    }

    public function testLegalNoticeNeverInventsIdentifiers(): void
    {
        $client = static::createClient();
        $client->request('GET', '/mentions-legales');
        $text = $client->getCrawler()->filter('body')->text();

        // Tant que l'éditeur n'a rien renseigné : champs visiblement à compléter, aucun numéro inventé.
        self::assertStringContainsString('À compléter', $text);
        self::assertDoesNotMatchRegularExpression('/\b\d{3} ?\d{3} ?\d{3} ?\d{5}\b/', $text, 'Aucun SIRET ne doit être inventé.');
    }

    public function testContactSendsOneEmailWithReplyTo(): void
    {
        $client = static::createClient();
        $this->postContact($client, ['name' => 'Jeanne', 'email' => 'jeanne@exemple.fr', 'message' => 'Bonjour, un projet.']);

        self::assertResponseIsSuccessful();
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailAddressContains($email, 'Reply-To', 'jeanne@exemple.fr');
        self::assertSelectorTextContains('.contact__success', 'Message envoyé');
    }

    public function testContactHoneypotSendsNothing(): void
    {
        $client = static::createClient();
        $this->postContact($client, ['name' => 'Robot', 'email' => 'robot@exemple.fr', 'message' => 'Spam', 'website' => 'http://spam.example']);

        self::assertResponseIsSuccessful();
        self::assertEmailCount(0);
    }

    public function testContactKeepsValuesOnError(): void
    {
        $client = static::createClient();
        $this->postContact($client, ['name' => 'Jeanne', 'email' => 'pas-une-adresse', 'message' => 'Mon message']);

        self::assertEmailCount(0);
        self::assertSelectorTextContains('.notice--risk', 'Adresse e-mail invalide');
        self::assertSelectorTextContains('textarea[name="message"]', 'Mon message');
    }

    public function testLoginErrorIsGeneric(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->filter('form.auth-form')->form([
            '_username' => 'inconnu-' . bin2hex(random_bytes(3)) . '@exemple.fr',
            '_password' => 'mauvais-mot-de-passe',
        ]));
        $client->followRedirect();

        self::assertSelectorTextContains('.notice--risk', 'Identifiants incorrects');
    }

    public function testMemberCanReadFreeArticleWithGeneratedTableOfContents(): void
    {
        $client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(4));

        $user = (new User())
            ->setEmail("membre-$suffix@exemple.fr")
            ->setFirstName('Membre')
            ->setLastName('Test')
            ->setPassword('inutilise')
            ->setCreatedAt(new \DateTimeImmutable());
        $category = (new DocumentCategory())->setName("Module $suffix")->setSlug("module-$suffix");
        $document = (new Document())
            ->setTitle("Article $suffix")
            ->setSlug("article-$suffix")
            ->setContent('<h2>Première partie</h2><p>Texte.</p><h2>Seconde partie</h2><p>Suite.</p><script>alert(1)</script>')
            ->setIsPublished(true)
            ->setCreatedAt(new \DateTimeImmutable())
            ->setCategory($category);
        $em->persist($user);
        $em->persist($category);
        $em->persist($document);
        $em->flush();

        $client->loginUser($user);
        $client->request('GET', "/documentation/article/free/article-$suffix");

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', "Article $suffix");
        self::assertSelectorExists('[data-reading-body] h2');
        self::assertSelectorExists('[data-toc]');
        // Le contenu de l'article passe par le filtre de nettoyage.
        self::assertStringNotContainsString('<script>alert(1)</script>', (string) $client->getResponse()->getContent());
    }

    /** @param array<string, string> $fields */
    private function postContact(KernelBrowser $client, array $fields): void
    {
        $crawler = $client->request('GET', '/contact');
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        $client->request('POST', '/contact', $fields + ['_token' => (string) $token]);
    }
}
