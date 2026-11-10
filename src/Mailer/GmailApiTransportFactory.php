<?php

declare(strict_types=1);

namespace App\Mailer;

use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportFactoryInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * MAILER_DSN=gmail+api://default ; les identifiants viennent des variables GMAIL_CLIENT_ID,
 * GMAIL_CLIENT_SECRET et GMAIL_REFRESH_TOKEN (Railway), jamais du dépôt.
 */
final class GmailApiTransportFactory implements TransportFactoryInterface
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly ?string $clientId = null,
        private readonly ?string $clientSecret = null,
        private readonly ?string $refreshToken = null,
    ) {
    }

    public function create(Dsn $dsn): TransportInterface
    {
        if (!$this->supports($dsn)) {
            throw new UnsupportedSchemeException($dsn, 'gmail+api', ['gmail+api']);
        }

        return new GmailApiTransport($this->http, (string) $this->clientId, (string) $this->clientSecret, (string) $this->refreshToken);
    }

    public function supports(Dsn $dsn): bool
    {
        return $dsn->getScheme() === 'gmail+api';
    }
}
