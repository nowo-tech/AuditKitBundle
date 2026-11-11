<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Integration;

use Nowo\AuditKitBundle\Avatar\ActionLogAvatarResolverInterface;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\TwigKernel;
use Nowo\AuditKitBundle\Tests\Support\TenantAvatarResolver;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use stdClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Twig\Environment;
use Twig\TemplateWrapper;

use function gc_collect_cycles;
use function memory_get_usage;
use function sprintf;

/**
 * REQ-RUNTIME-002: the per-user avatar memo with the kernel kept alive and no services resetter (FrankenPHP worker
 * mode, kernel reset false; Messenger consumer). One kernel, two tenants in alternation whose users share ids: each
 * request / message renders `nowo_audit_kit_avatar_url()` and must get its own tenant's picture, never the previous
 * unit's. N = 1,000 HTTP requests and 1,000 Messenger messages (one in five failing); memory margin 1 MiB.
 */
final class ActionLogAvatarsWorkerTest extends TestCase
{
    private const ITERATIONS    = 1000;
    private const WARMUP        = 100;
    private const MEMORY_MARGIN = 1024 * 1024;

    private ?TwigKernel $kernel = null;

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        (new Filesystem())->remove(sys_get_temp_dir() . '/nowo-audit-kit-twig-kernel');
    }

    public function testManyHttpRequestsForTwoTenantsNeverShowAnotherTenantsAvatar(): void
    {
        [$template, $resolver] = $this->boot();
        $kernel                = $this->kernel;
        $this->assertInstanceOf(HttpKernelInterface::class, $kernel);
        $users = $this->users();

        $before = 0;
        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            if ($i === self::WARMUP) {
                gc_collect_cycles();
                $before = memory_get_usage();
            }
            $tenant  = $i % 2 === 0 ? 'acme' : 'globex';
            $request = Request::create('/timeline');
            $request->attributes->set('_controller', 'already_routed');

            $this->dispatch(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST), KernelEvents::REQUEST);
            $resolver->tenant = $tenant;
            $this->assertSame($this->expected($tenant), $template->render(['users' => $users]), sprintf('Request %d (%s)', $i, $tenant));
            $this->dispatch(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST), KernelEvents::FINISH_REQUEST);
        }

        gc_collect_cycles();
        $this->assertLessThan(self::MEMORY_MARGIN, memory_get_usage() - $before, 'Memory must stay flat over the requests.');
        $this->assertSame(2 * self::ITERATIONS, $resolver->calls, 'Each request resolves its own users again (no stale memo).');
    }

    public function testManyMessengerMessagesForTwoTenantsNeverShowAnotherTenantsAvatar(): void
    {
        [$template, $resolver] = $this->boot();
        $users                 = $this->users();

        $before = 0;
        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            if ($i === self::WARMUP) {
                gc_collect_cycles();
                $before = memory_get_usage();
            }
            $tenant   = $i % 2 === 0 ? 'acme' : 'globex';
            $envelope = new Envelope(new stdClass());

            $this->dispatch(new WorkerMessageReceivedEvent($envelope, 'async'), WorkerMessageReceivedEvent::class);
            $resolver->tenant = $tenant;
            // e.g. a digest e-mail with the timeline, rendered by a handler.
            $this->assertSame($this->expected($tenant), $template->render(['users' => $users]), sprintf('Message %d (%s)', $i, $tenant));
            if ($i % 5 === 0) {
                $this->dispatch(new WorkerMessageFailedEvent($envelope, 'async', new RuntimeException('handler failed')), WorkerMessageFailedEvent::class);
            } else {
                $this->dispatch(new WorkerMessageHandledEvent($envelope, 'async'), WorkerMessageHandledEvent::class);
            }
        }

        gc_collect_cycles();
        $this->assertLessThan(self::MEMORY_MARGIN, memory_get_usage() - $before, 'Memory must stay flat over the messages.');
    }

    public function testTheMemoIsEmptyAfterEachBoundaryEvenWithoutTheNextStart(): void
    {
        [$template, $resolver] = $this->boot();
        $users                 = $this->users();
        $kernel                = $this->kernel;
        $this->assertInstanceOf(HttpKernelInterface::class, $kernel);

        $ends = [
            fn () => $this->dispatch(new FinishRequestEvent($kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST), KernelEvents::FINISH_REQUEST),
            fn () => $this->dispatch(new WorkerMessageHandledEvent(new Envelope(new stdClass()), 'async'), WorkerMessageHandledEvent::class),
            fn () => $this->dispatch(new WorkerMessageFailedEvent(new Envelope(new stdClass()), 'async', new RuntimeException('x')), WorkerMessageFailedEvent::class),
            fn () => $this->dispatch(new ConsoleTerminateEvent(new Command('app:digest'), new ArrayInput([]), new NullOutput(), Command::SUCCESS), ConsoleEvents::TERMINATE),
        ];
        foreach ($ends as $n => $end) {
            $resolver->tenant = 'acme';
            $this->assertSame($this->expected('acme'), $template->render(['users' => $users]));
            $end();
            // Code that runs after the boundary and before the next unit starts (e.g. a command's own code).
            $resolver->tenant = 'globex';
            $this->assertSame($this->expected('globex'), $template->render(['users' => $users]), sprintf('Boundary %d', $n));
            $end();
        }
    }

    /**
     * @return array{0: TemplateWrapper, 1: TenantAvatarResolver}
     */
    private function boot(): array
    {
        $this->kernel = new TwigKernel(__DIR__ . '/Kernel', 'avatar-worker', static function (ContainerBuilder $container): void {
            $container->register(TenantAvatarResolver::class, TenantAvatarResolver::class)->setPublic(true);
            $container->setAlias(ActionLogAvatarResolverInterface::class, TenantAvatarResolver::class);
        });
        $this->kernel->boot();
        $container = $this->kernel->getContainer()->get('test.service_container');
        $this->assertInstanceOf(ContainerInterface::class, $container);
        $twig = $container->get('twig');
        $this->assertInstanceOf(Environment::class, $twig);
        $resolver = $container->get(TenantAvatarResolver::class);
        $this->assertInstanceOf(TenantAvatarResolver::class, $resolver);

        // Compiled once: the loop measures the bundle, not Twig's template cache.
        $template = $twig->createTemplate(
            '{% for user in users %}{{ nowo_audit_kit_avatar_url(user) }};{% endfor %}',
        );

        return [$template, $resolver];
    }

    /**
     * @return list<KernelUser>
     */
    private function users(): array
    {
        $users = [];
        foreach ([1, 2] as $id) {
            $user     = new KernelUser('user' . $id . '@example.com');
            $user->id = $id;
            $users[]  = $user;
        }

        return $users;
    }

    private function expected(string $tenant): string
    {
        return sprintf('/files/%1$s/1/s24;/files/%1$s/2/s24;', $tenant);
    }

    private function dispatch(object $event, string $name): void
    {
        $container = $this->kernel?->getContainer();
        $this->assertNotNull($container);
        $dispatcher = $container->get('event_dispatcher');
        $this->assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->dispatch($event, $name);
    }
}
