<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\DependencyInjection;

use Nowo\AuditKitBundle\ActionLog\ActionLogHostFlushProbeResetSubscriber;
use Nowo\AuditKitBundle\ActionLog\ActionLogRecorder;
use Nowo\AuditKitBundle\DependencyInjection\Compiler\HostFlushProbePass;
use Nowo\AuditKitBundle\DependencyInjection\NowoAuditKitExtension;
use Nowo\AuditKitBundle\Deprecation\DefaultFlipNotices;
use Nowo\AuditKitBundle\NowoAuditKitBundle;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity\KernelActionLog;
use Nowo\AuditKitBundle\Tests\Support\CapturesDeprecations;
use Nowo\TransactionDoctrine\HostFlushProbe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Since 1.18 (REQ-ARCH-001 wave 0): a notice for each default that changes in 2.0 and that the host left unset, and
 * the host-flush probe wired only in implicit host mode.
 */
final class DefaultFlipNoticesTest extends TestCase
{
    use CapturesDeprecations;

    private const ACTION_LOG = ['entity_class' => KernelActionLog::class];

    /**
     * @return iterable<string, array{array<string, mixed>, list<string>}>
     */
    public static function configurations(): iterable
    {
        yield 'everything unset' => [['action_log' => self::ACTION_LOG], [DefaultFlipNotices::FLUSH_MODE, DefaultFlipNotices::ATTRIBUTE_OPT_IN]];
        yield 'no action log' => [[], [DefaultFlipNotices::ATTRIBUTE_OPT_IN]];
        yield 'flush mode host' => [['action_log' => self::ACTION_LOG + ['flush_mode' => 'host']], [DefaultFlipNotices::ATTRIBUTE_OPT_IN]];
        yield 'flush mode isolated' => [['action_log' => self::ACTION_LOG + ['flush_mode' => 'isolated']], [DefaultFlipNotices::ATTRIBUTE_OPT_IN]];
        yield 'attribute opt-in false' => [['attribute_opt_in' => false, 'action_log' => self::ACTION_LOG], [DefaultFlipNotices::FLUSH_MODE]];
        yield 'attribute opt-in true' => [['attribute_opt_in' => true, 'action_log' => self::ACTION_LOG], [DefaultFlipNotices::FLUSH_MODE]];
        yield 'both set' => [['attribute_opt_in' => false, 'action_log' => self::ACTION_LOG + ['flush_mode' => 'isolated']], []];
        yield 'xml keys' => [['attribute-opt-in' => true, 'action_log' => self::ACTION_LOG + ['flush-mode' => 'host']], []];
        yield 'auditing disabled' => [['enabled' => false, 'action_log' => self::ACTION_LOG + ['flush_mode' => 'host']], []];
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string> $expected
     */
    #[DataProvider('configurations')]
    public function testOnlyUnsetOptionsGetANotice(array $config, array $expected): void
    {
        $container = $this->load($config);

        $this->assertSame($expected, $container->getParameter(DefaultFlipNotices::PARAMETER));
    }

    public function testASetOptionInAnyFileSilencesItsNotice(): void
    {
        $container = new ContainerBuilder();
        (new NowoAuditKitExtension())->load([
            ['user_class' => 'App\\Entity\\User', 'action_log' => self::ACTION_LOG],
            ['user_class' => 'App\\Entity\\User', 'attribute_opt_in' => false, 'action_log' => ['flush_mode' => 'isolated']],
        ], $container);

        $this->assertSame([], $container->getParameter(DefaultFlipNotices::PARAMETER));
    }

    public function testBootTriggersEachNoticeOnce(): void
    {
        $messages = self::deprecationsOf(fn () => $this->bootBundle([DefaultFlipNotices::FLUSH_MODE, DefaultFlipNotices::ATTRIBUTE_OPT_IN]));

        $this->assertSame([
            'Since nowo-tech/audit-kit-bundle 1.18: ' . DefaultFlipNotices::MESSAGES[DefaultFlipNotices::FLUSH_MODE],
            'Since nowo-tech/audit-kit-bundle 1.18: ' . DefaultFlipNotices::MESSAGES[DefaultFlipNotices::ATTRIBUTE_OPT_IN],
        ], $messages);
        $this->assertStringContainsString('"nowo_audit_kit.action_log.flush_mode: isolated"', $messages[0]);
        $this->assertStringContainsString('The default becomes "isolated" in 2.0', $messages[0]);
        $this->assertStringContainsString('"nowo_audit_kit.attribute_opt_in: true"', $messages[1]);
        $this->assertStringContainsString('The default becomes true in 2.0', $messages[1]);
    }

    public function testBootWithExplicitOptionsTriggersNothing(): void
    {
        $this->assertSame([], self::deprecationsOf(fn () => $this->bootBundle([])));
        $this->assertSame([], self::deprecationsOf(static function (): void {
            $bundle = new NowoAuditKitBundle();
            $bundle->setContainer(new Container());
            $bundle->boot();
        }));
    }

    #[IgnoreDeprecations]
    public function testExpectedNoticeIsAUserDeprecation(): void
    {
        $this->expectUserDeprecationMessage('Since nowo-tech/audit-kit-bundle 1.18: ' . DefaultFlipNotices::MESSAGES[DefaultFlipNotices::FLUSH_MODE]);

        $this->bootBundle([DefaultFlipNotices::FLUSH_MODE]);
    }

    public function testImplicitHostModeWiresTheProbe(): void
    {
        $container = $this->load(['action_log' => self::ACTION_LOG]);

        $this->assertEquals(new Reference(NowoAuditKitExtension::HOST_FLUSH_PROBE), $container->getDefinition(ActionLogRecorder::class)->getArgument('$hostFlushProbe'));
        $probe = $container->getDefinition(NowoAuditKitExtension::HOST_FLUSH_PROBE);
        $this->assertSame(HostFlushProbe::class, $probe->getClass());
        $this->assertSame([['method' => 'reset']], $probe->getTag('kernel.reset'));
        $subscriber = $container->getDefinition(ActionLogHostFlushProbeResetSubscriber::class);
        $this->assertTrue($subscriber->hasTag('kernel.event_subscriber'));
        $this->assertEquals([new Reference(NowoAuditKitExtension::HOST_FLUSH_PROBE)], $subscriber->getArguments());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function explicitModes(): iterable
    {
        yield 'host' => [ActionLogRecorder::FLUSH_HOST];
        yield 'isolated' => [ActionLogRecorder::FLUSH_ISOLATED];
    }

    #[DataProvider('explicitModes')]
    public function testAnExplicitFlushModeGetsNoProbe(string $mode): void
    {
        $container = $this->load(['action_log' => self::ACTION_LOG + ['flush_mode' => $mode]]);

        $this->assertArrayNotHasKey('$hostFlushProbe', $container->getDefinition(ActionLogRecorder::class)->getArguments());
        $this->assertFalse($container->hasDefinition(NowoAuditKitExtension::HOST_FLUSH_PROBE));
        $this->assertFalse($container->hasDefinition(ActionLogHostFlushProbeResetSubscriber::class));
    }

    public function testTheSharedProbeOfTransactionDoctrineIsUsedWhenRegistered(): void
    {
        $container = $this->load(['action_log' => self::ACTION_LOG]);
        $container->register(HostFlushProbe::SERVICE_ID, HostFlushProbe::class);

        (new HostFlushProbePass())->process($container);

        $this->assertFalse($container->hasDefinition(NowoAuditKitExtension::HOST_FLUSH_PROBE));
        $this->assertSame(HostFlushProbe::SERVICE_ID, (string) $container->getAlias(NowoAuditKitExtension::HOST_FLUSH_PROBE));
    }

    public function testTheBundleProbeStaysWithoutTransactionDoctrineBundle(): void
    {
        $container = $this->load(['action_log' => self::ACTION_LOG]);

        (new HostFlushProbePass())->process($container);

        $this->assertTrue($container->hasDefinition(NowoAuditKitExtension::HOST_FLUSH_PROBE));
    }

    public function testBuildRegistersTheProbePass(): void
    {
        $container = new ContainerBuilder();
        (new NowoAuditKitBundle())->build($container);

        $passes = array_filter($container->getCompilerPassConfig()->getPasses(), static fn (object $pass): bool => $pass instanceof HostFlushProbePass);
        $this->assertCount(1, $passes);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new NowoAuditKitExtension())->load([['user_class' => 'App\\Entity\\User'] + $config], $container);

        return $container;
    }

    /**
     * @param list<string> $notices
     */
    private function bootBundle(array $notices): void
    {
        $container = new Container();
        $container->setParameter(DefaultFlipNotices::PARAMETER, $notices);
        $bundle = new NowoAuditKitBundle();
        $bundle->setContainer($container);
        $bundle->boot();
    }
}
