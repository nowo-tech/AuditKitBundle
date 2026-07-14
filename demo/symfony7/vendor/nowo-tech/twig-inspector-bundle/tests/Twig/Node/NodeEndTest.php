<?php

declare(strict_types=1);

namespace Nowo\TwigInspectorBundle\Tests\Twig\Node;

use Nowo\TwigInspectorBundle\Twig\Node\NodeEnd;
use PHPUnit\Framework\TestCase;
use Twig\Compiler;

/**
 * Tests for NodeEnd.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.com>
 * @copyright 2024 Nowo.tech
 */
final class NodeEndTest extends TestCase
{
    public function testConstructor(): void
    {
        $node = new NodeEnd('var_name');

        $this->assertSame('var_name', $node->getAttribute('var_name'));
    }

    public function testCompile(): void
    {
        $node = new NodeEnd('var123');
        $compiler = $this->createMock(Compiler::class);

        $compiler->method('write')
          ->willReturnCallback(function ($arg) use ($compiler) {
              static $callCount = 0;
              $callCount++;
              if ($callCount === 1) {
                  $this->assertSame("\n", $arg);
              } else {
                  $this->assertStringContainsString('$var123->end($var123_ref', $arg);
              }

              return $compiler;
          });

        $compiler->expects($this->exactly(2))->method('write');

        $node->compile($compiler);
    }
}
