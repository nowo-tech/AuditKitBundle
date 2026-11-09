<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\Doctrine;

use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\Persistence\ObjectManager;
use Nowo\AuditKitBundle\Blame\BlameValueResolverInterface;
use Nowo\AuditKitBundle\Doctrine\AuditableEntityListener;
use Nowo\AuditKitBundle\Doctrine\AuditablePropertyResolver;
use Nowo\AuditKitBundle\Model\TimestampableInterface;
use Nowo\AuditKitBundle\Profile\ProfileSettings;
use Nowo\AuditKitBundle\Security\CurrentUserResolverInterface;
use Nowo\AuditKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Security\Core\User\UserInterface;

/** Hexagonal host: no Doctrine user entity, blame stored as a scalar id through its own ports (1.12). */
final class AuditableEntityListenerPortsTest extends TestCase
{
    public function testHostPortsBlameWithAScalarIdWithoutAUserEntity(): void
    {
        $actor   = new PortsSecurityUser('0190a1b2-actor');
        $current = new class($actor) implements CurrentUserResolverInterface {
            public function __construct(private readonly UserInterface $user)
            {
            }

            public function resolve(): UserInterface
            {
                return $this->user;
            }
        };
        $blame = new class($current) implements BlameValueResolverInterface {
            public function __construct(private readonly CurrentUserResolverInterface $current)
            {
            }

            public function resolve(ProfileSettings $profile, ObjectManager $objectManager): ?string
            {
                $user = $this->current->resolve();

                return $user instanceof PortsSecurityUser ? $user->id : null;
            }
        };

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('getReference');
        $em->expects($this->never())->method('getClassMetadata');

        $listener = new AuditableEntityListener(
            registry: ProfileRegistryFactory::single(PortsDomainUserRecord::class, ['fields' => ['created_by' => 'createdById', 'updated_by' => 'updatedById']]),
            propertyResolver: new AuditablePropertyResolver(),
            currentUserResolver: $current,
            entityManager: $em,
            clock: new NativeClock(),
            logger: new NullLogger(),
            blameValueResolver: $blame,
        );

        $document = new PortsDocument();
        $listener->prePersist($document, new PrePersistEventArgs($document, $em));

        $this->assertSame('0190a1b2-actor', $document->createdById);
        $this->assertSame('0190a1b2-actor', $document->updatedById);
        $this->assertInstanceOf(DateTimeInterface::class, $document->getCreatedAt());

        $document->updatedById = 'someone-else';
        $changeSet             = [];
        $listener->preUpdate($document, new PreUpdateEventArgs($document, $em, $changeSet));
        $this->assertSame('0190a1b2-actor', $document->updatedById);
    }
}

/** Placeholder class for the profile (the host has no Doctrine user entity). */
final class PortsDomainUserRecord
{
}

final readonly class PortsSecurityUser implements UserInterface
{
    /** @param non-empty-string $id */
    public function __construct(public string $id)
    {
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
        return $this->id;
    }
}

final class PortsDocument implements TimestampableInterface
{
    public ?string $createdById = null;

    public ?string $updatedById = null;

    private ?DateTimeInterface $createdAt = null;

    private ?DateTimeInterface $updatedAt = null;

    public function getCreatedAt(): ?DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeInterface $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    public function getUpdatedAt(): ?DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(DateTimeInterface $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
    }
}
