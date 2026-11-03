<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\WaitlistEntry;
use App\Repository\WaitlistEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Inscription à la liste d'attente avec consentement explicite (RGPD).
 * Réponse volontairement identique qu'une adresse soit déjà inscrite ou non (pas d'énumération).
 */
final class WaitlistRegistration
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WaitlistEntryRepository $repository,
        private readonly ValidatorInterface $validator,
    ) {
    }

    /**
     * @return string|null message d'erreur à afficher, ou null si l'inscription est acceptée
     */
    public function register(string $email, ?string $firstName, bool $consent, string $source): ?string
    {
        $email = mb_strtolower(trim($email));
        $firstName = $firstName !== null ? mb_substr(trim($firstName), 0, 80) : null;

        if (!$consent) {
            return 'Merci de cocher la case de consentement.';
        }
        if (count($this->validator->validate($email, [new Email(), new Length(max: 180)])) > 0) {
            return 'Adresse e-mail invalide.';
        }

        if ($this->repository->findOneBy(['email' => $email]) === null) {
            $now = new \DateTimeImmutable();
            $entry = (new WaitlistEntry())
                ->setEmail($email)
                ->setFirstName($firstName !== '' ? $firstName : null)
                ->setSource(mb_substr($source, 0, 50))
                ->setNotified(false)
                ->setCreatedAt($now)
                ->setConsentAt($now);

            $this->em->persist($entry);
            $this->em->flush();
        }

        return null;
    }
}
