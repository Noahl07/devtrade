<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AdminLog;
use App\Entity\TradePost;
use App\Entity\User;
use App\Service\Community\TradeImageProcessor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Salle des trades : accès, publication, modération, réactions, signalements et traitement des images.
 */
final class CommunityTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testVisitorsAreSentToLogin(): void
    {
        foreach (['/communaute', '/communaute/publier', '/communaute/regles'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseRedirects();
            self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
        }
    }

    public function testPublishModerateReactAndReport(): void
    {
        $auteur = $this->user();
        $this->client->loginUser($auteur);
        $this->publish(['instrument' => 'NQ', 'resultR' => '-1', 'lesson' => str_repeat('Stop sous le creux, entrée au retour dans le FVG. ', 2)]);
        self::assertResponseRedirects('/communaute/mes-publications');

        $post = $this->em->getRepository(TradePost::class)->findOneBy(['author' => $auteur->getId()]);
        self::assertNotNull($post);
        self::assertSame(TradePost::EN_ATTENTE, $post->getStatus());
        self::assertTrue($post->isLoss());
        $image = $post->getImages()->first();
        self::assertSame('image/webp', $image->getMimeType());

        // L'auteur voit sa capture ; un autre membre ne voit ni la publication ni l'image avant validation.
        $this->client->request('GET', '/communaute/image/' . $image->getId());
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/webp');
        $lecteur = $this->user();
        $this->client->loginUser($lecteur);
        $this->client->request('GET', '/communaute/image/' . $image->getId());
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/communaute');
        self::assertSelectorNotExists('.trade-card');

        // Validation par un administrateur (jeton CSRF obligatoire).
        $this->client->loginUser($this->user(['ROLE_ADMIN']));
        $crawler = $this->client->request('GET', '/admin/communaute');
        self::assertSelectorTextContains('.moderation-item', 'NQ');
        $this->client->request('POST', '/admin/communaute/' . $post->getId() . '/valider', ['_token' => 'faux']);
        self::assertResponseStatusCodeSame(403);
        $form = $crawler->filter(sprintf('form[action="/admin/communaute/%d/valider"]', $post->getId()))->form();
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/communaute');

        // Réaction : ajout puis retrait.
        $this->client->loginUser($lecteur);
        $crawler = $this->client->request('GET', '/communaute');
        self::assertSelectorTextContains('.trade-card', 'Perte assumée');
        $token = $crawler->filter('form[data-reaction] input[name="_token"]')->attr('value');
        $url = '/communaute/' . $post->getId() . '/reaction';
        $this->client->request('POST', $url, ['_token' => $token, 'emoji' => 'cerveau'], [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertSame(['actif' => true, 'compte' => 1], json_decode((string) $this->client->getResponse()->getContent(), true));
        $this->client->request('POST', $url, ['_token' => $token, 'emoji' => 'cerveau'], [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertSame(['actif' => false, 'compte' => 0], json_decode((string) $this->client->getResponse()->getContent(), true));
        $this->client->request('POST', $url, ['_token' => $token, 'emoji' => 'caca'], [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(404);

        // Trois signalements de membres différents : la publication repasse en modération.
        for ($k = 0; $k < 3; ++$k) {
            $this->client->loginUser($this->user());
            $crawler = $this->client->request('GET', '/communaute/' . $post->getId());
            $this->client->submit($crawler->filter('.trade-report form')->form(['motif' => 'donnees']));
        }
        $this->em->clear();
        self::assertSame(TradePost::EN_ATTENTE, $this->em->find(TradePost::class, $post->getId())->getStatus());
        self::assertNotNull($this->em->getRepository(AdminLog::class)->findOneBy(['action' => 'Publication masquée (signalements)']));
    }

    public function testOnlyTheAuthorCanDeleteAndUploadsAreChecked(): void
    {
        $auteur = $this->user();
        $this->client->loginUser($auteur);

        // Faux PNG (texte) : refusé, rien n'est enregistré.
        $faux = tempnam(sys_get_temp_dir(), 'faux') . '.png';
        file_put_contents($faux, '<?php echo "pas une image";');
        $this->publish(['instrument' => 'ES'], new UploadedFile($faux, 'capture.png', 'image/png', null, true));
        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->em->getRepository(TradePost::class)->findOneBy(['author' => $auteur->getId()]));

        $this->publish(['instrument' => 'ES']);
        $post = $this->em->getRepository(TradePost::class)->findOneBy(['author' => $auteur->getId()]);
        self::assertNotNull($post);

        $this->client->loginUser($this->user());
        $this->client->request('POST', '/communaute/' . $post->getId() . '/supprimer', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($auteur);
        $crawler = $this->client->request('GET', '/communaute/' . $post->getId());
        $this->client->submit($crawler->filter(sprintf('form[action="/communaute/%d/supprimer"]', $post->getId()))->form());
        self::assertResponseRedirects('/communaute/mes-publications');
        $this->em->clear();
        self::assertNull($this->em->find(TradePost::class, $post->getId()));
    }

    public function testImagesAreReencodedWithoutMetadata(): void
    {
        $processor = static::getContainer()->get(TradeImageProcessor::class);
        // JPEG avec un segment EXIF ajouté à la main.
        $img = imagecreatetruecolor(2400, 900);
        ob_start();
        imagejpeg($img, null, 80);
        $jpeg = (string) ob_get_clean();
        $exif = "Exif\0\0" . str_repeat('GPS-SECRET', 20);
        $jpeg = substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif . substr($jpeg, 2);
        $chemin = tempnam(sys_get_temp_dir(), 'exif') . '.jpg';
        file_put_contents($chemin, $jpeg);
        self::assertStringContainsString('GPS-SECRET', (string) file_get_contents($chemin));

        $resultat = $processor->process(new UploadedFile($chemin, 'trade.jpg', 'image/jpeg', null, true));
        self::assertStringNotContainsString('GPS-SECRET', $resultat['data']);
        self::assertStringNotContainsString('Exif', $resultat['data']);
        self::assertSame(1600, $resultat['width'], 'Largeur ramenée à 1600 px.');
        self::assertSame(600, $resultat['height']);
    }

    /** @param array<string, string> $champs */
    private function publish(array $champs, ?UploadedFile $fichier = null): void
    {
        $crawler = $this->client->request('GET', '/communaute/publier');
        $token = $crawler->filter('input[name="trade_post[_token]"]')->attr('value');
        $this->client->request('POST', '/communaute/publier', ['trade_post' => $champs + [
            'timeframe' => '5m',
            'direction' => 'achat',
            'theme' => 'ict',
            'lesson' => 'Entrée au retour dans le FVG après la chasse des plus bas, stop sous la mèche.',
            'rules' => '1',
            '_token' => $token,
        ]], ['trade_post' => ['images' => [$fichier ?? $this->png()]]]);
    }

    private function png(): UploadedFile
    {
        $img = imagecreatetruecolor(400, 240);
        imagefilledrectangle($img, 20, 20, 200, 120, (int) imagecolorallocate($img, 79, 179, 143));
        $chemin = tempnam(sys_get_temp_dir(), 'trade') . '.png';
        imagepng($img, $chemin);

        return new UploadedFile($chemin, 'capture.png', 'image/png', null, true);
    }

    /** @param list<string> $roles */
    private function user(array $roles = []): User
    {
        $user = (new User())->setEmail('membre-' . bin2hex(random_bytes(5)) . '@exemple.fr')->setFirstName('Mem')->setLastName('Bre')
            ->setPassword('inutilise')->setRoles($roles)->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
