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
        self::assertSelectorTextContains('body', 'createdBy: demo@audit-kit.test');
        self::assertSelectorTextContains('body', 'Auditable(enabled: false)');
        self::assertSelectorTextContains('body', 'Latest legacy records');
    }

    public function testTimelineShowsActorAndSubjectAvatarsFromOneResolverCall(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/timeline');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="resolver-calls"]', 'Resolver calls for this timeline: 1');
        self::assertCount(4, $crawler->filter('img.nowo-ui-avatar__image[data-nowo-avatar-image]'));
        self::assertCount(2, $crawler->filter('img[src="/images/avatar-ada.svg"]'));
        self::assertCount(2, $crawler->filter('img[src="/does-not-exist.webp"]'));
        self::assertCount(0, $crawler->filter('img[src^="http:"]'));
    }
}
