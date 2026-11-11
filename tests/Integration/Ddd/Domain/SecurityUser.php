<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Integration\Ddd\Domain;

use Stringable;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/** Firewall user of the DDD host: not a Doctrine entity; its id is a UUID object. */
final readonly class SecurityUser implements UserInterface, Stringable
{
    public function __construct(private Uuid $id, private string $email)
    {
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return $this->email !== '' ? $this->email : 'anonymous';
    }

    public function __toString(): string
    {
        return $this->email;
    }
}
