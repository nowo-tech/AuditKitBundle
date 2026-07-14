<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DemoControllerTest extends WebTestCase
{
    public function testHomePageShowsAuditFields(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Automatic audit fields');
        self::assertSelectorTextContains('body', 'createdAt');
        self::assertSelectorTextContains('body', 'Auditable(enabled: false)');
        self::assertSelectorTextContains('body', 'Latest legacy records');
    }
}
