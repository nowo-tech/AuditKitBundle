<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Avatar;

use Nowo\AuditKitBundle\ActionLog\AbstractActionLog;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

use function array_column;
use function array_key_exists;
use function is_string;

/**
 * Since 1.11: batches and memoises the action log avatar URLs of one request. {@see prime()} resolves every user not
 * seen yet in ONE {@see ActionLogAvatarResolverInterface::urlsFor()} call; {@see urlOf()} returns the memoised,
 * sanitised URL (a miss primes that one user). Every URL goes through {@see AvatarUrlPolicy}; a rejected one becomes
 * null (a debug line names the key, never the URL). A resolver that throws gives null for the whole batch and a
 * warning: a broken picture service never breaks the timeline. Reset by `kernel.reset` (long-running workers).
 *
 * @internal used by the Twig functions `nowo_audit_kit_prime_avatars()` / `nowo_audit_kit_avatar_url()`
 */
final class ActionLogAvatars implements ResetInterface
{
    /** CSS px of the timeline avatar (UiKit size `sm`). */
    public const SIZE = 24;

    /** @var array<array-key, string|null> key => sanitised URL (numeric keys are ints) */
    private array $urls = [];

    public function __construct(
        private readonly ActionLogAvatarResolverInterface $resolver,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Primes the actors and subjects of action log entries (null users and users without an id are skipped).
     *
     * @param iterable<mixed> $entries
     */
    public function primeEntries(iterable $entries): void
    {
        $users = [];
        foreach ($entries as $entry) {
            if ($entry instanceof AbstractActionLog) {
                $users[] = $entry->getActor();
                $users[] = $entry->getSubject();
            }
        }

        $this->prime($users);
    }

    /**
     * Resolves every not-yet-known user of the list in one urlsFor() call (none when every user is known).
     *
     * @param iterable<object|null> $users
     */
    public function prime(iterable $users): void
    {
        $batch = [];
        foreach ($users as $user) {
            if ($user === null) {
                continue;
            }
            $key = AuditAvatarKey::of($user);
            if ($key !== null && !array_key_exists($key, $this->urls) && !isset($batch[$key])) {
                $batch[$key] = [$key, $user];
            }
        }
        if ($batch === []) {
            return;
        }

        try {
            $resolved = $this->resolver->urlsFor(array_column($batch, 1), self::SIZE);
        } catch (Throwable $e) {
            $this->logger?->warning('AuditKit avatar resolver failed; the timeline shows no pictures.', ['exception' => $e]);
            $resolved = [];
        }

        // The key is read from the pair: an array key would be an int for numeric ids.
        foreach ($batch as [$key]) {
            $url              = $resolved[$key] ?? null;
            $this->urls[$key] = is_string($url) ? AvatarUrlPolicy::sanitize($url) : null;
            if (is_string($url) && $this->urls[$key] === null) {
                $this->logger?->debug('AuditKit avatar URL rejected by the policy.', ['user' => $key]);
            }
        }
    }

    /** The memoised, sanitised URL of a user (null for null users, users without an id or without a picture). */
    public function urlOf(?object $user): ?string
    {
        if ($user === null) {
            return null;
        }
        $key = AuditAvatarKey::of($user);
        if ($key === null) {
            return null;
        }
        if (!array_key_exists($key, $this->urls)) {
            $this->prime([$user]);
        }

        return $this->urls[$key] ?? null;
    }

    public function reset(): void
    {
        $this->urls = [];
    }
}
