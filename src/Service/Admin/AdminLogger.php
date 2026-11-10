<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\AdminLog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Inscrit une action d'administration au journal (auteur pris dans la session).
 */
final class AdminLogger
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    public function log(string $action, string $target, ?string $details = null, ?string $actor = null): void
    {
        $actor ??= $this->security->getUser()?->getUserIdentifier() ?? 'système';
        $this->em->persist(new AdminLog(
            mb_substr($actor, 0, 180),
            mb_substr($action, 0, 60),
            mb_substr($target, 0, 255),
            $details !== null ? mb_substr($details, 0, 255) : null,
        ));
        $this->em->flush();
    }
}
