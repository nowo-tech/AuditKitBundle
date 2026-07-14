<?php

declare(strict_types=1);

namespace Nowo\TwigInspectorBundle\Tests\DataCollector;

use Nowo\TwigInspectorBundle\DataCollector\TwigInspectorCollector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Tests for TwigInspectorCollector.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.com>
 * @copyright 2024 Nowo.tech
 */
final class TwigInspectorCollectorTest extends TestCase
{
    private RequestStack $requestStack;
    private TwigInspectorCollector $collector;

    protected function setUp(): void
    {
        $this->requestStack = new RequestStack();
        $this->collector = new TwigInspectorCollector($this->requestStack);
    }

    public function testCollect(): void
    {
        $request = new Request();
        $response = new Response();

        // Should not throw any exception
        $this->collector->collect($request, $response);

        $this->assertTrue(true);
    }

    public function testCollectWithException(): void
    {
        $request = new Request();
        $response = new Response();
        $exception = new \Exception('Test exception');

        // Should not throw any exception
        $this->collector->collect($request, $response, $exception);

        $this->assertTrue(true);
    }

    public function testReset(): void
    {
        // Should not throw any exception
        $this->collector->reset();

        $data = $this->collector->getData();
        $this->assertEmpty($data['templates']);
        $this->assertEmpty($data['blocks']);
        $this->assertSame(0, $this->collector->getTotalTemplates());
        $this->assertSame(0, $this->collector->getTotalBlocks());
    }

    public function testGetName(): void
    {
        $this->assertSame('twig_inspector', $this->collector->getName());
    }

    public function testGetTotalTemplates(): void
    {
        $this->assertSame(0, $this->collector->getTotalTemplates());
    }

    public function testGetTotalBlocks(): void
    {
        $this->assertSame(0, $this->collector->getTotalBlocks());
    }

    public function testCollectWithTemplateComments(): void
    {
        $request = new Request();
        $request->cookies->set('twig_inspector_is_active', '1');
        $response = new Response();
        $response->setContent('<!-- ┏━ template1.html.twig [/_template/template1.html.twig?line=1] #id1-->');

        $this->collector->collect($request, $response);

        $templates = $this->collector->getTemplates();
        $this->assertCount(1, $templates);
        $this->assertSame('template1.html.twig', $templates[0]['name']);
        $this->assertSame(1, $templates[0]['count']);
        $this->assertSame(1, $this->collector->getTotalTemplates());
    }

    public function testCollectWithBlockComments(): void
    {
        $request = new Request();
        $request->cookies->set('twig_inspector_is_active', '1');
        $response = new Response();
        $response->setContent('<!-- ┏━ block1 [/_template/template1.html.twig?line=1] #id1-->');

        $this->collector->collect($request, $response);

        $blocks = $this->collector->getBlocks();
        $this->assertCount(1, $blocks);
        $this->assertSame('template1.html.twig', $blocks[0]['template']);
        $this->assertSame('block1', $blocks[0]['name']);
        $this->assertSame(1, $blocks[0]['count']);
        $this->assertSame(1, $this->collector->getTotalBlocks());
    }

    public function testCollectWithMultipleTemplates(): void
    {
        $request = new Request();
        $request->cookies->set('twig_inspector_is_active', '1');
        $response = new Response();
        $content = '<!-- ┏━ template1.html.twig [/_template/template1.html.twig?line=1] #id1-->';
        $content .= '<!-- ┏━ template2.html.twig [/_template/template2.html.twig?line=1] #id2-->';
        $response->setContent($content);

        $this->collector->collect($request, $response);

        $this->assertSame(2, $this->collector->getTotalTemplates());
    }

    public function testIsEnabledWithCookie(): void
    {
        $request = new Request();
        $request->cookies->set('twig_inspector_is_active', '1');
        $response = new Response();

        $this->collector->collect($request, $response);

        $this->assertTrue($this->collector->isEnabled());
    }

    public function testIsEnabledWithoutCookie(): void
    {
        $request = new Request();
        $response = new Response();

        $this->collector->collect($request, $response);

        $this->assertFalse($this->collector->isEnabled());
    }

    public function testCollectWithEmptyContent(): void
    {
        $request = new Request();
        $request->cookies->set('twig_inspector_is_active', '1');
        $response = new Response();
        $response->setContent('');

        $this->collector->collect($request, $response);

        $this->assertSame(0, $this->collector->getTotalTemplates());
        $this->assertSame(0, $this->collector->getTotalBlocks());
    }

    public function testCollectWithFalseContent(): void
    {
        $request = new Request();
        $request->cookies->set('twig_inspector_is_active', '1');
        $response = $this->createMock(Response::class);
        $response->method('getContent')->willReturn(false);

        $this->collector->collect($request, $response);

        $this->assertSame(0, $this->collector->getTotalTemplates());
        $this->assertSame(0, $this->collector->getTotalBlocks());
    }

    public function testGetData(): void
    {
        $this->collector->addTemplate('template1.html.twig');
        $this->collector->addBlock('block1');

        $data = $this->collector->getData();

        $this->assertIsArray($data);
        $this->assertArrayHasKey('templates', $data);
        $this->assertArrayHasKey('blocks', $data);
        $this->assertArrayHasKey('total_templates', $data);
        $this->assertArrayHasKey('total_blocks', $data);
        $this->assertArrayHasKey('enabled', $data);
    }
}
