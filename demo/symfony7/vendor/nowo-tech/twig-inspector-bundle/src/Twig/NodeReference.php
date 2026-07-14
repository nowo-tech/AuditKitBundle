<?php

declare(strict_types=1);

namespace Nowo\TwigInspectorBundle\Twig;

/**
 * Model for storing data required for referencing to the Twig Node source code.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.com>
 * @copyright 2024 Nowo.tech
 */
class NodeReference
{
    private readonly string $id;

    /**
     * Constructor.
     *
     * @param string $name     The node name
     * @param string $template The template name
     * @param int    $line     The line number
     */
    public function __construct(
        private readonly string $name,
        private readonly string $template,
        private readonly int $line
    ) {
        $this->id = uniqid('', false);
    }

    /**
     * Gets the unique ID.
     *
     * @return string The unique ID
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Gets the node name.
     *
     * @return string The node name
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Gets the template name.
     *
     * @return string The template name
     */
    public function getTemplate(): string
    {
        return $this->template;
    }

    /**
     * Gets the line number.
     *
     * @return int The line number
     */
    public function getLine(): int
    {
        return $this->line;
    }
}
