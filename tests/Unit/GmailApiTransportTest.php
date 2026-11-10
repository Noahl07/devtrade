<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Mailer\GmailApiTransportFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mime\Email;

final class GmailApiTransportTest extends TestCase
{
    public function testSendsRawMimeWithAFreshAccessToken(): void
    {
        $appels = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$appels): MockResponse {
            $appels[] = [$url, $options];

            return str_contains($url, 'oauth2')
                ? new MockResponse(json_encode(['access_token' => 'jeton-acces', 'expires_in' => 3600]))
                : new MockResponse(json_encode(['id' => 'msg-1']));
        });
        $transport = (new GmailApiTransportFactory($http, 'id-client', 'secret-client', 'jeton-rafraichissement'))->create(Dsn::fromString('gmail+api://default'));

        $email = (new Email())->from('contact@devtrade.fr')->to('destinataire@exemple.fr')->subject('Sujet de test')->text('Bonjour');
        $transport->send($email);
        $transport->send($email);

        self::assertCount(3, $appels, 'Un seul échange de jeton pour deux envois.');
        self::assertStringContainsString('grant_type=refresh_token', $appels[0][1]['body']);
        self::assertSame('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', $appels[1][0]);
        self::assertContains('Authorization: Bearer jeton-acces', $appels[1][1]['headers']);
        $raw = json_decode($appels[1][1]['body'], true)['raw'];
        $mime = base64_decode(strtr($raw, '-_', '+/'));
        self::assertStringContainsString('Subject: Sujet de test', $mime);
        self::assertStringContainsString('To: destinataire@exemple.fr', $mime);
    }

    public function testRefusedRefreshTokenIsATransportError(): void
    {
        $http = new MockHttpClient(new MockResponse(json_encode(['error' => 'invalid_grant']), ['http_code' => 400]));
        $transport = (new GmailApiTransportFactory($http, 'a', 'b', 'c'))->create(Dsn::fromString('gmail+api://default'));

        $this->expectException(TransportException::class);
        $transport->send((new Email())->from('a@exemple.fr')->to('b@exemple.fr')->text('x'));
    }

    public function testMissingCredentialsAreReportedWithoutAnyCall(): void
    {
        $http = new MockHttpClient(function (): MockResponse {
            self::fail('Aucun appel attendu.');
        });
        $transport = (new GmailApiTransportFactory($http))->create(Dsn::fromString('gmail+api://default'));

        $this->expectException(TransportException::class);
        $transport->send((new Email())->from('a@exemple.fr')->to('b@exemple.fr')->text('x'));
    }
}
