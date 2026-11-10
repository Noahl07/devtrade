<?php

declare(strict_types=1);

namespace App\Mailer;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Envoi par l'API Gmail (HTTPS), sans SMTP : jeton d'accès obtenu à partir d'un jeton de rafraîchissement OAuth,
 * puis message MIME complet envoyé à users/me/messages/send. Portée OAuth nécessaire : gmail.send.
 */
final class GmailApiTransport extends AbstractTransport
{
    private ?string $accessToken = null;
    private int $expiresAt = 0;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $refreshToken,
    ) {
        parent::__construct();
    }

    public function __toString(): string
    {
        return 'gmail+api://default';
    }

    protected function doSend(SentMessage $message): void
    {
        $raw = rtrim(strtr(base64_encode($message->toString()), '+/', '-_'), '=');
        try {
            $response = $this->http->request('POST', 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send', [
                'auth_bearer' => $this->token(),
                'json' => ['raw' => $raw],
            ]);
            if ($response->getStatusCode() >= 300) {
                throw new TransportException(sprintf('API Gmail : envoi refusé (HTTP %d).', $response->getStatusCode()));
            }
            $id = $response->toArray(false)['id'] ?? null;
            if (is_string($id)) {
                $message->setMessageId($id);
            }
        } catch (ExceptionInterface $e) {
            throw new TransportException('API Gmail injoignable.', 0, $e);
        }
    }

    private function token(): string
    {
        if ($this->accessToken !== null && time() < $this->expiresAt - 60) {
            return $this->accessToken;
        }
        if ($this->clientId === '' || $this->clientSecret === '' || $this->refreshToken === '') {
            throw new TransportException('API Gmail : identifiants OAuth absents (GMAIL_CLIENT_ID, GMAIL_CLIENT_SECRET, GMAIL_REFRESH_TOKEN).');
        }
        try {
            $data = $this->http->request('POST', 'https://oauth2.googleapis.com/token', [
                'body' => [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'refresh_token' => $this->refreshToken,
                    'grant_type' => 'refresh_token',
                ],
            ])->toArray(false);
        } catch (ExceptionInterface $e) {
            throw new TransportException('API Gmail : jeton OAuth indisponible.', 0, $e);
        }
        if (!isset($data['access_token']) || !is_string($data['access_token'])) {
            // Message volontairement sans détail : la réponse peut contenir des informations de compte.
            throw new TransportException('API Gmail : le jeton de rafraîchissement a été refusé (révoqué ou expiré ?).');
        }
        $this->accessToken = $data['access_token'];
        $this->expiresAt = time() + (int) ($data['expires_in'] ?? 3000);

        return $this->accessToken;
    }
}
