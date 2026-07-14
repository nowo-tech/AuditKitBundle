<?php

declare(strict_types=1);

namespace Nowo\TwigInspectorBundle\Tests\Twig;

use Nowo\TwigInspectorBundle\Twig\NodeReference;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NodeReference.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.com>
 * @copyright 2024 Nowo.tech
 */
final class NodeReferenceTest extends TestCase
{
    public function testConstructor(): void
    {
        $ref = new NodeReference('block_name', 'template.html.twig', 42);

        $this->assertSame('block_name', $ref->getName());
        $this->assertSame('template.html.twig', $ref->getTemplate());
        $this->assertSame(42, $ref->getLine());
    }

    public function testGetId(): void
    {
        $ref1 = new NodeReference('block1', 'template1.html.twig', 1);
        $ref2 = new NodeReference('block2', 'template2.html.twig', 2);

        $id1 = $ref1->getId();
        $id2 = $ref2->getId();

        $this->assertIsString($id1);
        $this->assertIsString($id2);
        $this->assertNotSame($id1, $id2);
        $this->assertNotEmpty($id1);
        $this->assertNotEmpty($id2);
    }

    public function testGetName(): void
    {
        $ref = new NodeReference('my_block', 'template.html.twig', 10);

        $this->assertSame('my_block', $ref->getName());
    }

    public function testGetTemplate(): void
    {
        $ref = new NodeReference('block', 'path/to/template.html.twig', 5);

        $this->assertSame('path/to/template.html.twig', $ref->getTemplate());
    }

    public function testGetLine(): void
    {
        $ref = new NodeReference('block', 'template.html.twig', 123);

        $this->assertSame(123, $ref->getLine());
    }

    public function testMultipleInstances(): void
    {
        $ref1 = new NodeReference('block1', 'template1.html.twig', 1);
        $ref2 = new NodeReference('block2', 'template2.html.twig', 2);
        $ref3 = new NodeReference('block1', 'template1.html.twig', 1);

        $this->assertNotSame($ref1->getId(), $ref2->getId());
        $this->assertNotSame($ref1->getId(), $ref3->getId());
        $this->assertNotSame($ref2->getId(), $ref3->getId());
    }
}
