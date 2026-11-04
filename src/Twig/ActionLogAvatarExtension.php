<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Twig;

use Generator;
use Nowo\AuditKitBundle\Avatar\ActionLogAvatars;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

use function is_iterable;

/**
 * Since 1.11: `nowo_audit_kit_prime_avatars(entries)` (one resolver call for the actors and subjects of a timeline;
 * prints nothing) and `nowo_audit_kit_avatar_url(user)` (the memoised, sanitised URL or null), for the action log
 * timeline and for hosts that draw their own avatar. Not `is_safe`: the URL is escaped by autoescape.
 */
final class ActionLogAvatarExtension extends AbstractExtension
{
    public function __construct(private readonly ActionLogAvatars $avatars)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('nowo_audit_kit_prime_avatars', $this->prime(...)),
            new TwigFunction('nowo_audit_kit_avatar_url', $this->avatars->urlOf(...)),
        ];
    }

    /**
     * Primes the avatars of the entries and returns an empty string (usable in `{{ }}` and `{% do %}`). A generator is
     * left alone (it can be iterated once, by the timeline).
     */
    public function prime(mixed $entries): string
    {
        if (is_iterable($entries) && !$entries instanceof Generator) {
            $this->avatars->primeEntries($entries);
        }

        return '';
    }
}
