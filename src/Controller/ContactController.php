<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ContactMessenger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ContactController extends AbstractController
{
    #[Route('/contact', name: 'app_contact', methods: ['GET', 'POST'])]
    public function index(Request $request, ContactMessenger $messenger): Response
    {
        $error = null;
        $success = false;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('contact', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            // Piège à robots : champ invisible pour un humain. On affiche « envoyé » sans rien envoyer.
            if ((string) $request->request->get('website') !== '') {
                $success = true;
            } else {
                $error = $messenger->send(
                    (string) $request->request->get('name'),
                    (string) $request->request->get('email'),
                    (string) $request->request->get('subject'),
                    (string) $request->request->get('message'),
                );
                $success = $error === null;
            }
        }

        return $this->render('contact/index.html.twig', [
            'error'    => $error,
            'success'  => $success,
            'subjects' => ContactMessenger::SUBJECTS,
            // Valeurs saisies, réaffichées en cas d'erreur
            'values'   => $success ? [] : array_map(
                static fn (mixed $v): string => is_string($v) ? $v : '',
                $request->request->all(),
            ),
        ]);
    }
}
