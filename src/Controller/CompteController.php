<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class CompteController extends AbstractController
{
    #[Route('/compte', name: 'app_compte', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('compte/index.html.twig', [
            'user' => $this->getUser(),
        ]);
    }

    #[Route('/compte/profil', name: 'app_compte_profil', methods: ['GET', 'POST'])]
    public function profil(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher
    ): Response {
        /** @var User $user */
        $user  = $this->getUser();
        $error = null;

        if ($request->isMethod('POST')) {
            $firstName   = trim($request->request->get('firstName', ''));
            $lastName    = trim($request->request->get('lastName', ''));
            $newPassword = trim($request->request->get('newPassword', ''));
            $confirm     = trim($request->request->get('confirmPassword', ''));

            if ($firstName) $user->setFirstName($firstName);
            if ($lastName)  $user->setLastName($lastName);

            if ($newPassword) {
                if (strlen($newPassword) < 8) {
                    $error = 'Le mot de passe doit faire au moins 8 caractères.';
                } elseif ($newPassword !== $confirm) {
                    $error = 'Les mots de passe ne correspondent pas.';
                } else {
                    $user->setPassword($hasher->hashPassword($user, $newPassword));
                }
            }

            if (!$error) {
                $em->flush();
                $this->addFlash('success', 'Profil mis à jour.');
                return $this->redirectToRoute('app_compte');
            }
        }

        return $this->render('compte/profil.html.twig', [
            'user'  => $user,
            'error' => $error,
        ]);
    }
}