<?php

declare(strict_types=1);

namespace Nowo\TwigInspectorBundle\Twig\Node;

use Twig\Compiler;
use Twig\Node\Node;

/**
 * Modify generated Twig template to call the `start` method of HtmlCommentsExtension extension.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.com>
 * @copyright 2024 Nowo.tech
 */
class NodeStart extends Node
{
    /**
     * Constructor.
     *
     * @param string $extensionName The extension name
     * @param string $name          The node name
     * @param int    $line          The line number
     * @param string $varName       The variable name
     */
    public function __construct(string $extensionName, string $name, int $line, string $varName)
    {
        parent::__construct(
            [],
            ['extension_name' => $extensionName, 'name' => $name, 'line' => $line, 'var_name' => $varName]
        );
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
          ->write(sprintf('$%s = $this->env->getExtension(', $this->getAttribute('var_name')))
          ->repr($this->getAttribute('extension_name'))
          ->raw(");\n")
          ->write(
              sprintf(
                  '$%s->start($%s = new \Nowo\TwigInspectorBundle\Twig\NodeReference(',
                  $this->getAttribute('var_name'),
                  $this->getAttribute('var_name') . '_ref'
              )
          )
          ->repr($this->getAttribute('name'))
          ->raw(', $this->getTemplateName(), ')
          ->repr($this->getAttribute('line'))
          ->raw("));\n\n");
    }
}
