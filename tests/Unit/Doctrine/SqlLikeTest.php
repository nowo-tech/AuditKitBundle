<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\Doctrine;

use Nowo\AuditKitBundle\Doctrine\SqlLike;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SqlLikeTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function escapeProvider(): iterable
    {
        yield 'plain' => ['abc', 'abc'];
        yield 'empty' => ['', ''];
        yield 'percent' => ['50%', '50\\%'];
        yield 'underscore' => ['a_b', 'a\\_b'];
        yield 'backslash' => ['a\\b', 'a\\\\b'];
        yield 'all' => ['\\%_', '\\\\\\%\\_'];
        yield 'no double' => ['\\%', '\\\\\\%'];
    }

    #[DataProvider('escapeProvider')]
    public function testEscape(string $input, string $expected): void
    {
        $this->assertSame($expected, SqlLike::escape($input));
    }

    public function testContainsWrapsEscapedValue(): void
    {
        $this->assertSame('%10\\%\\_off%', SqlLike::contains('10%_off'));
        $this->assertSame('%%', SqlLike::contains(''));
    }
}
