<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\Avatar;

use Nowo\AuditKitBundle\Avatar\AvatarUrlPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function strlen;

use const JSON_THROW_ON_ERROR;

/**
 * Since 1.11: the avatar URL policy (avatar contract §3.1, audit DZ), checked against UiKit's shared case table.
 */
final class AvatarUrlPolicyTest extends TestCase
{
    /**
     * UiKit's shared case table (a copy of UiKit's tests/Fixtures/avatar/url-policy-cases.json).
     */
    public const CASES = __DIR__ . '/../../Fixtures/avatar/url-policy-cases.json';

    /**
     * @return iterable<string, array{string}>
     */
    public static function accepted(): iterable
    {
        foreach (self::cases()['accepted'] as $name => $url) {
            yield $name => [$url];
        }
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function rejected(): iterable
    {
        yield 'null' => [null];
        foreach (self::cases()['rejected'] as $name => $url) {
            yield $name => [$url];
        }
    }

    public function testTheTableCoversTheAuditCases(): void
    {
        $rejected = self::cases()['rejected'];
        foreach (["/files/a/s64\n", "https://cdn.example.com/a\n", '/' . str_repeat('a', 2047) . "\n", '/a"/onerror="alert(1)', 'https://[::1]/a.png', '/' . str_repeat('é', 1024)] as $url) {
            $this->assertContains($url, $rejected);
        }
        $this->assertSame(AvatarUrlPolicy::MAX_BYTES, strlen(self::cases()['accepted']['non-ASCII path, 2048 bytes']));
    }

    #[DataProvider('accepted')]
    public function testAccepted(string $url): void
    {
        $this->assertSame($url, AvatarUrlPolicy::sanitize($url));
        $this->assertTrue(AvatarUrlPolicy::isAllowed($url));
    }

    #[DataProvider('rejected')]
    public function testRejected(?string $url): void
    {
        $this->assertNull(AvatarUrlPolicy::sanitize($url));
        $this->assertFalse(AvatarUrlPolicy::isAllowed($url));
    }

    /**
     * @return array{accepted: array<string, string>, rejected: array<string, string>}
     */
    private static function cases(): array
    {
        /** @var array{accepted: array<string, string>, rejected: array<string, string>} $cases */
        $cases = json_decode((string) file_get_contents(self::CASES), true, 512, JSON_THROW_ON_ERROR);

        return $cases;
    }
}
