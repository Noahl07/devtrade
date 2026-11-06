<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Document;
use App\Entity\DocumentCategory;
use App\Entity\Project;
use App\Entity\User;
use App\Entity\WaitlistEntry;
use App\Service\DocumentationBundle;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Back-office refondu : accès, rendu, validation, réordonnancement, aperçu, liste d'attente. */
final class AdminTest extends WebTestCase
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

    public function testMembersCannotOpenTheAdmin(): void
    {
        $this->client->loginUser($this->user([]));
        $this->client->request('GET', '/admin');

        self::assertResponseStatusCodeSame(403);
    }

    /** @return iterable<string, array{string}> */
    public static function adminPages(): iterable
    {
        foreach (['/admin', '/admin/categories', '/admin/categories/nouvelle', '/admin/documents', '/admin/documents/nouveau', '/admin/documents/import',
            '/admin/projets', '/admin/projets/nouveau', '/admin/utilisateurs', '/admin/waitlist', '/admin/statistiques', '/admin/statistiques?jours=7'] as $url) {
            yield $url => [$url];
        }
    }

    #[DataProvider('adminPages')]
    public function testAdminPagesRenderWithStrictPolicyAndNoLegacyAssets(string $url): void
    {
        $this->loginAdmin();
        $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('bootstrap-icons', $html);
        self::assertStringNotContainsString('cdnjs', $html);
        self::assertDoesNotMatchRegularExpression('/\son[a-z]+="/i', $html, 'Attribut on*= en ligne');
        self::assertStringContainsString("script-src 'self';", (string) $this->client->getResponse()->headers->get('Content-Security-Policy'));
        $this->client->getCrawler()->filter('script')->each(static fn ($s) => self::assertNotNull($s->attr('src'), "Script en ligne sur $url"));
    }

    public function testDuplicateTitlesGetDistinctSlugsInsteadOfACrash(): void
    {
        $this->loginAdmin();
        $category = $this->category();
        $title = "Même titre {$this->suffix}";

        foreach ([1, 2] as $_) {
            $this->postForm('/admin/documents/nouveau', ['title' => $title, 'category' => (string) $category->getId(), 'accessLevel' => 'free', 'content' => '<p>x</p>']);
            self::assertResponseRedirects('/admin/documents');
        }

        $slugs = array_map(static fn (Document $d) => $d->getSlug(), $this->em->getRepository(Document::class)->findBy(['title' => $title]));
        sort($slugs);
        self::assertSame(["meme-titre-{$this->suffix}", "meme-titre-{$this->suffix}-2"], $slugs);
    }

    public function testDocumentValidationRejectsUnknownAccessLevelAndOverlongTitle(): void
    {
        $this->loginAdmin();
        $category = $this->category();
        $this->postForm('/admin/documents/nouveau', ['title' => str_repeat('a', 201), 'category' => (string) $category->getId(), 'accessLevel' => 'public']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#title-error', '200 caractères maximum');
        self::assertSelectorTextContains('#accessLevel-error', 'non autorisée');
        self::assertSame(0, $this->em->getRepository(Document::class)->count(['category' => $category]));
    }

    public function testEditingASlugToOneAlreadyTakenIsRefused(): void
    {
        $this->loginAdmin();
        $category = $this->category();
        $first = $this->document($category, 'premier');
        $second = $this->document($category, 'second');

        $this->postForm("/admin/documents/{$second->getId()}/modifier", ['title' => 'Second', 'slug' => $first->getSlug(), 'category' => (string) $category->getId(), 'accessLevel' => 'free']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#slug-error', 'déjà utilisée');
    }

    public function testProjectLinksMustBeHttpButImagesMayBeLocal(): void
    {
        $this->loginAdmin();
        $base = ['title' => "Projet {$this->suffix}", 'shortDesc' => 'Résumé', 'description' => '<p>Détail</p>', 'type' => 'saas'];

        $this->postForm('/admin/projets/nouveau', $base + ['projectUrl' => 'javascript:alert(1)']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#projectUrl-error', 'http(s)');

        $this->postForm('/admin/projets/nouveau', $base + ['projectUrl' => 'https://exemple.fr', 'imageUrl' => '/images/projets/demo.jpg', 'isVisible' => '1']);
        self::assertResponseRedirects('/admin/projets');
        $project = $this->em->getRepository(Project::class)->findOneBy(['title' => $base['title']]);
        self::assertSame('/images/projets/demo.jpg', $project->getImageUrl());
    }

    public function testReorderNeedsCsrfAndRefusesMixingModules(): void
    {
        $this->loginAdmin();
        $category = $this->category();
        $a = $this->document($category, 'a');
        $b = $this->document($category, 'b');
        $other = $this->document($this->category('autre'), 'c');

        $this->client->request('POST', '/admin/documents/reorder', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['ids' => [$b->getId(), $a->getId()]]));
        self::assertResponseStatusCodeSame(403);

        $token = $this->adminToken();
        $this->client->request('POST', '/admin/documents/reorder', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], content: json_encode(['ids' => [$a->getId(), $other->getId()]]));
        self::assertResponseStatusCodeSame(400);

        $this->client->request('POST', '/admin/documents/reorder', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], content: json_encode(['ids' => [$b->getId(), $a->getId()]]));
        self::assertResponseIsSuccessful();
        $this->em->clear();
        self::assertSame(1, $this->em->find(Document::class, $b->getId())->getPosition());
        self::assertSame(2, $this->em->find(Document::class, $a->getId())->getPosition());
    }

    public function testPreviewReturnsSanitizedHtml(): void
    {
        $this->loginAdmin();
        $this->client->request('POST', '/admin/documents/apercu', ['content' => '<h2>Titre</h2><script>alert(1)</script><a href="javascript:alert(2)">x</a>'], server: ['HTTP_X_CSRF_TOKEN' => $this->adminToken()]);

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<h2>Titre</h2>', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('javascript:', $html);
    }

    public function testWaitlistExportNeutralisesFormulasAndEntriesCanBeErased(): void
    {
        $this->loginAdmin();
        $entry = (new WaitlistEntry())->setEmail("liste-{$this->suffix}@exemple.fr")->setFirstName('=HYPERLINK("http://x")')->setSource('test')->setNotified(false)->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($entry);
        $this->em->flush();

        $this->client->request('GET', '/admin/waitlist/export.csv');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/csv', (string) $this->client->getResponse()->headers->get('Content-Type'));
        $csv = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString("'=HYPERLINK", $csv);

        $crawler = $this->client->request('GET', '/admin/waitlist');
        $form = $crawler->filter(sprintf('form[action="/admin/waitlist/%d/supprimer"]', $entry->getId()))->form();
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/waitlist');
        $this->em->clear();
        self::assertNull($this->em->find(WaitlistEntry::class, $entry->getId()));
    }

    // ── Import du lot de documentation ──────────────────────────────────────

    public function testImportShowsThePlanThenAppliesItWithoutDeletingAnything(): void
    {
        $this->loginAdmin();
        $category = $this->category('import');
        $existing = $this->document($category, 'existant');
        $untouched = $this->document($category, 'hors-lot');

        $this->uploadBundle($this->bundleFile($category, $existing));
        self::assertResponseIsSuccessful();
        $page = $this->client->getCrawler();
        self::assertSame('1', trim($page->filter('tr:contains("Articles créés") td')->text()));
        self::assertSame('1', trim($page->filter('tr:contains("Articles mis à jour") td')->text()));
        self::assertStringContainsString('hors-lot', $page->filter('details')->last()->text(), 'Les articles absents du lot sont signalés');

        // Rien n'est écrit avant la confirmation.
        $this->em->clear();
        self::assertSame('existant', $this->em->find(Document::class, $existing->getId())->getTitle());

        $this->client->submit($page->filter('form[action="/admin/documents/import/confirmer"]')->form());
        self::assertResponseRedirects('/admin/documents');
        $this->em->clear();
        $updated = $this->em->find(Document::class, $existing->getId());
        self::assertSame('Titre réécrit', $updated->getTitle());
        self::assertSame(2, $updated->getPosition());
        $created = $this->em->getRepository(Document::class)->findOneBy(['slug' => "nouveau-{$this->suffix}"]);
        self::assertNotNull($created);
        self::assertTrue($created->isPublished());
        self::assertNotNull($this->em->find(Document::class, $untouched->getId()), 'Aucune suppression');
        self::assertSame(7, $this->em->find(DocumentCategory::class, $category->getId())->getPosition());
    }

    public function testImportRequiresCsrfAndRefusesForeignFiles(): void
    {
        $this->loginAdmin();
        $category = $this->category('import');
        $existing = $this->document($category, 'existant');

        $this->client->request('POST', '/admin/documents/import', ['_token' => 'faux'], ['bundle' => $this->bundleFile($category, $existing)]);
        self::assertResponseStatusCodeSame(403);

        $path = sys_get_temp_dir() . "/image-{$this->suffix}.gz";
        file_put_contents($path, "\x89PNG\r\n\x1a\n" . str_repeat('x', 64));
        $this->uploadBundle(new UploadedFile($path, 'image.gz', null, null, true));
        self::assertStringContainsString('Type de fichier refusé', (string) $this->client->getResponse()->getContent());

        $path = sys_get_temp_dir() . "/autre-{$this->suffix}.json";
        file_put_contents($path, '{"format":"autre","version":1}');
        $this->uploadBundle(new UploadedFile($path, 'autre.json', null, null, true));
        self::assertStringContainsString('pas un lot de documentation devtrade', (string) $this->client->getResponse()->getContent());
    }

    public function testImportCannotConfirmAnUploadThatIsNotFromThisSession(): void
    {
        $this->loginAdmin();
        $category = $this->category('import');
        $existing = $this->document($category, 'existant');
        $this->uploadBundle($this->bundleFile($category, $existing));
        $form = $this->client->getCrawler()->filter('form[action="/admin/documents/import/confirmer"]')->form();

        // Jeton d'envoi falsifié (un autre fichier déposé sur le serveur, par exemple) : refusé.
        $this->client->request('POST', '/admin/documents/import/confirmer', ['_token' => $form['_token']->getValue(), 'token' => str_repeat('a', 32)]);
        self::assertResponseRedirects('/admin/documents/import');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash', 'Envoi inconnu ou expiré');
        $this->em->clear();
        self::assertSame('existant', $this->em->find(Document::class, $existing->getId())->getTitle());
    }

    /** Lot de test : met à jour un article existant et en crée un autre, dans un module existant. */
    private function bundleFile(DocumentCategory $category, Document $existing): UploadedFile
    {
        $module = ['slug' => $category->getSlug(), 'name' => $category->getName(), 'description' => null, 'icon' => null, 'accessLevel' => 'free', 'position' => 7];
        $article = static fn (string $slug, string $title, int $position): array => ['slug' => $slug, 'title' => $title, 'excerpt' => 'Résumé', 'readingTime' => 4,
            'accessLevel' => 'free', 'position' => $position, 'module' => $category->getSlug(), 'content' => "<h2>$title</h2><p>Texte.</p>", 'published' => true];
        $bundle = ['format' => DocumentationBundle::FORMAT, 'version' => DocumentationBundle::VERSION, 'generatedAt' => 'test', 'modules' => [$module],
            'articles' => [$article((string) $existing->getSlug(), 'Titre réécrit', 2), $article("nouveau-{$this->suffix}", 'Article créé', 1)]];
        $path = sys_get_temp_dir() . "/lot-{$this->suffix}.json.gz";
        file_put_contents($path, gzencode(json_encode($bundle, JSON_THROW_ON_ERROR)));

        return new UploadedFile($path, basename($path), null, null, true);
    }

    private function uploadBundle(UploadedFile $file): void
    {
        $token = (string) $this->client->request('GET', '/admin/documents/import')->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST', '/admin/documents/import', ['_token' => $token], ['bundle' => $file]);
    }

    // ── Outils ──────────────────────────────────────────────────────────────

    /** @param list<string> $roles */
    private function user(array $roles): User
    {
        $user = (new User())->setEmail("admin-{$this->suffix}-" . bin2hex(random_bytes(2)) . '@exemple.fr')->setFirstName('Ad')->setLastName('Min')
            ->setPassword('inutilise')->setRoles($roles)->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function loginAdmin(): void
    {
        $this->client->loginUser($this->user(['ROLE_ADMIN']));
    }

    private function category(string $name = 'module'): DocumentCategory
    {
        $category = (new DocumentCategory())->setName("$name {$this->suffix}")->setSlug("$name-{$this->suffix}");
        $this->em->persist($category);
        $this->em->flush();

        return $category;
    }

    private function document(DocumentCategory $category, string $slug): Document
    {
        $document = (new Document())->setTitle($slug)->setSlug("$slug-{$this->suffix}")->setContent('<p>x</p>')->setCategory($category)->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($document);
        $this->em->flush();

        return $document;
    }

    private function adminToken(): string
    {
        return (string) $this->client->request('GET', '/admin')->filter('[data-admin]')->attr('data-csrf');
    }

    /** @param array<string, string> $fields */
    private function postForm(string $url, array $fields): void
    {
        $token = (string) $this->client->request('GET', '/admin/documents/nouveau')->filter('form.admin-form input[name="_token"]')->attr('value');
        $this->client->request('POST', $url, $fields + ['_token' => $token]);
    }
}
