<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\Doctrine;

use DateTimeImmutable;
use Nowo\AuditKitBundle\Attribute\Auditable;
use Nowo\AuditKitBundle\Doctrine\AuditablePropertyResolver;
use Nowo\AuditKitBundle\Model\AuditableTrait;
use Nowo\AuditKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;

/** Since 1.13: opting a class in with `#[Auditable]` or `auditable_entities`, without a bundle interface or trait. */
final class AuditablePropertyResolverOptInTest extends TestCase
{
    public function testTheAttributeAloneOptsIn(): void
    {
        $resolver = new AuditablePropertyResolver();
        $entity   = new OptInByAttribute();

        $this->assertTrue($resolver->isAuditable($entity));
        $this->assertTrue($resolver->hasTimestampFields($entity, ProfileRegistryFactory::defaultFields()));
        $this->assertFalse($resolver->hasBlameFields($entity, ProfileRegistryFactory::defaultFields()));
    }

    public function testWithoutConfigurationAPlainClassIsNotAudited(): void
    {
        $this->assertFalse((new AuditablePropertyResolver())->isAuditable(new PlainDomainRecord()));
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

    public function testADisabledAttributeAlwaysOptsOut(): void
    {
        $resolver = new AuditablePropertyResolver(null, [OptOutByAttribute::class]);

        $this->assertFalse($resolver->isAuditable(new OptOutByAttribute()), 'listed but disabled');
        $this->assertFalse($resolver->isAuditable(new DisabledWithTrait()), 'trait but disabled (unchanged)');
    }
}

interface HostAuditedMarker
{
}

#[Auditable]
final class OptInByAttribute
{
    public ?DateTimeImmutable $createdAt = null;

    public ?DateTimeImmutable $updatedAt = null;
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
final class DisabledWithTrait
{
    use AuditableTrait;
}
