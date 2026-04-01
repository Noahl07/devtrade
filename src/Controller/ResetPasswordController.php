<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class ResetPasswordController extends AbstractController
{
    // ── Étape 1 : formulaire demande de reset ──
    #[Route('/mot-de-passe-oublie', name: 'app_forgot_password', methods: ['GET', 'POST'])]
    public function forgotPassword(
        Request $request,
        UserRepository $userRepo,
        EntityManagerInterface $em,
        MailerInterface $mailer
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        $success = false;
        $error   = null;

        if ($request->isMethod('POST')) {
            $email = trim($request->request->get('email', ''));

            if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Veuillez entrer un email valide.';
            } else {
                $user = $userRepo->findOneBy(['email' => $email]);

                // On répond toujours "succès" pour ne pas révéler les emails
                if ($user) {
                    $token     = bin2hex(random_bytes(32));
                    $expiresAt = new \DateTimeImmutable('+1 hour');

                    $user->setResetToken($token);
                    $user->setResetTokenExpiresAt($expiresAt);
                    $em->flush();

                    $resetUrl = $this->generateUrl(
                        'app_reset_password',
                        ['token' => $token],
                        UrlGeneratorInterface::ABSOLUTE_URL
                    );

                    try {
                        $mail = (new Email())
                            ->from('laurfran3@gmail.com')
                            ->to($user->getEmail())
                            ->subject('[dev.trade] Réinitialisation de votre mot de passe')
                            ->html(
                                '<p>Bonjour ' . htmlspecialchars($user->getFirstName()) . ',</p>'
                                . '<p>Cliquez sur le lien ci-dessous pour réinitialiser votre mot de passe. Ce lien expire dans 1 heure.</p>'
                                . '<p><a href="' . $resetUrl . '">' . $resetUrl . '</a></p>'
                                . '<p>Si vous n\'avez pas demandé cette réinitialisation, ignorez cet email.</p>'
                                . '<p>— dev.trade</p>'
                            );
                        $mailer->send($mail);
                    } catch (\Exception $e) {
                        // Log silencieux — on ne révèle pas les erreurs SMTP
                    }
                }

                $success = true;
            }
        }

        return $this->render('security/forgot_password.html.twig', [
            'success' => $success,
            'error'   => $error,
        ]);
    }

    // ── Étape 2 : formulaire nouveau mot de passe ──
    #[Route('/reinitialisation/{token}', name: 'app_reset_password', methods: ['GET', 'POST'])]
    public function resetPassword(
        string $token,
        Request $request,
        UserRepository $userRepo,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher
    ): Response {
        $user = $userRepo->findOneBy(['resetToken' => $token]);

        // Token invalide ou expiré
        if (!$user || !$user->getResetTokenExpiresAt() || $user->getResetTokenExpiresAt() < new \DateTimeImmutable()) {
            $this->addFlash('danger', 'Ce lien de réinitialisation est invalide ou a expiré.');
            return $this->redirectToRoute('app_forgot_password');
        }

        $error = null;

        if ($request->isMethod('POST')) {
            $password = trim($request->request->get('password', ''));
            $confirm  = trim($request->request->get('confirm', ''));

            if (strlen($password) < 8) {
                $error = 'Le mot de passe doit faire au moins 8 caractères.';
            } elseif ($password !== $confirm) {
                $error = 'Les mots de passe ne correspondent pas.';
            } else {
                $user->setPassword($hasher->hashPassword($user, $password));
                $user->setResetToken(null);
                $user->setResetTokenExpiresAt(null);
                $em->flush();

                $this->addFlash('success', 'Mot de passe mis à jour. Vous pouvez vous connecter.');
                return $this->redirectToRoute('app_login');
            }
        }

        return $this->render('security/reset_password.html.twig', [
            'token' => $token,
            'error' => $error,
        ]);
    }
}