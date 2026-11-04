<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\ActionLog;

use ArrayIterator;
use Generator;
use Nowo\AuditKitBundle\Avatar\ActionLogAvatars;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity\KernelActionLog;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\AuditKitBundle\Tests\Support\CountingAvatarResolver;
use Nowo\AuditKitBundle\Twig\ActionLogAvatarExtension;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

use function count;

/** Since 1.11: actor and subject avatars in the action log timeline (one resolver call per include). */
final class TimelineAvatarTest extends TestCase
{
    private CountingAvatarResolver $resolver;

    private ?ActionLogAvatars $avatars = null;

    public function testOneResolverCallPerTimelineWithActorAndSubjectPictures(): void
    {
        [$ada, $bob, $eve] = [$this->user(1, 'Ada Lovelace'), $this->user(2, 'bob@example.com'), $this->user(3, 'Eve')];
        $entries           = [];
        for ($i = 0; $i < 5; ++$i) {
            $entries[] = (new KernelActionLog())->setAction('a' . $i)->setActor($i % 2 === 0 ? $ada : $eve)->setSubject($bob);
        }

        $html = $this->render($entries, ['1' => '/files/1/s64', '2' => 'https://cdn.example.com/2.webp', '3' => null]);

        $this->assertSame([['keys' => ['1', '2', '3'], 'size' => 24]], $this->resolver->calls, 'exactly one batch for the whole page');
        $this->assertSame(3, substr_count($html, '<span class="nowo-ui-avatar nowo-ui-avatar--sm has-image nowo-audit-timeline__avatar" aria-hidden="true" data-nowo-ui-avatar>AL<img class="nowo-ui-avatar__image" data-nowo-avatar-image src="/files/1/s64" alt="" width="24" height="24" loading="lazy" decoding="async" referrerpolicy="same-origin"></span>'));
        $this->assertSame(5, substr_count($html, '>B<img class="nowo-ui-avatar__image" data-nowo-avatar-image src="https://cdn.example.com/2.webp"'));
        $this->assertSame(8, substr_count($html, '<img '), 'Eve has no picture');
        $this->assertMatchesRegularExpression('#· <span class="nowo-ui-avatar[^"]*"[^>]*>B<img [^>]*src="https://cdn\.example\.com/2\.webp"[^>]*></span>\s*\[NowoAuditKitBundle:nowo_audit_kit\.action_log\.subject bob@example\.com\]#', $html);
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+=|style=/', $html);

        // A second include of the same users makes no new call (memoised per request).
        $this->twig()->render('@NowoAuditKitBundle/action_log/_timeline.html.twig', ['entries' => new ArrayIterator($entries)]);
        $this->assertSame(1, $this->callCount(), 'memoised: no second call');
    }

    public function testDeletedAndAnonymisedAccountsGetNoAvatar(): void
    {
        $anonymised = $this->user(9, 'Anonymous user');
        $unsaved    = $this->user(null, 'Not persisted');
        $entries    = [
            (new KernelActionLog())->setAction('deleted.actor')->setSubject($anonymised),
            (new KernelActionLog())->setAction('anon.actor')->setActor($anonymised)->setSubject($unsaved),
        ];

        // The host resolver returns null for an anonymised account; a deleted actor is null (SET NULL) and an unsaved
        // user has no key: neither reaches the resolver.
        $html = $this->render($entries, ['9' => null]);

        $this->assertSame([['keys' => ['9'], 'size' => 24]], $this->resolver->calls);
        $this->assertStringNotContainsString('nowo-ui-avatar', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('[NowoAuditKitBundle:nowo_audit_kit.action_log.by_system]', $html);
    }

    public function testRejectedUrlsAndEscaping(): void
    {
        $evil    = $this->user(1, '"><script>alert(1)</script>');
        $entries = [(new KernelActionLog())->setAction('x')->setActor($evil)->setSubject($this->user(2, 'Bob'))];

        $html = $this->render($entries, ['1' => '/a.webp?x=1&y=2', '2' => 'data:image/png;base64,AAAA']);

        $this->assertStringContainsString('src="/a.webp?x=1&amp;y=2"', $html);
        $this->assertStringContainsString('aria-hidden="true" data-nowo-ui-avatar>&quot;<img', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertSame(1, substr_count($html, '<img '), 'the data: URL is dropped');

        // Audit DZ-L2: quotes are rejected by the policy, so an attribute break-out never reaches the markup.
        $broken = $this->render([(new KernelActionLog())->setAction('y')->setActor($this->user(3, 'Eve'))], ['3' => '/a"/onerror="alert(1)']);
        $this->assertStringNotContainsString('<img', $broken);
        $this->assertStringNotContainsString('onerror', $broken);
    }

    public function testNoCallForAnEmptyTimelineOrAGenerator(): void
    {
        $this->render([], ['1' => '/a.webp']);
        $this->assertSame([], $this->resolver->calls);

        $extension = new ActionLogAvatarExtension(new ActionLogAvatars($this->resolver));
        $generator = (static function (): Generator {
            yield (new KernelActionLog())->setAction('x');
        })();
        $this->assertSame('', $extension->prime($generator));
        $this->assertSame('', $extension->prime(null));
        $this->assertTrue($generator->valid(), 'a generator is not consumed');
        $this->assertSame([], $this->resolver->calls);
    }

    public function testTheUrlFunctionIsAvailableToHosts(): void
    {
        $this->resolver = new CountingAvatarResolver(['5' => '/files/5/s64']);
        $twig           = $this->twig(['host' => '{{ nowo_audit_kit_avatar_url(user) ?? "none" }}|{{ nowo_audit_kit_avatar_url(null) ?? "none" }}']);
        $this->assertSame('/files/5/s64|none', $twig->render('host', ['user' => $this->user(5, 'X')]));
    }

    /**
     * @param iterable<KernelActionLog> $entries
     * @param array<array-key, string|null> $urls
     */
    private function render(iterable $entries, array $urls): string
    {
        $this->resolver = new CountingAvatarResolver($urls);
        $this->avatars  = null;

        return $this->twig()->render('@NowoAuditKitBundle/action_log/_timeline.html.twig', ['entries' => $entries]);
    }

    /**
     * @param array<string, string> $templates
     */
    private function twig(array $templates = []): Environment
    {
        $fs = new FilesystemLoader();
        $fs->addPath(__DIR__ . '/../../../src/Resources/views', 'NowoAuditKitBundle');
        $twig = new Environment(new ChainLoader([new ArrayLoader($templates), $fs]), ['strict_variables' => true, 'autoescape' => 'html']);
        $twig->addExtension(new ActionLogAvatarExtension($this->avatars ??= new ActionLogAvatars($this->resolver)));
        $twig->addFilter(new TwigFilter('trans', static fn (string $key, array $params = [], ?string $domain = null): string => '[' . $domain . ':' . $key . ($params === [] ? '' : ' ' . implode(',', $params)) . ']'));

        return $twig;
    }

    private function callCount(): int
    {
        return count($this->resolver->calls);
    }

    private function user(?int $id, string $email): KernelUser
    {
        $user     = new KernelUser($email);
        $user->id = $id;

        return $user;
    }
}
