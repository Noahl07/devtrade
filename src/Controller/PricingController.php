<?php

namespace App\Controller;

use App\Service\Billing\StripeBilling;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PricingController extends AbstractController
{
    public function __construct(
        private readonly StripeBilling $stripe,
        private readonly ?string $priceLabel = null,
    ) {
    }

    #[Route('/premium', name: 'app_pricing', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('pricing/index.html.twig', [
            'online_payment' => $this->stripe->isEnabled(),
            'price_label' => $this->priceLabel !== null && $this->priceLabel !== '' ? $this->priceLabel : null,
        ]);
    }
}
