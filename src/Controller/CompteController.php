<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\ProfileUpdater;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Espace membre. Routes déclarées dans config/routes.yaml (app_compte, app_compte_profil). */
#[IsGranted('ROLE_USER')]
final class CompteController extends AbstractController
{
    public function index(): Response
    {
        return $this->render('compte/index.html.twig', [
            'user' => $this->getUser(),
        ]);
    }

    public function profil(Request $request, ProfileUpdater $updater): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $errors = [];
        $values = ['firstName' => (string) $user->getFirstName(), 'lastName' => (string) $user->getLastName()];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('profil', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }
            $values = [
                'firstName' => (string) $request->request->get('firstName', ''),
                'lastName' => (string) $request->request->get('lastName', ''),
            ];
            $errors = $updater->update(
                $user,
                $values['firstName'],
                $values['lastName'],
                (string) $request->request->get('currentPassword', ''),
                (string) $request->request->get('newPassword', ''),
                (string) $request->request->get('confirmPassword', ''),
            );

            if ($errors === []) {
                $this->addFlash('success', 'Profil mis à jour.');

                return $this->redirectToRoute('app_compte');
            }
        }

        return $this->render('compte/profil.html.twig', [
            'user' => $user,
            'values' => $values,
            'errors' => $errors,
        ], new Response(status: $errors === [] ? 200 : 422));
    }
}
