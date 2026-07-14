<?php

declare(strict_types=1);

namespace Nowo\TwigInspectorBundle;

/**
 * Generates prefixes for start and end comment tags.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.com>
 * @copyright 2024 Nowo.tech
 */
class BoxDrawings
{
    protected const CHARSETS = [
      ['┏', '━', '┗'],
      ['╭', '─', '╰'],
      ['╔', '═', '╚'],
      ['┎', '─', '┖'],
    ];

    private int $charsetIndex = 0;

    private int $length = 0;

    /**
     * Gets the start comment prefix.
     *
     * @return string The start comment prefix
     */
    public function getStartCommentPrefix(): string
    {
        $prefix = $this->getCharset()[0];

        return $prefix . str_repeat((string) $this->getCharset()[1], $this->length);
    }

    /**
     * Gets the end comment prefix.
     *
     * @return string The end comment prefix
     */
    public function getEndCommentPrefix(): string
    {
        $prefix = $this->getCharset()[2];

        return $prefix . str_repeat((string) $this->getCharset()[1], $this->length);
    }

    /**
     * Handles block changes and updates charset index.
     * Cycles through different box drawing character sets to visually distinguish nested blocks.
     * Resets to the first charset when reaching the last one or when length is zero.
     *
     * @param int $length The length of the block
     *
     * @return void
     */
    public function blockChanged(int $length): void
    {
        $this->length = $length;
        ++$this->charsetIndex;

        // Reset to first charset if we've reached the last one or length is zero
        if ($length === 0 || count(self::CHARSETS) - 1 === $this->charsetIndex) {
            $this->charsetIndex = 0;
        }
    }

    /**
     * Gets the current charset.
     *
     * @return array<int, string> The charset array
     */
    private function getCharset(): array
    {
        return self::CHARSETS[$this->charsetIndex];
    }
}
