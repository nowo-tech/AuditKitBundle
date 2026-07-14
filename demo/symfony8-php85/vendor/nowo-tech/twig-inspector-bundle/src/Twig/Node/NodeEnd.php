<?php

declare(strict_types=1);

namespace Nowo\TwigInspectorBundle\Twig\Node;

use Twig\Attribute\YieldReady;
use Twig\Compiler;
use Twig\Node\Node;

/**
 * Modify generated Twig template to call the `end` method of HtmlCommentsExtension extension.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.com>
 * @copyright 2024 Nowo.tech
 */
#[YieldReady]
class NodeEnd extends Node
{
    /**
     * Constructor.
     *
     * @param string $varName The variable name
     */
    public function __construct(string $varName)
    {
        parent::__construct([], ['var_name' => $varName]);
    }

    /**
     * Compiles the node.
     *
     * @param Compiler $compiler The Twig compiler
     *
     * @return void
     */
    public function compile(Compiler $compiler): void
    {
        $compiler
          ->write("\n")
          ->write(
              sprintf(
                  "\$%s->end(\$%s);\n\n",
                  $this->getAttribute('var_name'),
                  $this->getAttribute('var_name') . '_ref'
              )
          );
    }
}
