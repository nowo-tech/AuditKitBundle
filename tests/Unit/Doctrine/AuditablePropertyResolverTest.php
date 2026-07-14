<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit;

use DateTimeImmutable;
use Nowo\AuditKitBundle\Attribute\Auditable;
use Nowo\AuditKitBundle\Doctrine\AuditablePropertyResolver;
use Nowo\AuditKitBundle\Model\AuditableTrait;
use Nowo\AuditKitBundle\Model\TimestampableTrait;
use PHPUnit\Framework\TestCase;
use stdClass;

final class AuditablePropertyResolverTest extends TestCase
{
    private AuditablePropertyResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new AuditablePropertyResolver([
            'created_at' => 'createdAt',
            'updated_at' => 'updatedAt',
            'created_by' => 'createdBy',
            'updated_by' => 'updatedBy',
        ]);
    }

    public function testDetectsAuditableEntity(): void
    {
        $entity = new AuditableEntity();

        $this->assertTrue($this->resolver->isAuditable($entity));
        $this->assertTrue($this->resolver->hasTimestampFields($entity));
        $this->assertTrue($this->resolver->hasBlameFields($entity));
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
        $this->resolver->setTimestamp($entity, 'created_at', $now);

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
