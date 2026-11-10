<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AdminLog;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Admin\AdminLogger;
use App\Service\Billing\StripeBilling;
use App\Service\Billing\StripeException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Abonnement Stripe : signature des webhooks, activation et fin de l'accès Premium, page de paiement.
 * Aucun appel réel à Stripe : les webhooks sont signés ici avec le secret factice de l'environnement de test.
 */
final class BillingTest extends WebTestCase
{
    private const SECRET = 'whsec_factice';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testUnsignedOrForgedWebhooksAreRefused(): void
    {
        $user = $this->user();
        $payload = $this->checkoutCompleted($user);

        $this->client->request('POST', '/stripe/webhook', [], [], ['CONTENT_TYPE' => 'application/json'], $payload);
        self::assertResponseStatusCodeSame(400);

        $this->postWebhook($payload, hash_hmac('sha256', time() . '.' . $payload, 'mauvais_secret'));
        self::assertResponseStatusCodeSame(400);

        // Signature valide mais trop ancienne (rejeu)
        $old = time() - 3600;
        $this->postWebhook($payload, hash_hmac('sha256', $old . '.' . $payload, self::SECRET), $old);
        self::assertResponseStatusCodeSame(400);

        self::assertFalse($this->reload($user)->isPremium());
    }

    public function testSubscriptionLifecycleGrantsThenRemovesPremium(): void
    {
        $user = $this->user();

        $this->signedWebhook($this->checkoutCompleted($user));
        self::assertResponseIsSuccessful();
        $user = $this->reload($user);
        self::assertTrue($user->isPremium());
        self::assertSame('cus_test', $user->getStripeCustomerId());
        self::assertSame('sub_' . $user->getId(), $user->getStripeSubscriptionId());

        // Même événement renvoyé par Stripe : sans effet de bord
        $this->signedWebhook($this->checkoutCompleted($user));
        self::assertResponseIsSuccessful();

        // Paiement en retard : l'accès reste, Stripe relance
        $this->signedWebhook($this->subscriptionEvent('customer.subscription.updated', $user, 'past_due'));
        self::assertTrue($this->reload($user)->isPremium());

        $this->signedWebhook($this->subscriptionEvent('customer.subscription.deleted', $user, 'canceled'));
        $user = $this->reload($user);
        self::assertFalse($user->isPremium());
        self::assertNull($user->getStripeSubscriptionId());

        $logs = $this->em->getRepository(AdminLog::class)->findBy(['target' => $user->getEmail()]);
        self::assertSame(['Abonnement Stripe souscrit', 'Abonnement Stripe terminé'], array_map(fn (AdminLog $l) => $l->getAction(), $logs));
    }

    public function testUnpaidCheckoutDoesNotGrantPremium(): void
    {
        $user = $this->user();
        $this->signedWebhook($this->checkoutCompleted($user, 'unpaid'));
        self::assertResponseIsSuccessful();
        self::assertFalse($this->reload($user)->isPremium());
    }

    public function testPricingPageOffersCheckoutAndCheckoutNeedsCsrf(): void
    {
        $this->client->loginUser($this->user());
        $this->client->request('GET', '/premium');
        self::assertSelectorExists('form[action="/premium/paiement"] button[type="submit"]');

        $this->client->request('POST', '/premium/paiement', ['_token' => 'faux']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testCheckoutSessionIsCreatedForTheMemberWithoutRealCall(): void
    {
        $sent = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent = [$method, $url, $options['body'] ?? ''];

            return new MockResponse(json_encode(['url' => 'https://checkout.stripe.com/c/pay/cs_test']), ['http_code' => 200]);
        });
        $stripe = $this->billing($http);
        $user = $this->user();

        self::assertSame('https://checkout.stripe.com/c/pay/cs_test', $stripe->checkoutUrl($user, 'https://dev.trade/premium/merci', 'https://dev.trade/premium'));
        self::assertSame('POST', $sent[0]);
        self::assertSame('https://api.stripe.com/v1/checkout/sessions', $sent[1]);
        parse_str($sent[2], $body);
        self::assertSame('subscription', $body['mode']);
        self::assertSame((string) $user->getId(), $body['client_reference_id']);
        self::assertSame('price_factice', $body['line_items'][0]['price']);
        self::assertSame($user->getEmail(), $body['customer_email']);

        $erreur = $this->billing(new MockHttpClient(new MockResponse(json_encode(['error' => ['message' => 'No such price']]), ['http_code' => 400])));
        $this->expectException(StripeException::class);
        $erreur->checkoutUrl($user, 'https://a', 'https://b');
    }

    public function testMemberWithActiveSubscriptionCannotBeDeletedFromAdmin(): void
    {
        $user = $this->user();
        $user->setStripeSubscriptionId('sub_actif');
        $this->em->flush();
        $remover = static::getContainer()->get(\App\Service\Admin\UserRemover::class);
        self::assertStringContainsString('abonnement Stripe', (string) $remover->refusal($user, null));
    }

    private function billing(MockHttpClient $http): StripeBilling
    {
        $c = static::getContainer();

        return new StripeBilling($http, $c->get(UserRepository::class), $this->em, $c->get(AdminLogger::class), 'sk_test_factice', 'price_factice', self::SECRET);
    }

    private function signedWebhook(string $payload): void
    {
        $t = time();
        $this->postWebhook($payload, hash_hmac('sha256', $t . '.' . $payload, self::SECRET), $t);
    }

    private function postWebhook(string $payload, string $signature, ?int $t = null): void
    {
        $this->client->request('POST', '/stripe/webhook', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => sprintf('t=%d,v1=%s', $t ?? time(), $signature),
        ], $payload);
    }

    private function checkoutCompleted(User $user, string $paymentStatus = 'paid'): string
    {
        return (string) json_encode(['id' => 'evt_1', 'type' => 'checkout.session.completed', 'data' => ['object' => [
            'mode' => 'subscription', 'payment_status' => $paymentStatus, 'client_reference_id' => (string) $user->getId(),
            'customer' => 'cus_test', 'subscription' => 'sub_' . $user->getId(),
        ]]]);
    }

    private function subscriptionEvent(string $type, User $user, string $status): string
    {
        return (string) json_encode(['id' => 'evt_2', 'type' => $type, 'data' => ['object' => ['id' => 'sub_' . $user->getId(), 'status' => $status]]]);
    }

    private function reload(User $user): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->find(User::class, $user->getId());
    }

    private function user(): User
    {
        $user = (new User())->setEmail('abonne-' . bin2hex(random_bytes(5)) . '@exemple.fr')->setFirstName('Abo')->setLastName('Nne')
            ->setPassword('inutilise')->setRoles([])->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
