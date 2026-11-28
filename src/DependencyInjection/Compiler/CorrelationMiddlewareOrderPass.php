<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\DependencyInjection\Compiler;

use Nowo\AuditKitBundle\DependencyInjection\NowoAuditKitExtension;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function array_keys;
use function array_splice;
use function array_values;
use function in_array;
use function is_array;

/**
 * Since 2.2: moves `nowo_audit_kit.correlation.messenger_middleware` in front of `dispatch_after_current_bus` on every
 * bus that lists it.
 *
 * FrameworkBundle puts a bus's own middleware after its default `before` middleware, `dispatch_after_current_bus`
 * included. A message dispatched with `DispatchAfterCurrentBusStamp` from a handler is then queued before the
 * correlation middleware stamps it, and handled after the received message's context has ended: it would lose its id
 * and `caused_by`. In front of it, the middleware stamps the message when it is dispatched and keeps the received
 * context open until the queued messages have been handled.
 *
 * Runs before FrameworkBundle's `MessengerPass` (priority 1), on the `<bus>.middleware` parameters it reads.
 *
 * @internal
 */
final class CorrelationMiddlewareOrderPass implements CompilerPassInterface
{
    /** Ids FrameworkBundle accepts for the after-current-bus middleware. */
    public const DISPATCH_AFTER_CURRENT_BUS = ['dispatch_after_current_bus', 'messenger.middleware.dispatch_after_current_bus'];

    public function process(ContainerBuilder $container): void
    {
        foreach (array_keys($container->findTaggedServiceIds('messenger.bus')) as $busId) {
            $parameter = $busId . '.middleware';
            if (!$container->hasParameter($parameter)) {
                continue;
            }

            $middleware = $container->getParameter($parameter);
            if (is_array($middleware)) {
                $container->setParameter($parameter, self::reorder(array_values($middleware)));
            }
        }
    }

    /**
     * The middleware list with the correlation middleware just before the after-current-bus one, when it came after it.
     *
     * @param list<mixed> $middleware
     *
     * @return list<mixed>
     */
    public static function reorder(array $middleware): array
    {
        $own   = null;
        $after = null;
        foreach ($middleware as $index => $item) {
            $id = is_array($item) ? ($item['id'] ?? null) : null;
            if ($id === NowoAuditKitExtension::MESSENGER_MIDDLEWARE) {
                $own ??= $index;
            } elseif ($after === null && in_array($id, self::DISPATCH_AFTER_CURRENT_BUS, true)) {
                $after = $index;
            }
        }

        if ($own === null || $after === null || $own < $after) {
            return $middleware;
        }

        $item = array_splice($middleware, $own, 1);
        array_splice($middleware, $after, 0, $item);

        return $middleware;
    }
}
