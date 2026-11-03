<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StyleguideTest extends WebTestCase
{
    public function testStyleguideDoesNotExistOutsideDev(): void
    {
        $client = static::createClient();
        $client->request('GET', '/styleguide');

        // La route n'est déclarée que sous when@dev : 404 en test comme en prod.
        self::assertResponseStatusCodeSame(404);
    }
}
