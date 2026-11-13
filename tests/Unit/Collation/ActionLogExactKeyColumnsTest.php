<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\Collation;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuditKitBundle\ActionLog\AbstractIdActionLog;
use Nowo\AuditKitBundle\Collation\ActionLogExactKeyColumns;
use Nowo\MigrationsKitBundle\Collation\ExactKeyColumn;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_map;

#[CoversClass(ActionLogExactKeyColumns::class)]
final class ActionLogExactKeyColumnsTest extends TestCase
{
    /**
     * @param list<ClassMetadata<object>> $metadata
     */
    private function registry(array $metadata, bool $throws = false): ManagerRegistry
    {
        $factory = $this->createMock(ClassMetadataFactory::class);
        if ($throws) {
            $factory->method('getAllMetadata')->willThrowException(new RuntimeException('broken host mapping'));
        } else {
            $factory->method('getAllMetadata')->willReturn($metadata);
        }

        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getMetadataFactory')->willReturn($factory);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagers')->willReturn(['default' => $manager]);

        return $registry;
    }

    /**
     * @param class-string $class
     *
     * @return ClassMetadata<object>
     */
    private function metadataFor(string $class, string $table, string $actor = 'actor_id', string $subject = 'subject_id'): ClassMetadata
    {
        $metadata = new ClassMetadata($class);
        $metadata->setPrimaryTable(['name' => $table]);
        $metadata->mapField(['fieldName' => 'actorId', 'columnName' => $actor, 'type' => 'string', 'length' => 64, 'nullable' => true]);
        $metadata->mapField(['fieldName' => 'subjectId', 'columnName' => $subject, 'type' => 'string', 'length' => 64, 'nullable' => true]);

        return $metadata;
    }

    /**
     * @return list<string>
     */
    private function describe(ManagerRegistry $registry): array
    {
        return array_map(
            static fn (ExactKeyColumn $c): string => $c->describe(),
            (new ActionLogExactKeyColumns($registry))->exactKeyColumns(),
        );
    }

    public function testDeclaresTheHostTableAndColumnNamesFromTheMapping(): void
    {
        // The bundle owns no table: AbstractIdActionLog is a mapped superclass, so the host names both the table and
        // the columns. A literal would be wrong on every host.
        $registry = $this->registry([$this->metadataFor(HostActionLog::class, 'app_action_log', 'who_id', 'about_id')]);

        self::assertSame(['app_action_log.who_id', 'app_action_log.about_id'], $this->describe($registry));
    }

    public function testEntityModeDeclaresNothing(): void
    {
        // Entity blame mode maps actor / subject as relations to the host user entity: no string key, nothing to check.
        $registry = $this->registry([$this->metadataFor(UnrelatedEntity::class, 'orders')]);

        self::assertSame([], $this->describe($registry));
    }

    public function testABrokenHostMappingIsNotThisChecksBusiness(): void
    {
        self::assertSame([], $this->describe($this->registry([], true)));
    }

    public function testEveryDeclaredColumnCarriesItsOwnerAndPurpose(): void
    {
        $registry = $this->registry([$this->metadataFor(HostActionLog::class, 'app_action_log')]);
        foreach ((new ActionLogExactKeyColumns($registry))->exactKeyColumns() as $column) {
            self::assertSame(ActionLogExactKeyColumns::OWNER, $column->owner);
            self::assertNotSame('', $column->purpose);
            self::assertTrue($column->active);
        }
    }
}

/**
 * Host entity extending the id-mode mapped superclass.
 */
class HostActionLog extends AbstractIdActionLog
{
}

/**
 * A host entity that has nothing to do with the action log.
 */
class UnrelatedEntity
{
}
