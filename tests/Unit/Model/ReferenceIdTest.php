<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\Model;

use Nowo\AuditKitBundle\Model\ReferenceId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Stringable;
use Symfony\Component\Uid\Uuid;

final class ReferenceIdTest extends TestCase
{
    /** @return iterable<string, array{mixed, string|null}> */
    public static function references(): iterable
    {
        $uuid = '0192f1a4-7d3e-7c4b-9a1e-1b2c3d4e5f60';

        yield 'positive int' => [42, '42'];
        yield 'zero' => [0, null];
        yield 'negative int' => [-1, null];
        yield 'string' => ['abc', 'abc'];
        yield 'empty string' => ['', null];
        yield 'zero string' => ['0', null];
        yield 'null' => [null, null];
        yield 'float' => [1.5, null];
        yield 'uuid object' => [Uuid::fromString($uuid), $uuid];
        yield 'entity with int getId' => [new class {
            public function getId(): int
            {
                return 7;
            }
        }, '7'];
        yield 'entity with uuid getId and e-mail __toString' => [new class($uuid) implements Stringable {
            public function __construct(private readonly string $id)
            {
            }

            public function getId(): Uuid
            {
                return Uuid::fromString($this->id);
            }

            public function __toString(): string
            {
                return 'alice@example.com';
            }
        }, $uuid];
        yield 'entity with unsaved id' => [new class {
            public ?int $saved = null;

            public function getId(): ?int
            {
                return $this->saved;
            }
        }, null];
        yield 'entity with non-stringable id object' => [new class {
            public function getId(): stdClass
            {
                return new stdClass();
            }
        }, null];
        yield 'public id property' => [new class {
            public string $id = 'u-1';
        }, 'u-1'];
        yield 'object without id' => [new stdClass(), null];
        yield 'stringable without id' => [new class implements Stringable {
            public function __toString(): string
            {
                return 'ref-9';
            }
        }, 'ref-9'];
    }

    #[DataProvider('references')]
    public function testOf(mixed $reference, ?string $expected): void
    {
        $this->assertSame($expected, ReferenceId::of($reference));
    }
}
