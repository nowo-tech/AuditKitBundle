<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\Blame;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ObjectManager;
use Nowo\AuditKitBundle\Blame\EntityReferenceBlameValueResolver;
use Nowo\AuditKitBundle\Blame\SecurityUserIdBlameValueResolver;
use Nowo\AuditKitBundle\Profile\ProfileSettings;
use Nowo\AuditKitBundle\Security\CurrentUserResolverInterface;
use Nowo\AuditKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Security\Core\User\UserInterface;

final class BlameValueResolverTest extends TestCase
{
    public function testEntityReferenceReturnsTheReferenceOfAnEntityUser(): void
    {
        $user      = new BlameEntityUser(5);
        $reference = new BlameEntityUser(5);
        $em        = $this->entityManager();
        $em->expects($this->once())->method('getReference')->with(BlameEntityUser::class, 5)->willReturn($reference);

        $resolver = new EntityReferenceBlameValueResolver($this->current($user), $em);

        $this->assertSame($reference, $resolver->resolve($this->profile(), $em));
    }

    public function testEntityReferenceIgnoresASecurityUserThatIsNotTheEntity(): void
    {
        $em = $this->entityManager();
        $em->expects($this->never())->method('getReference');

        $resolver = new EntityReferenceBlameValueResolver($this->current(new BlameSecurityUser(5)), $em);

        $this->assertNull($resolver->resolve($this->profile(), $em));
    }

    public function testEntityReferenceWithoutUserIsNull(): void
    {
        $em = $this->entityManager();

        $this->assertNull((new EntityReferenceBlameValueResolver($this->current(null), $em))->resolve($this->profile(), $em));
    }

    public function testEntityReferenceFallsBackToTheUserAndLogsWhenNoReferenceCanBeBuilt(): void
    {
        $user = new BlameEntityUser(5);
        $em   = $this->createMock(EntityManagerInterface::class);
        $em->method('getClassMetadata')->willThrowException(new RuntimeException('no metadata'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $resolver = new EntityReferenceBlameValueResolver($this->current($user), $em, $logger);

        $this->assertSame($user, $resolver->resolve($this->profile(), $em));
    }

    public function testEntityReferenceReturnsTheUserWhenItHasNoIdYet(): void
    {
        $user = new BlameEntityUser(null);
        $em   = $this->entityManager([]);
        $em->expects($this->never())->method('getReference');

        $this->assertSame($user, (new EntityReferenceBlameValueResolver($this->current($user), $em))->resolve($this->profile(), $em));
    }

    public function testSecurityUserIdBuildsAReferenceFromTheWrapperId(): void
    {
        $reference = new BlameEntityUser(7);
        $em        = $this->entityManager();
        $em->expects($this->once())->method('getReference')->with(BlameEntityUser::class, 7)->willReturn($reference);

        $resolver = new SecurityUserIdBlameValueResolver($this->current(new BlameSecurityUser(7)), $em);

        $this->assertSame($reference, $resolver->resolve($this->profile(), $em));
    }

    public function testSecurityUserIdAcceptsStringIds(): void
    {
        $reference = new BlameEntityUser(7);
        $em        = $this->entityManager();
        $em->expects($this->once())->method('getReference')->with(BlameEntityUser::class, '0190-uuid')->willReturn($reference);

        $resolver = new SecurityUserIdBlameValueResolver($this->current(new BlameSecurityUser('0190-uuid')), $em);

        $this->assertSame($reference, $resolver->resolve($this->profile(), $em));
    }

    public function testSecurityUserIdUsesTheFallbackManagerWhenTheEventManagerIsNotOrm(): void
    {
        $reference = new BlameEntityUser(7);
        $em        = $this->entityManager();
        $em->expects($this->once())->method('getReference')->willReturn($reference);

        $resolver = new SecurityUserIdBlameValueResolver($this->current(new BlameSecurityUser(7)), $em);

        $this->assertSame($reference, $resolver->resolve($this->profile(), $this->createMock(ObjectManager::class)));
    }

    public function testSecurityUserIdDelegatesForAnEntityUser(): void
    {
        $user      = new BlameEntityUser(5);
        $reference = new BlameEntityUser(5);
        $em        = $this->entityManager();
        $em->expects($this->once())->method('getReference')->with(BlameEntityUser::class, 5)->willReturn($reference);

        $resolver = new SecurityUserIdBlameValueResolver($this->current($user), $em);

        $this->assertSame($reference, $resolver->resolve($this->profile(), $em));
    }

    public function testSecurityUserIdIgnoresUsersWithoutAUsableId(): void
    {
        $em = $this->entityManager();
        $em->expects($this->never())->method('getReference');

        $this->assertNull((new SecurityUserIdBlameValueResolver($this->current(null), $em))->resolve($this->profile(), $em));
        $this->assertNull((new SecurityUserIdBlameValueResolver($this->current(new BlameSecurityUser('')), $em))->resolve($this->profile(), $em));
        $this->assertNull((new SecurityUserIdBlameValueResolver($this->current(new BlameSecurityUser(null)), $em))->resolve($this->profile(), $em));
        $this->assertNull((new SecurityUserIdBlameValueResolver($this->current(new BlameIdlessUser()), $em))->resolve($this->profile(), $em));
    }

    public function testSecurityUserIdLogsAndLeavesBlameNullWhenTheReferenceFails(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getReference')->willThrowException(new RuntimeException('unknown class'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $resolver = new SecurityUserIdBlameValueResolver($this->current(new BlameSecurityUser(7)), $em, $logger);

        $this->assertNull($resolver->resolve($this->profile(), $em));
    }

    private function profile(): ProfileSettings
    {
        return ProfileRegistryFactory::single(BlameEntityUser::class)->getDefault();
    }

    /**
     * @param array<string, mixed> $identifierValues
     *
     * @return EntityManagerInterface&MockObject
     */
    private function entityManager(array $identifierValues = ['id' => 5]): EntityManagerInterface
    {
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getSingleIdentifierFieldName')->willReturn('id');
        $metadata->method('getIdentifierValues')->willReturn($identifierValues);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getClassMetadata')->willReturn($metadata);

        return $em;
    }

    private function current(?UserInterface $user): CurrentUserResolverInterface
    {
        return new class($user) implements CurrentUserResolverInterface {
            public function __construct(private readonly ?UserInterface $user)
            {
            }

            public function resolve(): ?UserInterface
            {
                return $this->user;
            }
        };
    }
}

/** A Doctrine user entity (the profile `user_class`). */
class BlameEntityUser implements UserInterface
{
    public function __construct(public int|string|null $id)
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
        return 'entity-' . $this->id;
    }
}

/** A firewall user that is not the Doctrine entity (hexagonal host): only its id is shared. */
final readonly class BlameSecurityUser implements UserInterface
{
    public function __construct(private int|string|null $id)
    {
    }

    public function getId(): int|string|null
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
        return 'security-' . $this->id;
    }
}

final class BlameIdlessUser implements UserInterface
{
    public function getRoles(): array
    {
        return [];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return 'idless';
    }
}
