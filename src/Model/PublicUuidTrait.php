<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Model;

use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use Nowo\AuditKitBundle\Doctrine\PublicUuidListener;
use Symfony\Component\Uid\Uuid;

use function sprintf;

/**
 * Public opaque identifier for URL routing: `uuid` column (string, length 36, not null; empty until assigned).
 *
 * Pair with {@see PublicUuidInterface} so {@see PublicUuidListener} fills it on persist.
 */
trait PublicUuidTrait
{
    #[ORM\Column(length: 36)]
    private string $uuid = '';

    public function getUuid(): string
    {
        return $this->uuid;
    }

    /**
     * Replaces any auto-generated value from {@see ensureUuid()}. The value is trimmed; an empty value is a no-op.
     *
     * @throws InvalidArgumentException `invalid_uuid:<value>` when the value is not a valid RFC 4122 UUID
     */
    public function assignUuid(string $uuid): void
    {
        $uuid = trim($uuid);
        if ($uuid === '') {
            return;
        }

        if (!Uuid::isValid($uuid)) {
            throw new InvalidArgumentException(sprintf('invalid_uuid:%s', $uuid));
        }

        // @igor-ignore - Doctrine entity trait; instance-scoped, not a shared service.
        $this->uuid = $uuid;
    }

    /** Assigns a new UUID v7 (RFC 4122 string) when missing; called from constructors or `prePersist`. */
    public function ensureUuid(): void
    {
        if ($this->uuid === '') {
            // @igor-ignore - Doctrine entity trait; instance-scoped, not a shared service.
            $this->uuid = Uuid::v7()->toRfc4122();
        }
    }
}
