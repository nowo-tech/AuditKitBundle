<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit;

use DateTimeImmutable;
use Nowo\AuditKitBundle\Attribute\Auditable;
use Nowo\AuditKitBundle\Doctrine\AuditablePropertyResolver;
use Nowo\AuditKitBundle\Model\AuditableTrait;
use Nowo\AuditKitBundle\Model\TimestampableTrait;
use Nowo\AuditKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;
use stdClass;

final class AuditablePropertyResolverTest extends TestCase
{
    private AuditablePropertyResolver $resolver;

    /** @var array{created_at: string, updated_at: string, created_by: string, updated_by: string} */
    private array $fields;

    protected function setUp(): void
    {
        $this->resolver = new AuditablePropertyResolver();
        $this->fields   = ProfileRegistryFactory::defaultFields();
    }

    public function testDetectsAuditableEntity(): void
    {
        $entity = new AuditableEntity();

        $this->assertTrue($this->resolver->isAuditable($entity));
        $this->assertTrue($this->resolver->hasTimestampFields($entity, $this->fields));
        $this->assertTrue($this->resolver->hasBlameFields($entity, $this->fields));
    }

    public function testDisabledAttributeSkipsEntity(): void
    {
        $this->assertFalse($this->resolver->isAuditable(new DisabledAuditableEntity()));
    }

    public function testTimestampOnlyEntity(): void
    {
        $entity = new TimestampOnlyEntity();
        $now    = new DateTimeImmutable();

        $this->assertTrue($this->resolver->isAuditable($entity));
        $this->resolver->setTimestamp($entity, 'created_at', $now, $this->fields);

        $this->assertSame($now, $entity->getCreatedAt());
    }

    public function testNonAuditableEntity(): void
    {
        $this->assertFalse($this->resolver->isAuditable(new stdClass()));
    }
}

class AuditableEntity
{
    use AuditableTrait;
}

#[Auditable(enabled: false)]
class DisabledAuditableEntity
{
    use AuditableTrait;
}

class TimestampOnlyEntity
{
    use TimestampableTrait;
}
