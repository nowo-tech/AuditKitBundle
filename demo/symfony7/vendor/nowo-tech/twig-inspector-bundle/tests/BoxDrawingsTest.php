<?php

declare(strict_types=1);

namespace Nowo\TwigInspectorBundle\Tests;

use Nowo\TwigInspectorBundle\BoxDrawings;
use PHPUnit\Framework\TestCase;

/**
 * Tests for BoxDrawings.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.com>
 * @copyright 2024 Nowo.tech
 */
final class BoxDrawingsTest extends TestCase
{
    private BoxDrawings $boxDrawings;

    protected function setUp(): void
    {
        $this->boxDrawings = new BoxDrawings();
    }

    public function testGetStartCommentPrefix(): void
    {
        $prefix = $this->boxDrawings->getStartCommentPrefix();

        $this->assertIsString($prefix);
        $this->assertNotEmpty($prefix);
    }

    public function testGetEndCommentPrefix(): void
    {
        $prefix = $this->boxDrawings->getEndCommentPrefix();

        $this->assertIsString($prefix);
        $this->assertNotEmpty($prefix);
    }

    public function testBlockChanged(): void
    {
        $initialStart = $this->boxDrawings->getStartCommentPrefix();
        $initialEnd = $this->boxDrawings->getEndCommentPrefix();

        $this->boxDrawings->blockChanged(5);

        $newStart = $this->boxDrawings->getStartCommentPrefix();
        $newEnd = $this->boxDrawings->getEndCommentPrefix();

        // After blockChanged, the prefix should change
        $this->assertNotSame($initialStart, $newStart);
        $this->assertNotSame($initialEnd, $newEnd);
    }

    public function testBlockChangedWithZeroLength(): void
    {
        $this->boxDrawings->blockChanged(0);

        // Should reset charset index
        $prefix = $this->boxDrawings->getStartCommentPrefix();
        $this->assertStringStartsWith('┏', $prefix);
    }

    public function testBlockChangedCyclesThroughCharsets(): void
    {
        $initialPrefix = $this->boxDrawings->getStartCommentPrefix();

        // Change block multiple times to cycle through charsets (4 charsets: 0,1,2,3)
        // Call 1: charsetIndex 0->1 (┏->╭)
        // Call 2: charsetIndex 1->2 (╭->╔)
        // Call 3: charsetIndex 2->3, then resets to 0 (╔->┏) because 3 === count-1
        // Call 4: charsetIndex 0->1 (┏->╭)
        for ($i = 1; $i <= 4; $i++) {
            $this->boxDrawings->blockChanged($i);
        }

        // After 4 calls, should be at charset index 1 (╭)
        $finalPrefix = $this->boxDrawings->getStartCommentPrefix();
        $this->assertStringStartsWith('╭', $finalPrefix);
    }

    public function testBlockChangedWithMaxCharsetIndex(): void
    {
        // Set to last charset index (3 calls: index 0->1, 1->2, 2->3)
        for ($i = 0; $i < 3; $i++) {
            $this->boxDrawings->blockChanged(1);
        }

        // Next change (4th call) should increment to 3, then reset to 0 because 3 === count-1
        // But wait, after 3 calls, charsetIndex is already 3, so the 4th call increments to 4,
        // but the condition checks if charsetIndex === count-1 (3), which is false after increment.
        // Actually, after 3 calls: charsetIndex = 3, and on the 3rd call it resets to 0.
        // So after 3 calls, charsetIndex = 0. The 4th call increments to 1.
        $this->boxDrawings->blockChanged(1);
        $prefix = $this->boxDrawings->getStartCommentPrefix();
        // After 4 calls total, should be at index 1 (╭)
        $this->assertStringStartsWith('╭', $prefix);
    }

    public function testGetStartCommentPrefixWithLength(): void
    {
        $this->boxDrawings->blockChanged(5);
        $prefix = $this->boxDrawings->getStartCommentPrefix();

        $this->assertStringStartsWith('╭', $prefix);
        $this->assertGreaterThan(1, strlen($prefix));
    }

    public function testGetEndCommentPrefixWithLength(): void
    {
        $this->boxDrawings->blockChanged(3);
        $prefix = $this->boxDrawings->getEndCommentPrefix();

        $this->assertStringStartsWith('╰', $prefix);
        $this->assertGreaterThan(1, strlen($prefix));
    }
}
