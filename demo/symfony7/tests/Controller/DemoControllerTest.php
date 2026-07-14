<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DemoControllerTest extends WebTestCase
{
    public function testHomePageIsAccessible(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Wallet QR Bundle Demo');
    }

    public function testHomePageShowsAndroidAndIosSections(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        $this->assertGreaterThan(0, $crawler->filter('img[alt="Google Wallet QR"]')->count());
        $this->assertGreaterThan(0, $crawler->filter('img[alt="Apple Wallet QR"]')->count());
    }
}
