<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\Document;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Suppression d'un compte depuis le back-office (droit à l'effacement, comptes de test…).
 *
 * Garde-fous : on ne supprime ni son propre compte ni un compte administrateur, pour ne jamais
 * perdre l'accès à l'administration. Les articles écrits par le compte sont conservés, sans auteur.
 */
final class UserRemover
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** Raison du refus, ou null si la suppression est possible. */
    public function refusal(User $target, ?User $currentUser): ?string
    {
        if ($currentUser !== null && $target->getId() === $currentUser->getId()) {
            return 'Vous ne pouvez pas supprimer votre propre compte.';
        }
        if (in_array('ROLE_ADMIN', $target->getRoles(), true)) {
            return 'Un compte administrateur ne peut pas être supprimé depuis cette page.';
        }
        if ($target->getStripeSubscriptionId() !== null) {
            return 'Ce compte a un abonnement Stripe en cours : résiliez-le d\'abord dans Stripe, sinon il continuerait d\'être facturé.';
        }

        return null;
    }

    public function remove(User $user): void
    {
        $this->em->wrapInTransaction(function () use ($user): void {
            $this->em->createQueryBuilder()
                ->update(Document::class, 'd')
                ->set('d.author', ':aucun')
                ->where('d.author = :auteur')
                ->setParameter('aucun', null)
                ->setParameter('auteur', $user)
                ->getQuery()
                ->execute();
            $this->em->remove($user);
        });
    }
}
