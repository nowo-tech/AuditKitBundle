<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Avatar;

use Nowo\AuditKitBundle\ActionLog\AbstractActionLog;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

use function array_column;
use function array_key_exists;
use function count;
use function is_string;
use function strrpos;
use function substr;

/**
 * Since 1.11: batches and memoises the action log avatar URLs of one request. {@see prime()} resolves every user not
 * seen yet in ONE {@see ActionLogAvatarResolverInterface::urlsFor()} call per user class (one call for a timeline whose
 * users share a class); {@see urlOf()} returns the memoised,
 * sanitised URL (a miss primes that one user). Every URL goes through {@see AvatarUrlPolicy}; a rejected one becomes
 * null (a debug line names the key, never the URL). A resolver that throws gives null for the whole batch and a
 * warning: a broken picture service never breaks the timeline.
 *
 * Long-running workers (kernel reset false): the memo belongs to one unit of work. Since 1.14.1
 * {@see ActionLogAvatarsResetSubscriber} empties it at the start and end of every main request, of every Messenger
 * message and at `console.terminate`; `kernel.reset` still empties it too. Within one unit it holds at most
 * {@see self::MAX_MEMOISED} users (plus the users of the batch being primed): when a new batch would go over, the memo
 * starts again, so a long command rendering many timelines does not grow without bound.
 *
 * Since 1.14.1 the memo is keyed by user class + {@see AuditAvatarKey} (the resolver contract is unchanged: it still
 * receives one class per call and keys its result by {@see AuditAvatarKey::of()}), so two user classes with the same
 * id never share a picture. The bundle cannot see tenants: a single unit of work that renders timelines for several
 * tenants whose users share ids must call {@see reset()} between tenants (inject this service; `reset()` is supported
 * for hosts).
 *
 * @internal used by the Twig functions `nowo_audit_kit_prime_avatars()` / `nowo_audit_kit_avatar_url()`
 */
final class ActionLogAvatars implements ResetInterface
{
    /** CSS px of the timeline avatar (UiKit size `sm`). */
    public const SIZE = 24;

    /** Since 1.14.1: most users memoised at once; a batch that would go over starts the memo again. */
    public const MAX_MEMOISED = 1000;

    /** @var array<string, string|null> user class + '#' + key => sanitised URL */
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
        /** @var array<string, array<string, array{0: string, 1: object}>> $batches class => memo key => [resolver key, user] */
        $batches = [];
        $count   = 0;
        foreach ($users as $user) {
            if ($user === null) {
                continue;
            }
            $key = AuditAvatarKey::of($user);
            if ($key === null) {
                continue;
            }
            $class   = $this->userClass($user);
            $memoKey = $class . '#' . $key;
            if (!array_key_exists($memoKey, $this->urls) && !isset($batches[$class][$memoKey])) {
                $batches[$class][$memoKey] = [$key, $user];
                ++$count;
            }
        }
        if ($batches === []) {
            return;
        }

        if (count($this->urls) + $count > self::MAX_MEMOISED) {
            $this->urls = [];
        }

        // One resolver call per user class: the resolver keys its result by id only.
        foreach ($batches as $batch) {
            try {
                $resolved = $this->resolver->urlsFor(array_column($batch, 1), self::SIZE);
            } catch (Throwable $e) {
                $this->logger?->warning('AuditKit avatar resolver failed; the timeline shows no pictures.', ['exception' => $e]);
                $resolved = [];
            }

            // The key is read from the pair: an array key would be an int for numeric ids.
            foreach ($batch as $memoKey => [$key]) {
                $url                  = $resolved[$key] ?? null;
                $this->urls[$memoKey] = is_string($url) ? AvatarUrlPolicy::sanitize($url) : null;
                if (is_string($url) && $this->urls[$memoKey] === null) {
                    $this->logger?->debug('AuditKit avatar URL rejected by the policy.', ['user' => $key]);
                }
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
        $memoKey = $this->userClass($user) . '#' . $key;
        if (!array_key_exists($memoKey, $this->urls)) {
            $this->prime([$user]);
        }

        return $this->urls[$memoKey] ?? null;
    }

    /** Empties the memo (unit-of-work boundaries, `kernel.reset`, or a host between tenants inside one unit). */
    public function reset(): void
    {
        $this->urls = [];
    }

    /** The user's class, with a Doctrine proxy mapped to its entity class (same user, same memo entry). */
    private function userClass(object $user): string
    {
        $class = $user::class;
        $pos   = strrpos($class, '\\__CG__\\');

        return $pos === false ? $class : substr($class, $pos + 8);
    }
}
