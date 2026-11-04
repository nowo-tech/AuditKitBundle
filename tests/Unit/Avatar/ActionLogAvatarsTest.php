<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\Avatar;

use Nowo\AuditKitBundle\Avatar\ActionLogAvatars;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity\KernelActionLog;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\AuditKitBundle\Tests\Support\CountingAvatarResolver;
use Nowo\AuditKitBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;

use function count;

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
