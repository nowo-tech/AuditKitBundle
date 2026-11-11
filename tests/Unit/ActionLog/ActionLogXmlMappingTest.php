<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\ActionLog;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\DefaultNamingStrategy;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Mapping\Driver\SimplifiedXmlDriver;
use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use Nowo\AuditKitBundle\ActionLog\AbstractActionLog;
use Nowo\AuditKitBundle\ActionLog\AbstractIdActionLog;
use Nowo\AuditKitBundle\DependencyInjection\NowoAuditKitExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Since 1.14: the shipped XML mapping of each action log superclass is identical to its attributes. */
final class ActionLogXmlMappingTest extends TestCase
{
    /** @return iterable<string, array{class-string}> */
    public static function superclasses(): iterable
    {
        yield 'relations' => [AbstractActionLog::class];
        yield 'ids' => [AbstractIdActionLog::class];
    }

    /** @param class-string $class */
    #[DataProvider('superclasses')]
    public function testXmlMatchesAttributes(string $class): void
    {
        $fromAttributes = $this->load(new AttributeDriver([__DIR__ . '/../../../src/ActionLog']), $class);
        $fromXml        = $this->load(new SimplifiedXmlDriver([NowoAuditKitExtension::actionLogXmlMappingDir() => 'Nowo\\AuditKitBundle\\ActionLog']), $class);

        $this->assertTrue($fromXml->isMappedSuperclass);
        $this->assertEquals($fromAttributes->fieldMappings, $fromXml->fieldMappings);
        $this->assertEquals($fromAttributes->associationMappings, $fromXml->associationMappings);
        $this->assertSame($fromAttributes->identifier, $fromXml->identifier);
        $this->assertSame($fromAttributes->generatorType, $fromXml->generatorType);
    }

    /**
     * @param class-string $class
     *
     * @return ClassMetadata<object>
     */
    private function load(AttributeDriver|SimplifiedXmlDriver $driver, string $class): ClassMetadata
    {
        $metadata = new ClassMetadata($class, new DefaultNamingStrategy());
        $metadata->initializeReflection(new RuntimeReflectionService());
        $driver->loadMetadataForClass($class, $metadata);

        return $metadata;
    }
}
