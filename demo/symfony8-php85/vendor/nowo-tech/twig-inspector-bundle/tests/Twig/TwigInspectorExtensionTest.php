<?php

declare(strict_types=1);

namespace Nowo\TwigInspectorBundle\Tests\Twig;

use Nowo\TwigInspectorBundle\Twig\DebugInfoNodeVisitor;
use Nowo\TwigInspectorBundle\Twig\TwigInspectorExtension;
use PHPUnit\Framework\TestCase;

/**
 * Tests for TwigInspectorExtension.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.com>
 * @copyright 2024 Nowo.tech
 */
final class TwigInspectorExtensionTest extends TestCase
{
    private TwigInspectorExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new TwigInspectorExtension();
    }

    public function testGetNodeVisitors(): void
    {
        $visitors = $this->extension->getNodeVisitors();

        $this->assertIsArray($visitors);
        $this->assertCount(1, $visitors);
        $this->assertInstanceOf(DebugInfoNodeVisitor::class, $visitors[0]);
    }

    public function testGetNodeVisitorsReturnsNewInstance(): void
    {
        $visitors1 = $this->extension->getNodeVisitors();
        $visitors2 = $this->extension->getNodeVisitors();

        $this->assertNotSame($visitors1[0], $visitors2[0]);
    }
}
