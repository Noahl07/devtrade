<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Mime\Email;

/**
 * Envoi d'un message du formulaire de contact. Rien n'est stocké en base : le message part par e-mail.
 */
final class ContactMessenger
{
    public const SUBJECTS = [
        'Demande de devis : développement',
        'Question sur Shiro',
        'Accès Premium à la documentation',
        'Partenariat',
        'Autre',
    ];

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly string $sender = 'contact@devtrade.fr',
        private readonly string $recipient = 'contact@devtrade.fr',
        private readonly string $publicAddress = 'contact@devtrade.fr',
    ) {
    }

    /**
     * @return string|null message d'erreur à afficher, ou null si le message est parti
     */
    public function send(string $name, string $email, string $subject, string $message): ?string
    {
        // Pas de retour à la ligne dans les champs qui finissent dans des en-têtes.
        $name = (string) preg_replace('/[\r\n]+/', ' ', mb_substr(trim($name), 0, 100));
        $email = trim($email);
        $subject = in_array($subject, self::SUBJECTS, true) ? $subject : '';
        $message = mb_substr(trim($message), 0, 5000);

        if ($name === '' || $email === '' || $message === '') {
            return 'Veuillez remplir tous les champs obligatoires.';
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 'Adresse e-mail invalide.';
        }

        $mail = (new Email())
            ->from($this->sender)
            ->replyTo($email)
            ->to($this->recipient)
            ->subject('[dev.trade] ' . ($subject !== '' ? $subject : 'Nouveau message de ' . $name))
            ->text("Nom : $name\nE-mail : $email\nSujet : " . ($subject !== '' ? $subject : '(aucun)') . "\n\nMessage :\n$message");

        try {
            $this->mailer->send($mail);
        } catch (TransportExceptionInterface|HandlerFailedException|\RuntimeException $e) {
            // HandlerFailedException : envoi passé par Messenger (transport synchrone) qui a échoué.
            // Journalisé sans le contenu du message (données personnelles).
            $this->logger->error('Échec d\'envoi du formulaire de contact', ['exception' => $e::class]);

            return "L'envoi a échoué. Réessayez dans un instant ou écrivez directement à {$this->publicAddress}.";
        }

        return null;
    }
}
