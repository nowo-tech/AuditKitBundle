<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\DependencyInjection;

use Nowo\AuditKitBundle\DependencyInjection\Compiler\CorrelationMiddlewareOrderPass;
use Nowo\AuditKitBundle\DependencyInjection\NowoAuditKitExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class CorrelationMiddlewareOrderPassTest extends TestCase
{
    private const OWN = NowoAuditKitExtension::MESSENGER_MIDDLEWARE;

    public function testMovesTheMiddlewareInFrontOfDispatchAfterCurrentBus(): void
    {
        $container = new ContainerBuilder();
        $container->register('app.bus')->addTag('messenger.bus');
        $container->setParameter('app.bus.middleware', [
            ['id' => 'add_bus_name_stamp_middleware', 'arguments' => ['app.bus']],
            ['id' => 'dispatch_after_current_bus'],
            ['id' => 'app.audit'],
            ['id' => self::OWN],
            ['id' => 'handle_message'],
        ]);
        $container->register('other.bus')->addTag('messenger.bus');
        $container->register('raw.bus')->addTag('messenger.bus');
        $container->setParameter('raw.bus.middleware', 'not-a-list');

        (new CorrelationMiddlewareOrderPass())->process($container);

        $this->assertSame(
            ['add_bus_name_stamp_middleware', self::OWN, 'dispatch_after_current_bus', 'app.audit', 'handle_message'],
            array_column((array) $container->getParameter('app.bus.middleware'), 'id'),
        );
        $this->assertSame('not-a-list', $container->getParameter('raw.bus.middleware'));
    }

    public function testLeavesOtherOrdersAlone(): void
    {
        $before  = [['id' => self::OWN], ['id' => 'messenger.middleware.dispatch_after_current_bus']];
        $without = [['id' => 'dispatch_after_current_bus'], 'not-an-item'];
        $noAfter = [['id' => 'app.audit'], ['id' => self::OWN]];

        $this->assertSame($before, CorrelationMiddlewareOrderPass::reorder($before));
        $this->assertSame($without, CorrelationMiddlewareOrderPass::reorder($without));
        $this->assertSame($noAfter, CorrelationMiddlewareOrderPass::reorder($noAfter));
        $this->assertSame(
            [['id' => self::OWN], ['id' => 'messenger.middleware.dispatch_after_current_bus'], ['id' => self::OWN]],
            CorrelationMiddlewareOrderPass::reorder([['id' => 'messenger.middleware.dispatch_after_current_bus'], ['id' => self::OWN], ['id' => self::OWN]]),
        );
    }
}
