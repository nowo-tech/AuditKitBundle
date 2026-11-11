<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\Doctrine;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\Persistence\ObjectManager;
use InvalidArgumentException;
use Nowo\AuditKitBundle\Blame\ActorIdBlameIdResolver;
use Nowo\AuditKitBundle\Blame\BlameIdResolverInterface;
use Nowo\AuditKitBundle\Blame\UserIdBlameValueResolver;
use Nowo\AuditKitBundle\Doctrine\AuditableEntityListener;
use Nowo\AuditKitBundle\Doctrine\AuditablePropertyResolver;
use Nowo\AuditKitBundle\Profile\ProfileRegistry;
use Nowo\AuditKitBundle\Profile\UnknownProfileException;
use Nowo\AuditKitBundle\Security\AuditActor;
use Nowo\AuditKitBundle\Security\CurrentActorProviderInterface;
use Nowo\AuditKitBundle\Security\CurrentUserResolverInterface;
use Nowo\AuditKitBundle\Security\SecurityCurrentActorProvider;
use Nowo\AuditKitBundle\Tests\Integration\Ddd\Domain\SecurityUser;
use Nowo\AuditKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

use function count;

/**
 * Since 1.15, `blame_mode: id`: the framework-free ports ({@see CurrentActorProviderInterface},
 * {@see BlameIdResolverInterface}) write exactly what the 1.14 ports wrote (Symfony user + {@see UserIdBlameValueResolver}).
 */
final class AuditableEntityListenerIdPortsTest extends TestCase
{
    private const ALICE = '0192f1a4-7d3e-7c4b-9a1e-1b2c3d4e5f60';
    private const BOB   = '0192f1a4-7d3e-7c4b-9a1e-1b2c3d4e5f61';

    /**
     * @return iterable<string, array{?UserInterface}>
     */
    public static function users(): iterable
    {
        yield 'guest' => [null];
        yield 'user without getId()' => [new InMemoryUser('mallory@example.com', null)];
        yield 'staff alice (uuid)' => [new SecurityUser(Uuid::fromString(self::ALICE), 'alice@example.com')];
        yield 'staff bob (uuid)' => [new SecurityUser(Uuid::fromString(self::BOB), 'bob@example.com')];
        yield 'int id' => [new IdPortsIntUser(7)];
        yield 'unusable int id' => [new IdPortsIntUser(0)];
        yield 'robot (profile without blame)' => [new IdPortsRobotUser('r-1')];
    }

    /**
     * Equivalence: the 1.15 id ports (default adapters) and the 1.14 path (Symfony user port + UserIdBlameValueResolver)
     * select the same profile and write the same blame and timestamps.
     */
    #[DataProvider('users')]
    public function testIdPortsWriteWhatThe114PortsWrote(?UserInterface $user): void
    {
        $this->assertSame($this->oracle114($user), $this->write($this->idPortsListener($this->current($user))));
        $this->assertSame($this->oracle114($user), $this->write($this->legacyListener($this->current($user))));
    }

    public function testTheComparisonIsNotVacuous(): void
    {
        $alice = new SecurityUser(Uuid::fromString(self::ALICE), 'alice@example.com');
        $this->assertSame([self::ALICE, self::ALICE, false, self::ALICE], $this->write($this->idPortsListener($this->current($alice))));
        $this->assertSame(['7', '7', true, '7'], $this->write($this->idPortsListener($this->current(new IdPortsIntUser(7)))));
        $this->assertSame([null, null, true, null], $this->write($this->idPortsListener($this->current(new IdPortsRobotUser('r-1')))));
    }

    /** Kept-alive listener (worker mode): alternating users never inherit the previous user's blame or profile. */
    public function testAlternatingUsersOnOneListenerNeverLeak(): void
    {
        $users = [];
        foreach (self::users() as [$user]) {
            $users[] = $user;
        }
        $current  = new IdPortsMutableCurrentUser();
        $listener = $this->idPortsListener($current);

        for ($i = 0; $i < 700; ++$i) {
            $user          = $users[$i % count($users)];
            $current->user = $user;
            $this->assertSame($this->oracle114($user), $this->write($listener), 'iteration ' . $i);
        }
    }

    public function testHostActorPortAloneDrivesProfileAndBlame(): void
    {
        $actors   = new IdPortsMutableActors();
        $listener = new AuditableEntityListener(
            registry: $this->registry(),
            propertyResolver: new AuditablePropertyResolver(null, [IdPortsRecord::class]),
            currentUserResolver: null,
            entityManager: $this->createStub(EntityManagerInterface::class),
            clock: new NativeClock(),
            currentActorProvider: $actors,
            blameIdResolver: new ActorIdBlameIdResolver(),
        );
        $em = $this->createStub(EntityManagerInterface::class);

        $actors->actor = new AuditActor('domain-user-1');
        $record        = new IdPortsRecord();
        $listener->prePersist($record, new PrePersistEventArgs($record, $em));
        $this->assertSame('domain-user-1', $record->createdBy);
        $this->assertNotNull($record->createdAt);

        $actors->actor = new AuditActor('domain-user-2', 'staff');
        $record        = new IdPortsRecord();
        $listener->prePersist($record, new PrePersistEventArgs($record, $em));
        $this->assertSame('domain-user-2', $record->createdBy);
        $this->assertNull($record->createdAt, 'staff profile: timestampable false');

        $actors->actor = new AuditActor('domain-user-3', 'nope');
        $this->expectException(UnknownProfileException::class);
        $listener->prePersist($record, new PrePersistEventArgs($record, $em));
    }

    public function testNeedsAnActorSource(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(CurrentActorProviderInterface::class);

        new AuditableEntityListener(
            registry: $this->registry(),
            propertyResolver: new AuditablePropertyResolver(),
            currentUserResolver: null,
            entityManager: $this->createStub(EntityManagerInterface::class),
            clock: new NativeClock(),
            blameIdResolver: new ActorIdBlameIdResolver(),
        );
    }

    public function testNeedsABlameSource(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(BlameIdResolverInterface::class);

        new AuditableEntityListener(
            registry: $this->registry(),
            propertyResolver: new AuditablePropertyResolver(),
            currentUserResolver: null,
            entityManager: $this->createStub(EntityManagerInterface::class),
            clock: new NativeClock(),
            currentActorProvider: new IdPortsMutableActors(),
        );
    }

    /**
     * The 1.14 listener algorithm, written out: profile of the user's class (else the default), blame from
     * {@see UserIdBlameValueResolver} with that profile, timestamps per profile.
     *
     * @return array{?string, ?string, bool, ?string}
     */
    private function oracle114(?UserInterface $user): array
    {
        $registry = $this->registry();
        $profile  = ($user instanceof UserInterface ? $registry->resolveForObject($user) : null) ?? $registry->getDefault();
        $blame    = $profile->blameable
            ? (new UserIdBlameValueResolver($this->current($user)))->resolve($profile, $this->createStub(ObjectManager::class))
            : null;

        return [$blame, $blame, $profile->timestampable, $profile->blameable ? $blame : null];
    }

    /**
     * @return array{?string, ?string, bool, ?string} created_by, updated_by after persist, timestamps set, updated_by after update
     */
    private function write(AuditableEntityListener $listener): array
    {
        $em     = $this->createStub(EntityManagerInterface::class);
        $record = new IdPortsRecord();
        $listener->prePersist($record, new PrePersistEventArgs($record, $em));
        $persisted = [$record->createdBy, $record->updatedBy, $record->createdAt instanceof DateTimeImmutable];

        $record->updatedBy = 'stale';
        $changeSet         = [];
        $listener->preUpdate($record, new PreUpdateEventArgs($record, $em, $changeSet));

        return [...$persisted, $record->updatedBy === 'stale' ? null : $record->updatedBy];
    }

    private function idPortsListener(CurrentUserResolverInterface $current): AuditableEntityListener
    {
        return new AuditableEntityListener(
            registry: $this->registry(),
            propertyResolver: new AuditablePropertyResolver(null, [IdPortsRecord::class]),
            currentUserResolver: null,
            entityManager: $this->createStub(EntityManagerInterface::class),
            clock: new NativeClock(),
            currentActorProvider: new SecurityCurrentActorProvider($current, $this->registry()),
            blameIdResolver: new ActorIdBlameIdResolver(),
        );
    }

    private function legacyListener(CurrentUserResolverInterface $current): AuditableEntityListener
    {
        return new AuditableEntityListener(
            registry: $this->registry(),
            propertyResolver: new AuditablePropertyResolver(null, [IdPortsRecord::class]),
            currentUserResolver: $current,
            entityManager: $this->createStub(EntityManagerInterface::class),
            clock: new NativeClock(),
            blameValueResolver: new UserIdBlameValueResolver($current),
        );
    }

    private function current(?UserInterface $user): CurrentUserResolverInterface
    {
        $current       = new IdPortsMutableCurrentUser();
        $current->user = $user;

        return $current;
    }

    private function registry(): ProfileRegistry
    {
        $profile = ['enabled' => true, 'fields' => ProfileRegistryFactory::defaultFields(), 'timestamp_type' => 'datetime_immutable', 'blameable' => true, 'timestampable' => true];

        return ProfileRegistryFactory::fromProfiles([
            'default' => ['user_class' => UserInterface::class] + $profile,
            'staff'   => ['user_class' => SecurityUser::class, 'timestampable' => false] + $profile,
            'robots'  => ['user_class' => IdPortsRobotUser::class, 'blameable' => false] + $profile,
        ]);
    }
}

/** @internal */
final class IdPortsRecord
{
    public ?DateTimeImmutable $createdAt = null;

    public ?DateTimeImmutable $updatedAt = null;

    public ?string $createdBy = null;

    public ?string $updatedBy = null;
}

/** @internal */
final class IdPortsMutableCurrentUser implements CurrentUserResolverInterface
{
    public ?UserInterface $user = null;

    public function resolve(): ?UserInterface
    {
        return $this->user;
    }
}

/** @internal */
final class IdPortsMutableActors implements CurrentActorProviderInterface
{
    public ?AuditActor $actor = null;

    public function currentActor(): ?AuditActor
    {
        return $this->actor;
    }
}

/** @internal */
final readonly class IdPortsIntUser implements UserInterface
{
    public function __construct(private int $id)
    {
    }

    public function getId(): int
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
        return 'int-' . $this->id . '@example.com';
    }
}

/** @internal */
final readonly class IdPortsRobotUser implements UserInterface
{
    public function __construct(private string $id)
    {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getRoles(): array
    {
        return ['ROLE_ROBOT'];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return 'robot-' . $this->id;
    }
}
