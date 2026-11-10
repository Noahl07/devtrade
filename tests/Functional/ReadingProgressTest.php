<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Document;
use App\Entity\DocumentCategory;
use App\Entity\ReadingProgress;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Progression de lecture (ouvert, terminé, quiz) et recherche dans la documentation.
 */
final class ReadingProgressTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $suffix;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->suffix = bin2hex(random_bytes(4));
    }

    public function testOpeningThenFinishingAnArticleIsRecordedAndShownInTheModule(): void
    {
        $category = $this->category();
        $doc = $this->document($category, 'lecture', 'free');
        $user = $this->user(false);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', "/documentation/article/free/{$doc->getSlug()}");
        self::assertResponseIsSuccessful();
        $token = (string) $crawler->filter('[data-progress-token]')->attr('data-progress-token');
        self::assertNotNull($this->progress($user, $doc), 'L\'ouverture est enregistrée.');

        // Reprise proposée sur l'index tant que l'article n'est pas terminé.
        $this->client->request('GET', '/documentation');
        self::assertSelectorTextContains('.resume-card__title', $doc->getTitle());

        $this->client->request('POST', "/documentation/progression/{$doc->getId()}", ['_token' => $token, 'action' => 'lu'], [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();
        self::assertTrue(json_decode((string) $this->client->getResponse()->getContent(), true)['completed']);

        $this->client->request('POST', "/documentation/progression/{$doc->getId()}", ['_token' => $token, 'action' => 'quiz', 'score' => 3, 'total' => 5], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->client->request('POST', "/documentation/progression/{$doc->getId()}", ['_token' => $token, 'action' => 'quiz', 'score' => 2, 'total' => 5], [], ['HTTP_ACCEPT' => 'application/json']);
        $etat = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(3, $etat['quizScore'], 'Le meilleur score est conservé.');

        $this->client->request('GET', "/documentation/categorie/{$category->getSlug()}");
        self::assertSelectorExists('.editorial-list__item.is-done');
        self::assertSelectorTextContains('.module-list__quiz', 'Quiz 3/5');
        self::assertSelectorTextContains('.module-progress', '1 / 1');
    }

    public function testProgressNeedsCsrfLoginAndAccessToTheArticle(): void
    {
        $category = $this->category();
        $premium = $this->document($category, 'reserve', 'premium');
        $free = $this->document($category, 'libre', 'free');

        $this->client->request('POST', "/documentation/progression/{$free->getId()}", ['action' => 'lu']);
        self::assertResponseRedirects();

        $this->client->loginUser($this->user(false));
        $crawler = $this->client->request('GET', "/documentation/article/free/{$free->getSlug()}");
        $token = (string) $crawler->filter('[data-progress-token]')->attr('data-progress-token');

        $this->client->request('POST', "/documentation/progression/{$free->getId()}", ['_token' => 'faux', 'action' => 'lu']);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', "/documentation/progression/{$premium->getId()}", ['_token' => $token, 'action' => 'lu']);
        self::assertResponseStatusCodeSame(403, 'Un membre gratuit ne peut pas noter un article Premium.');

        $this->client->request('POST', "/documentation/progression/{$free->getId()}", ['_token' => $token, 'action' => 'supprimer']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testSearchDoesNotLookInsidePremiumContentWithoutPremiumAccess(): void
    {
        $category = $this->category();
        $mot = 'zorglub' . $this->suffix;
        $this->document($category, 'cache', 'premium', "<p>Le secret $mot est ici.</p>");
        $this->document($category, 'visible', 'free', "<p>On parle de $mot aussi.</p>");

        $this->client->request('GET', '/documentation/recherche?q=' . $mot);
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, '.module-list .editorial-list__item');

        $this->client->loginUser($this->user(true));
        $this->client->request('GET', '/documentation/recherche?q=' . $mot);
        self::assertSelectorCount(2, '.module-list .editorial-list__item');

        // Les jokers SQL sont traités comme du texte.
        $this->client->request('GET', '/documentation/recherche?q=%25%25');
        self::assertResponseIsSuccessful();
    }

    private function progress(User $user, Document $doc): ?ReadingProgress
    {
        return $this->em->getRepository(ReadingProgress::class)->findOneBy(['user' => $user->getId(), 'document' => $doc->getId()]);
    }

    private function user(bool $premium): User
    {
        $user = (new User())->setEmail("lecteur-{$this->suffix}-" . bin2hex(random_bytes(2)) . '@exemple.fr')->setFirstName('Le')->setLastName('Cteur')
            ->setPassword('inutilise')->setRoles([])->setIsPremium($premium)->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function category(): DocumentCategory
    {
        $category = (new DocumentCategory())->setName("Module {$this->suffix}")->setSlug("module-{$this->suffix}");
        $this->em->persist($category);
        $this->em->flush();

        return $category;
    }

    private function document(DocumentCategory $category, string $slug, string $access, string $content = '<p>Texte.</p>'): Document
    {
        $document = (new Document())->setTitle("Titre $slug {$this->suffix}")->setSlug("$slug-{$this->suffix}")->setContent($content)
            ->setCategory($category)->setAccessLevel($access)->setIsPublished(true)->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($document);
        $this->em->flush();
        $category->addDocument($document);

        return $document;
    }
}
