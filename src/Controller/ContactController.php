<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;

class ContactController extends AbstractController
{
    #[Route('/contact', name: 'app_contact', methods: ['GET', 'POST'])]
    public function index(Request $request, MailerInterface $mailer): Response
    {
        $error   = null;
        $success = false;

        if ($request->isMethod('POST')) {
            $name    = trim($request->request->get('name', ''));
            $email   = trim($request->request->get('email', ''));
            $subject = trim($request->request->get('subject', ''));
            $message = trim($request->request->get('message', ''));

            if (!$name || !$email || !$message) {
                $error = 'Veuillez remplir tous les champs obligatoires.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Email invalide.';
            } else {
                try {
                    $mail = (new Email())
                        ->from($email)
                        ->to('contact@devtrade.fr')
                        ->subject('[dev.trade] ' . ($subject ?: 'Nouveau message de ' . $name))
                        ->text("Nom : $name\nEmail : $email\n\nMessage :\n$message");

                    $mailer->send($mail);
                    $success = true;
                } catch (\Exception $e) {
                    $error = 'Erreur lors de l\'envoi. Réessayez ou contactez directement par email.';
                }
            }
        }

        return $this->render('contact/index.html.twig', [
            'error'   => $error,
            'success' => $success,
        ]);
    }
}