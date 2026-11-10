<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Admin\AdminLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Abonnement Premium via Stripe Checkout, sans SDK : appels HTTPS directs à l'API Stripe.
 *
 * Désactivé tant que STRIPE_SECRET_KEY, STRIPE_PRICE_ID et STRIPE_WEBHOOK_SECRET ne sont pas définis
 * (variables Railway). L'accès Premium n'est donné que par un événement signé reçu sur le webhook,
 * jamais par le simple retour du navigateur sur la page de remerciement.
 */
final class StripeBilling
{
    private const API = 'https://api.stripe.com/v1/';
    private const TOLERANCE = 300;
    private const ACTIVE = ['active', 'trialing'];
    private const ENDED = ['canceled', 'unpaid', 'incomplete_expired'];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly AdminLogger $journal,
        private readonly ?string $secretKey,
        private readonly ?string $priceId,
        private readonly ?string $webhookSecret,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->secretKey !== null && $this->secretKey !== ''
            && $this->priceId !== null && $this->priceId !== ''
            && $this->webhookSecret !== null && $this->webhookSecret !== '';
    }

    /** URL de la page de paiement Stripe pour ce membre. */
    public function checkoutUrl(User $user, string $successUrl, string $cancelUrl): string
    {
        $fields = [
            'mode' => 'subscription',
            'line_items' => [['price' => $this->priceId, 'quantity' => 1]],
            'client_reference_id' => (string) $user->getId(),
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'locale' => 'fr',
            'allow_promotion_codes' => 'true',
            'metadata' => ['user_id' => (string) $user->getId()],
            'subscription_data' => ['metadata' => ['user_id' => (string) $user->getId()]],
        ];
        if ($user->getStripeCustomerId() !== null) {
            $fields['customer'] = $user->getStripeCustomerId();
        } else {
            $fields['customer_email'] = $user->getEmail();
        }

        return $this->post('checkout/sessions', $fields)['url'];
    }

    /** URL du portail client Stripe (factures, carte, résiliation). */
    public function portalUrl(User $user, string $returnUrl): string
    {
        $customer = $user->getStripeCustomerId() ?? throw new StripeException('Aucun abonnement associé à ce compte.');

        return $this->post('billing_portal/sessions', ['customer' => $customer, 'return_url' => $returnUrl])['url'];
    }

    /**
     * Vérifie la signature d'un appel webhook (en-tête Stripe-Signature) et renvoie l'événement.
     *
     * @return array<string, mixed>
     */
    public function verify(string $payload, string $signatureHeader, ?int $now = null): array
    {
        if (!$this->isEnabled()) {
            throw new StripeException('Paiement en ligne désactivé.');
        }
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $signatureHeader) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }
        if ($timestamp === null || $signatures === []) {
            throw new StripeException('Signature absente.');
        }
        if (abs(($now ?? time()) - $timestamp) > self::TOLERANCE) {
            throw new StripeException('Signature expirée.');
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, (string) $this->webhookSecret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                $event = json_decode($payload, true);
                if (!is_array($event) || !isset($event['type'], $event['data']['object'])) {
                    throw new StripeException('Événement illisible.');
                }

                return $event;
            }
        }
        throw new StripeException('Signature invalide.');
    }

    /**
     * Applique un événement Stripe vérifié. Idempotent : Stripe peut renvoyer le même événement.
     *
     * @param array<string, mixed> $event
     */
    public function handle(array $event): void
    {
        $object = $event['data']['object'];

        switch ($event['type']) {
            case 'checkout.session.completed':
                if (($object['mode'] ?? null) !== 'subscription' || !in_array($object['payment_status'] ?? null, ['paid', 'no_payment_required'], true)) {
                    return;
                }
                $user = $this->users->find((int) ($object['client_reference_id'] ?? 0));
                if ($user === null) {
                    return;
                }
                $user->setStripeCustomerId(is_string($object['customer'] ?? null) ? $object['customer'] : $user->getStripeCustomerId());
                $user->setStripeSubscriptionId(is_string($object['subscription'] ?? null) ? $object['subscription'] : null);
                $this->grant($user, true, 'Abonnement Stripe souscrit');
                break;

            case 'customer.subscription.updated':
            case 'customer.subscription.deleted':
                $user = $this->users->findOneBy(['stripeSubscriptionId' => (string) ($object['id'] ?? '')]);
                if ($user === null) {
                    return;
                }
                $status = (string) ($object['status'] ?? '');
                if ($event['type'] === 'customer.subscription.deleted' || in_array($status, self::ENDED, true)) {
                    $user->setStripeSubscriptionId(null);
                    $this->grant($user, false, 'Abonnement Stripe terminé', $status);
                } elseif (in_array($status, self::ACTIVE, true)) {
                    $this->grant($user, true, 'Abonnement Stripe actif', $status);
                }
                // past_due : Stripe relance le paiement ; l'accès est retiré seulement si l'abonnement se termine.
                break;

            case 'invoice.payment_failed':
                $user = $this->users->findOneBy(['stripeCustomerId' => (string) ($object['customer'] ?? '')]);
                if ($user !== null) {
                    $this->journal->log('Paiement Stripe refusé', (string) $user->getEmail(), null, 'stripe');
                }
                break;
        }
    }

    private function grant(User $user, bool $premium, string $action, ?string $details = null): void
    {
        $changed = $user->isPremium() !== $premium;
        $user->setIsPremium($premium);
        $this->em->flush();
        if ($changed) {
            $this->journal->log($action, (string) $user->getEmail(), $details, 'stripe');
        }
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private function post(string $path, array $fields): array
    {
        if (!$this->isEnabled()) {
            throw new StripeException('Paiement en ligne désactivé.');
        }
        try {
            $response = $this->httpClient->request('POST', self::API . $path, [
                'auth_bearer' => $this->secretKey,
                'body' => http_build_query($fields),
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            ]);
            $data = $response->toArray(false);
            if ($response->getStatusCode() >= 400 || !isset($data['url'])) {
                throw new StripeException('Stripe a refusé la demande : ' . ($data['error']['message'] ?? 'réponse inattendue'));
            }

            return $data;
        } catch (ExceptionInterface $e) {
            throw new StripeException('Stripe ne répond pas. Réessayez dans un instant.', 0, $e);
        }
    }
}
