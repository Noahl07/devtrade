<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Espace membre refondu (Phase 5) : accès, rendu, mise à jour du profil. */
final class AccountPagesTest extends WebTestCase
{
    private const PASSWORD = 'mot-de-passe-actuel';

    public function testAccountRequiresLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/compte');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testAccountPagesRenderWithoutLegacyAssets(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());

        foreach (['/compte', '/compte/profil'] as $url) {
            $client->request('GET', $url);
            self::assertResponseIsSuccessful();
            $html = (string) $client->getResponse()->getContent();
            self::assertStringNotContainsString('/build/app.', $html, $url);
            self::assertStringNotContainsString('bootstrap-icons', $html, $url);
            self::assertStringContainsString('noindex', $html, $url);
        }
        // Plus d'affirmation chiffrée invérifiable ni d'ancre morte
        $client->request('GET', '/compte');
        self::assertStringNotContainsString('40+', (string) $client->getResponse()->getContent());
        self::assertSelectorExists('a[href$="/trading#liste-attente"]');
    }

    public function testProfileUpdateKeepsPasswordExactlyAsTyped(): void
    {
        $client = static::createClient();
        $user = $this->createUser();
        $client->loginUser($user);

        // Espaces en fin de mot de passe : ils font partie du mot de passe.
        $this->submitProfile($client, ['firstName' => 'Jeanne', 'lastName' => 'Martin', 'currentPassword' => self::PASSWORD, 'newPassword' => 'nouveau mdp  ', 'confirmPassword' => 'nouveau mdp  ']);
        self::assertResponseRedirects('/compte');

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $fresh = $this->reload($user);
        self::assertSame('Jeanne', $fresh->getFirstName());
        self::assertTrue($hasher->isPasswordValid($fresh, 'nouveau mdp  '));
        self::assertFalse($hasher->isPasswordValid($fresh, 'nouveau mdp'));
    }

    public function testProfileErrorsSaveNothing(): void
    {
        $client = static::createClient();
        $user = $this->createUser();
        $client->loginUser($user);

        $this->submitProfile($client, ['firstName' => str_repeat('a', 81), 'lastName' => 'Martin', 'currentPassword' => 'faux', 'newPassword' => 'nouveau-mdp', 'confirmPassword' => 'nouveau-mdp']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#firstName-error', 'Entre 2 et 80');
        self::assertSelectorTextContains('#currentPassword-error', 'incorrect');
        self::assertSame('Membre', $this->reload($user)->getFirstName());
    }

    public function testProfileRejectsMissingCsrfToken(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $client->request('POST', '/compte/profil', ['firstName' => 'Pirate', 'lastName' => 'Test']);

        self::assertResponseStatusCodeSame(403);
    }

    private function createUser(): User
    {
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        $user = (new User())
            ->setEmail('compte-' . bin2hex(random_bytes(4)) . '@exemple.fr')
            ->setFirstName('Membre')
            ->setLastName('Test')
            ->setCreatedAt(new \DateTimeImmutable());
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function reload(User $user): User
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->getRepository(User::class)->find($user->getId());
    }

    /** @param array<string, string> $fields */
    private function submitProfile(KernelBrowser $client, array $fields): void
    {
        $crawler = $client->request('GET', '/compte/profil');
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');
        $client->request('POST', '/compte/profil', $fields + ['_token' => $token]);
    }
}
