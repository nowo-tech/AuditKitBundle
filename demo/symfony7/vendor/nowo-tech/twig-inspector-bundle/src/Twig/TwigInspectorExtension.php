<?php

declare(strict_types=1);

namespace Nowo\TwigInspectorBundle\Twig;

use Twig\Extension\AbstractExtension;

/**
 * Registers DebugInfoNodeVisitor to add comments to Twig templates.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.com>
 * @copyright 2024 Nowo.tech
 */
class TwigInspectorExtension extends AbstractExtension
{
    /**
     * Returns the node visitors.
     *
     * @return array<int, DebugInfoNodeVisitor> Array of node visitors
     */
    public function getNodeVisitors(): array
    {
        return [new DebugInfoNodeVisitor()];
    }
}
