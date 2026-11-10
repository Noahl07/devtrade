<?php

declare(strict_types=1);

namespace App\Service\Billing;

/** Erreur liée au paiement Stripe ; le message peut être montré au membre. */
final class StripeException extends \RuntimeException
{
}
