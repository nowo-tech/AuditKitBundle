<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\Doctrine;

use DateTimeImmutable;
use Nowo\AuditKitBundle\Attribute\Auditable;
use Nowo\AuditKitBundle\Doctrine\AuditablePropertyResolver;
use Nowo\AuditKitBundle\Model\AuditableTrait;
use Nowo\AuditKitBundle\Model\TimestampableTrait;
use Nowo\AuditKitBundle\Tests\Support\ProfileRegistryFactory;
use Nowo\AuditKitBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\PropertyAccess\Exception\NoSuchPropertyException;

use function array_map;

/**
 * Since 1.13: opting a class in with `auditable_entities` or (with `attribute_opt_in`) `#[Auditable]`, without a bundle
 * interface or trait; opt-out inherited by subclasses and proxies; guarded writes on the opt-in path (audit KO).
 */
final class AuditablePropertyResolverOptInTest extends TestCase
{
    public function testTheAttributeAloneIsIgnoredByDefault(): void
    {
        $this->assertFalse((new AuditablePropertyResolver())->isAuditable(new OptInByAttribute()), '1.12 behaviour');
    }

    public function testTheAttributeAloneOptsInWithTheSwitch(): void
    {
        $resolver = new AuditablePropertyResolver(null, [], true);
        $entity   = new OptInByAttribute();

        $this->assertTrue($resolver->isAuditable($entity));
        $this->assertTrue($resolver->isAuditable(new ChildOfOptInByAttribute()), 'the attribute of a parent (a proxy) counts');
        $this->assertTrue($resolver->hasTimestampFields($entity, ProfileRegistryFactory::defaultFields()));
        $this->assertFalse($resolver->hasBlameFields($entity, ProfileRegistryFactory::defaultFields()));
    }

    public function testWithoutConfigurationAPlainClassIsNotAudited(): void
    {
        $this->assertFalse((new AuditablePropertyResolver(null, [], true))->isAuditable(new PlainDomainRecord()));
    }

    public function testConfiguredClassesParentsAndInterfacesOptIn(): void
    {
        $byClass     = new AuditablePropertyResolver(null, [PlainDomainRecord::class]);
        $byInterface = new AuditablePropertyResolver(null, [HostAuditedMarker::class]);

        $this->assertTrue($byClass->isAuditable(new PlainDomainRecord()));
        $this->assertTrue($byClass->isAuditable(new ChildDomainRecord()), 'a subclass of a listed class');
        $this->assertFalse($byClass->isAuditable(new MarkedDomainRecord()));
        $this->assertTrue($byInterface->isAuditable(new MarkedDomainRecord()));
        $this->assertFalse($byInterface->isAuditable(new PlainDomainRecord()));
    }

    public function testADisabledAttributeAlwaysOptsOutAlsoFromAParent(): void
    {
        $resolver = new AuditablePropertyResolver(null, [OptOutByAttribute::class, ChildOfDisabledWithTrait::class], true);

        $this->assertFalse($resolver->isAuditable(new OptOutByAttribute()), 'listed but disabled');
        $this->assertFalse($resolver->isAuditable(new DisabledWithTrait()), 'trait but disabled (unchanged)');
        $this->assertFalse($resolver->isAuditable(new ChildOfDisabledWithTrait()), 'a subclass or proxy of a disabled class');
    }

    public function testAnOptInClassGetsOnlyTheFieldsItHasAndOneWarning(): void
    {
        $logger   = new RecordingLogger();
        $resolver = new AuditablePropertyResolver(null, [CreatedAtOnlyRecord::class, WrongTypeRecord::class], false, $logger);
        $fields   = ProfileRegistryFactory::defaultFields();
        $now      = new DateTimeImmutable('2026-11-10 10:00:00');

        $record = new CreatedAtOnlyRecord();
        $this->assertTrue($resolver->hasTimestampFields($record, $fields));
        foreach ([1, 2] as $flush) {
            $resolver->setTimestamp($record, 'created_at', $now, $fields);
            $resolver->setTimestamp($record, 'updated_at', $now, $fields);
            $resolver->setBlame($record, 'created_by', new stdClass(), $fields);
        }
        $this->assertSame($now, $record->createdAt);

        $wrong = new WrongTypeRecord();
        $resolver->setTimestamp($wrong, 'created_at', $now, $fields);
        $this->assertSame('', $wrong->createdAt);

        $this->assertSame([
            [CreatedAtOnlyRecord::class, 'updatedAt', 'is not writable'],
            [CreatedAtOnlyRecord::class, 'createdBy', 'is not writable'],
            [WrongTypeRecord::class, 'createdAt', 'does not accept the value'],
        ], array_map(static fn (array $record): array => [$record[2]['class'], $record[2]['property'], $record[2]['reason']], $logger->records));
    }

    public function testAContractClassIsWrittenAsBefore(): void
    {
        $this->expectException(NoSuchPropertyException::class);

        (new AuditablePropertyResolver(null, [], true))->setTimestamp(new TimestampOnlyContract(), 'created_at', new DateTimeImmutable(), ['created_at' => 'missing', 'updated_at' => 'updatedAt', 'created_by' => 'createdBy', 'updated_by' => 'updatedBy']);
    }
}

interface HostAuditedMarker
{
}

#[Auditable]
class OptInByAttribute
{
    public ?DateTimeImmutable $createdAt = null;

    public ?DateTimeImmutable $updatedAt = null;
}

final class ChildOfOptInByAttribute extends OptInByAttribute
{
}

class PlainDomainRecord
{
    public ?DateTimeImmutable $createdAt = null;

    public ?DateTimeImmutable $updatedAt = null;
}

final class ChildDomainRecord extends PlainDomainRecord
{
}

final class MarkedDomainRecord implements HostAuditedMarker
{
}

#[Auditable(enabled: false)]
final class OptOutByAttribute
{
}

#[Auditable(enabled: false)]
class DisabledWithTrait
{
    use AuditableTrait;
}

final class ChildOfDisabledWithTrait extends DisabledWithTrait
{
}

final class CreatedAtOnlyRecord
{
    public ?DateTimeImmutable $createdAt = null;
}

final class WrongTypeRecord
{
    public string $createdAt = '';
}

final class TimestampOnlyContract
{
    use TimestampableTrait;
}
