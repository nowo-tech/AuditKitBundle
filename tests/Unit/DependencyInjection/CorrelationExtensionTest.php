<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\AuditKitBundle\Change\ChangeSetBuilder;
use Nowo\AuditKitBundle\Correlation\CorrelationConsoleSubscriber;
use Nowo\AuditKitBundle\Correlation\CorrelationContextProviderInterface;
use Nowo\AuditKitBundle\Correlation\CorrelationIdHolder;
use Nowo\AuditKitBundle\Correlation\CorrelationRequestSubscriber;
use Nowo\AuditKitBundle\Correlation\HttpClient\CorrelationHttpClient;
use Nowo\AuditKitBundle\Correlation\InboundCorrelationId;
use Nowo\AuditKitBundle\Correlation\Mailer\CorrelationMailerListener;
use Nowo\AuditKitBundle\Correlation\Monolog\CorrelationLogProcessor;
use Nowo\AuditKitBundle\DependencyInjection\Compiler\CorrelationProviderPass;
use Nowo\AuditKitBundle\DependencyInjection\NowoAuditKitExtension;
use Nowo\AuditKitBundle\Timeline\TimelineMerger;
use Nowo\AuditKitBundle\Timeline\TimelineSourceInterface;
use Nowo\CorrelationContract\CorrelationIdProviderInterface;
use Nowo\CorrelationContract\NullCorrelationIdProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Mailer\Event\MessageEvent;

final class CorrelationExtensionTest extends TestCase
{
    public function testDefaultsRegisterTheFallbackAndOptionalIntegrationsOnly(): void
    {
        $container = $this->load([]);

        $this->assertSame(NullCorrelationIdProvider::class, $container->getDefinition(NowoAuditKitExtension::NULL_CORRELATION_PROVIDER)->getClass());
        $this->assertFalse($container->has(CorrelationIdProviderInterface::class), 'the fallback is bound by the compiler pass');
        $this->assertFalse($container->hasDefinition(CorrelationIdHolder::class));
        $this->assertFalse($container->hasDefinition(CorrelationHttpClient::class));
        $this->assertFalse($container->hasDefinition(CorrelationMailerListener::class));
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
        $container = $this->load(['provider' => true, 'header_name' => 'X-Request-Id', 'inbound_headers' => [], 'inbound_trusted_proxies_only' => false, 'response_header_paths' => ['/api'], 'http_client' => true, 'mailer' => true, 'monolog' => false]);

        $this->assertSame(CorrelationIdHolder::class, (string) $container->getAlias(CorrelationIdProviderInterface::class));
        $this->assertSame(CorrelationIdHolder::class, (string) $container->getAlias(CorrelationContextProviderInterface::class));
        $this->assertSame([['method' => 'reset']], $container->getDefinition(CorrelationIdHolder::class)->getTag('kernel.reset'));
        $this->assertSame([[], false], $container->getDefinition(InboundCorrelationId::class)->getArguments());
        $this->assertSame(['/api'], $container->getDefinition(CorrelationRequestSubscriber::class)->getArgument(2));
        $this->assertSame('X-Request-Id', $container->getDefinition(CorrelationRequestSubscriber::class)->getArgument(3));
        $this->assertSame('X-Request-Id', $container->getDefinition(CorrelationHttpClient::class)->getArgument(2));
        $this->assertSame('X-Request-Id', $container->getDefinition(CorrelationMailerListener::class)->getArgument(1));
        $this->assertSame([['event' => MessageEvent::class, 'priority' => 255]], $container->getDefinition(CorrelationMailerListener::class)->getTag('kernel.event_listener'));
        $this->assertTrue($container->getDefinition(CorrelationRequestSubscriber::class)->hasTag('kernel.event_subscriber'));
        $this->assertTrue($container->getDefinition(CorrelationConsoleSubscriber::class)->hasTag('kernel.event_subscriber'));
        $this->assertSame('http_client', $container->getDefinition(CorrelationHttpClient::class)->getDecoratedService()[0] ?? null);
        $this->assertFalse($container->hasDefinition(CorrelationLogProcessor::class));

        $host = new ContainerBuilder();
        $host->setAlias(CorrelationIdProviderInterface::class, 'app.provider');
        (new CorrelationProviderPass())->process($host);
        $this->assertSame('app.provider', (string) $host->getAlias(CorrelationIdProviderInterface::class), 'a host binding is never replaced');

        (new CorrelationProviderPass())->process($container);
        $this->assertSame(CorrelationIdHolder::class, (string) $container->getAlias(CorrelationIdProviderInterface::class));
    }

    public function testTheBundleProviderAndAHostBindingAreMutuallyExclusive(): void
    {
        $container = $this->load(['provider' => true]);
        $container->setAlias(CorrelationIdProviderInterface::class, 'app.provider');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('is bound to "app.provider"');
        (new CorrelationProviderPass())->process($container);
    }

    public function testADefinitionNamedAfterTheInterfaceAlsoConflicts(): void
    {
        $container = $this->load(['provider' => true]);
        $container->removeAlias(CorrelationIdProviderInterface::class);
        $container->register(CorrelationIdProviderInterface::class, NullCorrelationIdProvider::class);

        $this->expectException(LogicException::class);
        (new CorrelationProviderPass())->process($container);
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
