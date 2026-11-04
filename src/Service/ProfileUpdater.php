<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Mise à jour du profil depuis l'espace membre : nom, prénom et, si demandé, mot de passe.
 * Rien n'est enregistré tant qu'une erreur subsiste. Les mots de passe ne sont jamais modifiés (pas de trim).
 */
final class ProfileUpdater
{
    public const NAME_MIN = 2;
    public const NAME_MAX = 80;
    public const PASSWORD_MIN = 8;
    public const PASSWORD_MAX = 128;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    /**
     * @return array<string, string> erreurs par champ (vide si tout est enregistré)
     */
    public function update(User $user, string $firstName, string $lastName, string $currentPassword, string $newPassword, string $confirm): array
    {
        $errors = [];
        $firstName = trim($firstName);
        $lastName = trim($lastName);

        foreach (['firstName' => $firstName, 'lastName' => $lastName] as $field => $value) {
            $length = mb_strlen($value);
            if ($length < self::NAME_MIN || $length > self::NAME_MAX) {
                $errors[$field] = sprintf('Entre %d et %d caractères.', self::NAME_MIN, self::NAME_MAX);
            }
        }

        $hashedPassword = null;
        if ($newPassword !== '' || $confirm !== '') {
            $length = mb_strlen($newPassword);
            if (!$this->hasher->isPasswordValid($user, $currentPassword)) {
                $errors['currentPassword'] = 'Le mot de passe actuel est incorrect.';
            } elseif ($length < self::PASSWORD_MIN || $length > self::PASSWORD_MAX) {
                $errors['newPassword'] = sprintf('Entre %d et %d caractères.', self::PASSWORD_MIN, self::PASSWORD_MAX);
            } elseif ($newPassword !== $confirm) {
                $errors['confirmPassword'] = 'Les mots de passe ne correspondent pas.';
            } else {
                $hashedPassword = $this->hasher->hashPassword($user, $newPassword);
            }
        }

        if ($errors !== []) {
            return $errors;
        }

        $user->setFirstName($firstName)->setLastName($lastName);
        if ($hashedPassword !== null) {
            $user->setPassword($hashedPassword);
        }
        $this->em->flush();

        return [];
    }
}
