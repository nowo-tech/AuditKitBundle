<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\Avatar;

use Nowo\AuditKitBundle\Avatar\AvatarUrlPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Since 1.11: the avatar URL policy (avatar contract §3.1, the same data as UiKit and ChatKit): https:// without userinfo, or a same-origin path with a single leading "/".
 */
final class AvatarUrlPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function accepted(): iterable
    {
        yield 'https' => ['https://cdn.example.com/u/1.webp'];
        yield 'https upper case' => ['HTTPS://cdn.example.com/u/1.webp'];
        yield 'https with port and query' => ['https://cdn.example.com:8443/a.png?v=2#x'];
        yield 'https host only' => ['https://cdn.example.com'];
        yield 'same-origin path' => ['/files/0b9b6f8e-3d1a-4c51-9b77-1f0f4c5e2a10/s64'];
        yield 'root' => ['/'];
        yield 'path with @' => ['/users/@ada/avatar'];
        yield 'max length' => ['/' . str_repeat('a', AvatarUrlPolicy::MAX_BYTES - 1)];
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function rejected(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'http' => ['http://cdn.example.com/a.png'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'javascript upper' => ['JavaScript:alert(1)'];
        yield 'vbscript' => ['vbscript:msgbox(1)'];
        yield 'data image' => ['data:image/png;base64,iVBORw0KGgo='];
        yield 'data svg' => ['data:image/svg+xml,<svg onload=alert(1)>'];
        yield 'blob' => ['blob:https://example.com/0b9b6f8e'];
        yield 'file' => ['file:///etc/passwd'];
        yield 'ftp' => ['ftp://example.com/a.png'];
        yield 'protocol-relative' => ['//evil.example/a.png'];
        yield 'slash backslash' => ['/\\evil.example/a.png'];
        yield 'backslash in path' => ['/files\\a.png'];
        yield 'backslash in https' => ['https://cdn.example.com\\@evil.example/a.png'];
        yield 'userinfo' => ['https://good@evil.example/a.png'];
        yield 'user and password' => ['https://user:pass@evil.example/a.png'];
        yield 'empty userinfo' => ['https://@evil.example/a.png'];
        yield 'https without host' => ['https:///a.png'];
        yield 'https without slashes' => ['https:cdn.example.com/a.png'];
        yield 'https unparsable' => ['https://cdn.example.com:99999999/a.png'];
        yield 'relative' => ['files/a.png'];
        yield 'dot relative' => ['./a.png'];
        yield 'query only' => ['?a=1'];
        yield 'fragment only' => ['#a'];
        yield 'leading space' => [' /a.png'];
        yield 'inner space' => ['/a b.png'];
        yield 'tab' => ["/a\tb.png"];
        yield 'newline' => ["https://cdn.example.com/a\n.png"];
        yield 'nul' => ["/a\0.png"];
        yield 'del' => ["/a\x7F.png"];
        yield 'too long' => ['/' . str_repeat('a', AvatarUrlPolicy::MAX_BYTES)];
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
}
