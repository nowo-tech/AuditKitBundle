<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\DependencyInjection;

use Nowo\AuditKitBundle\Change\ChangeSetBuilder;
use Nowo\AuditKitBundle\Correlation\CorrelationConsoleSubscriber;
use Nowo\AuditKitBundle\Correlation\CorrelationContextProviderInterface;
use Nowo\AuditKitBundle\Correlation\CorrelationIdHolder;
use Nowo\AuditKitBundle\Correlation\CorrelationRequestSubscriber;
use Nowo\AuditKitBundle\Correlation\HttpClient\CorrelationHttpClient;
use Nowo\AuditKitBundle\Correlation\InboundCorrelationId;
use Nowo\AuditKitBundle\Correlation\Monolog\CorrelationLogProcessor;
use Nowo\AuditKitBundle\DependencyInjection\Compiler\CorrelationProviderPass;
use Nowo\AuditKitBundle\DependencyInjection\NowoAuditKitExtension;
use Nowo\AuditKitBundle\Timeline\TimelineMerger;
use Nowo\AuditKitBundle\Timeline\TimelineSourceInterface;
use Nowo\CorrelationContract\CorrelationIdProviderInterface;
use Nowo\CorrelationContract\NullCorrelationIdProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class CorrelationExtensionTest extends TestCase
{
    public function testDefaultsRegisterTheFallbackAndOptionalIntegrationsOnly(): void
    {
        $container = $this->load([]);

        $this->assertSame(NullCorrelationIdProvider::class, $container->getDefinition(NowoAuditKitExtension::NULL_CORRELATION_PROVIDER)->getClass());
        $this->assertFalse($container->has(CorrelationIdProviderInterface::class), 'the fallback is bound by the compiler pass');
        $this->assertFalse($container->hasDefinition(CorrelationIdHolder::class));
        $this->assertFalse($container->hasDefinition(CorrelationHttpClient::class));
        $this->assertTrue($container->hasDefinition(NowoAuditKitExtension::MESSENGER_MIDDLEWARE));
        $this->assertSame([[]], $container->getDefinition(CorrelationLogProcessor::class)->getTag('monolog.processor'));
        $this->assertSame([[], ChangeSetBuilder::DEFAULT_SECRET_PATTERN, 500, 16384], $container->getDefinition(ChangeSetBuilder::class)->getArguments());
        $this->assertTrue($container->hasDefinition(TimelineMerger::class));
        $this->assertArrayHasKey(TimelineSourceInterface::class, $container->getAutoconfiguredInstanceof());

        (new CorrelationProviderPass())->process($container);
        $this->assertSame(NowoAuditKitExtension::NULL_CORRELATION_PROVIDER, (string) $container->getAlias(CorrelationIdProviderInterface::class));
    }

    public function testTheProviderOptionBindsTheHolderAndItsListeners(): void
    {
        $container = $this->load(['provider' => true, 'inbound_headers' => ['X-Request-Id'], 'inbound_trusted_proxies_only' => false, 'response_header_paths' => ['/api'], 'http_client' => true, 'monolog' => false]);

        $this->assertSame(CorrelationIdHolder::class, (string) $container->getAlias(CorrelationIdProviderInterface::class));
        $this->assertSame(CorrelationIdHolder::class, (string) $container->getAlias(CorrelationContextProviderInterface::class));
        $this->assertSame([['method' => 'reset']], $container->getDefinition(CorrelationIdHolder::class)->getTag('kernel.reset'));
        $this->assertSame([['X-Request-Id'], false], $container->getDefinition(InboundCorrelationId::class)->getArguments());
        $this->assertTrue($container->getDefinition(CorrelationRequestSubscriber::class)->hasTag('kernel.event_subscriber'));
        $this->assertTrue($container->getDefinition(CorrelationConsoleSubscriber::class)->hasTag('kernel.event_subscriber'));
        $this->assertSame('http_client', $container->getDefinition(CorrelationHttpClient::class)->getDecoratedService()[0] ?? null);
        $this->assertFalse($container->hasDefinition(CorrelationLogProcessor::class));

        $host = new ContainerBuilder();
        $host->setAlias(CorrelationIdProviderInterface::class, 'app.provider');
        (new CorrelationProviderPass())->process($host);
        $this->assertSame('app.provider', (string) $host->getAlias(CorrelationIdProviderInterface::class), 'a host binding is never replaced');
    }

    /**
     * @param array<string, mixed> $correlation
     */
    private function load(array $correlation): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new NowoAuditKitExtension())->load([['user_class' => 'App\\Entity\\User', 'correlation' => $correlation]], $container);

        return $container;
    }
}
