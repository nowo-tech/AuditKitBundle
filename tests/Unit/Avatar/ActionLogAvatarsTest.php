<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\Avatar;

use LogicException;
use Nowo\AuditKitBundle\Avatar\ActionLogAvatarResolverInterface;
use Nowo\AuditKitBundle\Avatar\ActionLogAvatars;
use Nowo\AuditKitBundle\Avatar\AuditAvatarKey;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity\KernelActionLog;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\AuditKitBundle\Tests\Support\AvatarUser;
use Nowo\AuditKitBundle\Tests\Support\CountingAvatarResolver;
use Nowo\AuditKitBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;

use function count;
use function get_object_vars;
use function is_array;

final class ActionLogAvatarsTest extends TestCase
{
    public function testPrimingBatchesTheNewUsersInOneCall(): void
    {
        [$ada, $bob, $eve] = [$this->user(1), $this->user(2), $this->user(3)];
        $resolver          = new CountingAvatarResolver(['1' => '/files/1/s64', '2' => 'https://cdn.example.com/2.webp']);
        $avatars           = new ActionLogAvatars($resolver);

        $avatars->prime([$ada, null, $bob, $ada, $this->user(null), $eve]);
        $this->assertSame([['keys' => ['1', '2', '3'], 'size' => 24]], $resolver->calls);

        // Known users: no new call; a miss primes only that user.
        $avatars->prime([$ada, $bob]);
        $this->assertSame('/files/1/s64', $avatars->urlOf($ada));
        $this->assertSame('https://cdn.example.com/2.webp', $avatars->urlOf($bob));
        $this->assertNull($avatars->urlOf($eve));
        $this->assertNull($avatars->urlOf(null));
        $this->assertNull($avatars->urlOf($this->user(null)));
        $this->assertSame(1, $this->callCount($resolver));

        $this->assertNull($avatars->urlOf($this->user(4)));
        $this->assertSame([['keys' => ['1', '2', '3'], 'size' => 24], ['keys' => ['4'], 'size' => 24]], $this->calls($resolver));

        $avatars->reset();
        $avatars->prime([$ada]);
        $this->assertSame(3, $this->callCount($resolver));
    }

    public function testEntriesPrimeTheirActorsAndSubjects(): void
    {
        $resolver = new CountingAvatarResolver();
        $avatars  = new ActionLogAvatars($resolver);
        $avatars->primeEntries([
            (new KernelActionLog())->setAction('a')->setActor($this->user(1))->setSubject($this->user(2)),
            (new KernelActionLog())->setAction('b')->setSubject($this->user(2)),
            'not an entry',
        ]);
        $avatars->primeEntries([]);

        $this->assertSame([['keys' => ['1', '2'], 'size' => ActionLogAvatars::SIZE]], $resolver->calls);
    }

    public function testRejectedUrlsAreDroppedAndLoggedWithoutTheUrl(): void
    {
        $logger   = $this->logger();
        $resolver = new CountingAvatarResolver(['1' => 'javascript:alert(1)', '2' => 'data:image/png;base64,AAAA', '3' => '/ok.webp']);
        $avatars  = new ActionLogAvatars($resolver, $logger);
        $avatars->prime([$this->user(1), $this->user(2), $this->user(3)]);

        $this->assertNull($avatars->urlOf($this->user(1)));
        $this->assertNull($avatars->urlOf($this->user(2)));
        $this->assertSame('/ok.webp', $avatars->urlOf($this->user(3)));
        $this->assertSame([['debug', 'AuditKit avatar URL rejected by the policy.', ['user' => '1']], ['debug', 'AuditKit avatar URL rejected by the policy.', ['user' => '2']]], $logger->records);
    }

    public function testAThrowingResolverGivesNoPicturesAndAWarning(): void
    {
        $logger  = $this->logger();
        $avatars = new ActionLogAvatars(new CountingAvatarResolver(['1' => '/a.webp'], throw: true), $logger);
        $avatars->prime([$this->user(1)]);

        $this->assertNull($avatars->urlOf($this->user(1)));
        $this->assertSame('warning', $logger->records[0][0]);

        // Without a logger it stays silent.
        $silent = new ActionLogAvatars(new CountingAvatarResolver(['1' => 'http://x.test/a.png'], throw: false));
        $silent->prime([$this->user(1)]);
        $this->assertNull($silent->urlOf($this->user(1)));
    }

    public function testTheMemoIsBoundedAndStartsAgainWhenABatchWouldGoOver(): void
    {
        $resolver = new CountingAvatarResolver();
        $avatars  = new ActionLogAvatars($resolver);

        $first = [];
        for ($id = 1; $id <= ActionLogAvatars::MAX_MEMOISED; ++$id) {
            $first[] = $this->user($id);
        }
        $avatars->prime($first);
        $avatars->prime([$this->user(1)]);
        $this->assertSame(1, $this->callCount($resolver), 'Full memo, every user known: no new call.');

        // One more user would go over the bound: the memo starts again with that batch only.
        $avatars->prime([$this->user(ActionLogAvatars::MAX_MEMOISED + 1)]);
        $this->assertSame(2, $this->callCount($resolver));
        $avatars->prime([$this->user(ActionLogAvatars::MAX_MEMOISED + 1)]);
        $this->assertSame(2, $this->callCount($resolver), 'The new batch is memoised.');
        $avatars->prime([$this->user(1)]);
        $this->assertSame(3, $this->callCount($resolver), 'Users of the dropped memo are resolved again.');
    }

    public function testTwoUserClassesWithTheSameIdNeverShareAPicture(): void
    {
        $resolver = new class implements ActionLogAvatarResolverInterface {
            /** @var list<list<string>> */
            public array $calls = [];

            public function urlFor(object $user, int $size): ?string
            {
                throw new LogicException('batch only');
            }

            public function urlsFor(array $users, int $size): array
            {
                $classes = [];
                $urls    = [];
                foreach ($users as $user) {
                    $classes[]                                = $user::class;
                    $urls[(string) AuditAvatarKey::of($user)] = $user instanceof KernelUser ? '/files/users/1.webp' : '/files/admins/1.webp';
                }
                $this->calls[] = $classes;

                return $urls;
            }
        };
        $avatars = new ActionLogAvatars($resolver);
        $user    = $this->user(1);
        $admin   = new class {
            public int $id = 1;
        };

        $avatars->prime([$user, $admin, $this->user(1)]);
        $this->assertSame([[KernelUser::class], [$admin::class]], $resolver->calls, 'One resolver call per user class.');
        $this->assertSame('/files/users/1.webp', $avatars->urlOf($user));
        $this->assertSame('/files/admins/1.webp', $avatars->urlOf($admin));
        $this->assertSame(2, $this->resolverCalls($resolver), 'Both memoised under their own class.');

        // A Doctrine proxy of the same class is the same memo entry (proxy declared at runtime, unmapped class).
        $code = 'namespace Proxies\\__CG__\\Nowo\\AuditKitBundle\\Tests\\Support;'
            . ' if (!class_exists(AvatarUser::class, false)) { class AvatarUser extends \\' . AvatarUser::class . ' {} }'
            . ' return new AvatarUser(7);';
        $proxy  = eval($code);
        $entity = new AvatarUser(7);
        $this->assertInstanceOf(AvatarUser::class, $proxy);
        $this->assertStringStartsWith('Proxies\\__CG__\\', $proxy::class);
        $avatars->urlOf($entity);
        $calls = $this->resolverCalls($resolver);
        $avatars->urlOf($proxy);
        $this->assertSame($calls, $this->resolverCalls($resolver), 'The proxy reuses the entry of its class.');
    }

    private function resolverCalls(object $resolver): int
    {
        $calls = get_object_vars($resolver)['calls'] ?? [];

        return is_array($calls) ? count($calls) : 0;
    }

    public function testResetEmptiesTheMemo(): void
    {
        $resolver = new CountingAvatarResolver(['1' => '/files/1/s64']);
        $avatars  = new ActionLogAvatars($resolver);
        $this->assertSame('/files/1/s64', $avatars->urlOf($this->user(1)));

        $avatars->reset();

        $this->assertSame('/files/1/s64', $avatars->urlOf($this->user(1)));
        $this->assertSame(2, $this->callCount($resolver), 'After reset() the user is resolved again.');
    }

    private function user(?int $id): KernelUser
    {
        $user     = new KernelUser('user' . $id . '@example.com');
        $user->id = $id;

        return $user;
    }

    private function callCount(CountingAvatarResolver $resolver): int
    {
        return count($resolver->calls);
    }

    /**
     * @return list<array{keys: list<string|null>, size: int}>
     */
    private function calls(CountingAvatarResolver $resolver): array
    {
        return $resolver->calls;
    }

    private function logger(): RecordingLogger
    {
        return new RecordingLogger();
    }
}
