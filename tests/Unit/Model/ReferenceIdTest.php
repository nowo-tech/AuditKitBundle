<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\Model;

use Nowo\AuditKitBundle\Model\ReferenceId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Stringable;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Uid\Ulid;
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
        // REQ-SEC-006 (look-alike ids): refused, never trimmed; case and accents stay significant.
        yield 'upper-case look-alike' => ['Bob', 'Bob'];
        yield 'accented look-alike' => ["b\u{00F3}b", "b\u{00F3}b"];
        yield 'NFD look-alike' => ["bo\u{0301}b", "bo\u{0301}b"];
        yield 'inner space' => ['bob smith', 'bob smith'];
        yield 'trailing space' => ['bob ', null];
        yield 'leading space' => [' bob', null];
        yield 'trailing newline' => ["bob\n", null];
        yield 'tab inside' => ["bo\tb", null];
        yield 'trailing NBSP' => ["bob\u{00A0}", null];
        yield 'leading ideographic space' => ["\u{3000}bob", null];
        yield 'zero-width space inside' => ["bo\u{200B}b", null];
        yield 'BOM' => ["\u{FEFF}bob", null];
        yield 'bidi mark' => ["bob\u{200E}", null];
        yield 'line separator' => ["bo\u{2028}b", null];
        yield 'NUL byte' => ["bob\0", null];
        yield 'invalid UTF-8' => ["bob\xC3", null];
        yield 'longest usable' => [str_repeat('x', ReferenceId::MAX_LENGTH), str_repeat('x', ReferenceId::MAX_LENGTH)];
        yield 'too long' => [str_repeat('x', ReferenceId::MAX_LENGTH + 1), null];
        yield 'id object with padded id' => [new class {
            public string $id = 'u-1 ';
        }, null];
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
        // LQ-A-L1: an object's own __toString() is never stored (it is often the identifier / e-mail address).
        yield 'stringable without id' => [new class implements Stringable {
            public function __toString(): string
            {
                return 'ref-9';
            }
        }, null];
        yield 'in-memory security user' => [new InMemoryUser('alice@example.com', null), null];
        yield 'ulid object' => [new Ulid('01ARZ3NDEKTSV4RRFFQ69G5FAV'), '01ARZ3NDEKTSV4RRFFQ69G5FAV'];
        yield 'entity with stringable id value object' => [new class {
            public function getId(): Stringable
            {
                return new class implements Stringable {
                    public function __toString(): string
                    {
                        return 'vo-7';
                    }
                };
            }
        }, 'vo-7'];
        yield 'getId returning a security user' => [new class {
            public function getId(): InMemoryUser
            {
                return new InMemoryUser('bob@example.com', null);
            }
        }, null];
    }

    #[DataProvider('references')]
    public function testOf(mixed $reference, ?string $expected): void
    {
        $this->assertSame($expected, ReferenceId::of($reference));
    }
}
