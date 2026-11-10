<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\Billing\StripeBilling;
use App\Service\Billing\StripeException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Abonnement Premium : départ vers Stripe Checkout, portail client, webhook signé.
 */
final class BillingController extends AbstractController
{
    public function __construct(
        private readonly StripeBilling $stripe,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function checkout(Request $request): Response
    {
        if (!$this->stripe->isEnabled()) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('checkout', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
        /** @var User $user */
        $user = $this->getUser();
        if ($user->isPremium()) {
            $this->addFlash('info', 'Votre accès est déjà Premium.');

            return $this->redirectToRoute('app_compte');
        }

        try {
            $url = $this->stripe->checkoutUrl(
                $user,
                $this->generateUrl('app_billing_success', [], UrlGeneratorInterface::ABSOLUTE_URL),
                $this->generateUrl('app_pricing', [], UrlGeneratorInterface::ABSOLUTE_URL),
            );
        } catch (StripeException $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->redirectToRoute('app_pricing');
        }

        return $this->redirect($url, Response::HTTP_SEE_OTHER);
    }

    public function success(): Response
    {
        return $this->render('pricing/success.html.twig');
    }

    public function portal(Request $request): Response
    {
        if (!$this->stripe->isEnabled()) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('billing-portal', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
        /** @var User $user */
        $user = $this->getUser();
        try {
            $url = $this->stripe->portalUrl($user, $this->generateUrl('app_compte', [], UrlGeneratorInterface::ABSOLUTE_URL));
        } catch (StripeException $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->redirectToRoute('app_compte');
        }

        return $this->redirect($url, Response::HTTP_SEE_OTHER);
    }

    /** Appelé par Stripe uniquement : la signature fait foi, pas la session. */
    public function webhook(Request $request): Response
    {
        try {
            $event = $this->stripe->verify($request->getContent(), (string) $request->headers->get('Stripe-Signature'));
        } catch (StripeException $e) {
            $this->logger->warning('Webhook Stripe refusé', ['raison' => $e->getMessage()]);

            return new Response('', Response::HTTP_BAD_REQUEST);
        }
        $this->stripe->handle($event);

        return new Response('', Response::HTTP_OK);
    }
}
