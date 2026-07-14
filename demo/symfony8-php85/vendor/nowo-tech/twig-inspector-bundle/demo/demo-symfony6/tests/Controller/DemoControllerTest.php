<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tests for DemoController.
 *
 * Verifies that the demo controller works correctly and integrates
 * with the Twig Inspector Bundle.
 *
 * @covers \App\Controller\DemoController
 */
final class DemoControllerTest extends WebTestCase
{
    /**
     * Tests that the home page is accessible and returns a successful response.
     */
    public function testHomePageIsAccessible(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Twig Inspector Bundle');
    }

    /**
     * Tests that the home page contains the expected Symfony version badge.
     */
    public function testHomePageContainsSymfonyVersion(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.version-badge', 'Symfony 6.4');
    }

    /**
     * Tests that the home page renders the demo message correctly.
     */
    public function testHomePageRendersMessage(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('p', 'Symfony 6.4');
    }

    /**
     * Tests that the home page contains the expected items list.
     */
    public function testHomePageContainsItemsList(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.feature-list');
        $this->assertSelectorTextContains('.feature-list', 'Enable Twig Inspector');
    }

    /**
     * Tests that the home page contains instructions.
     */
    public function testHomePageContainsInstructions(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.info-box');
        $this->assertSelectorTextContains('.info-box', 'Instructions');
    }
}

