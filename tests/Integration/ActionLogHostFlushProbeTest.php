<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Integration;

use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\AuditKitBundle\ActionLog\AbstractActionLog;
use Nowo\AuditKitBundle\ActionLog\ActionLogEntryInterface;
use Nowo\AuditKitBundle\ActionLog\ActionLogHostFlushProbeResetSubscriber;
use Nowo\AuditKitBundle\ActionLog\ActionLogRecorder;
use Nowo\AuditKitBundle\Deprecation\DefaultFlipNotices;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\AuditKitTestKernel;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity\KernelActionLog;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity\KernelArticle;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity\KernelPlainRecord;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\AuditKitBundle\Tests\Support\CapturesDeprecations;
use Nowo\TransactionDoctrine\HostFlushProbe;
use Nowo\TransactionDoctrine\Symfony\EventListener\UnitOfWorkBoundaryListener;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;
use ReflectionProperty;
use stdClass;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

use function is_array;
use function is_callable;
use function is_object;
use function sprintf;

/**
 * Since 1.18 (REQ-ARCH-001 rule 6 deprecation layer): with `action_log.flush_mode` unset, recordAndFlush() /
 * recordIdsAndFlush() run the host-flush probe before flushing the host's entity manager. One deprecation per foreign
 * entity class with pending changes per unit of work; none with an explicit flush mode. The probe changes nothing: the
 * host's changes are flushed exactly as in host mode.
 */
final class ActionLogHostFlushProbeTest extends TestCase
{
    use CapturesDeprecations;

    private AuditKitTestKernel $kernel;

    private EntityManagerInterface $em;

    private ActionLogRecorder $recorder;

    private KernelUser $alice;

    private KernelArticle $stored;

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function probes(): iterable
    {
        yield 'bundle probe' => [false];
        yield 'transaction-doctrine shared probe' => [true];
    }

    #[DataProvider('probes')]
    public function testImplicitHostModeReportsEachForeignClassOnceAndFlushesAsBefore(bool $transactionDoctrineBundle): void
    {
        $boot = self::ownDeprecations(self::deprecationsOf(fn () => $this->boot(null, $transactionDoctrineBundle)));
        $this->assertContains('Since nowo-tech/audit-kit-bundle 1.18: ' . DefaultFlipNotices::MESSAGES[DefaultFlipNotices::FLUSH_MODE], $boot);

        $pending = $this->pendingHostChanges();
        $plain   = new KernelPlainRecord();
        $this->em->persist($plain);

        $messages = self::probeMessages(self::deprecationsOf(fn (): AbstractActionLog => $this->recorder->recordAndFlush('item.viewed', $this->alice)));

        $this->assertSame([$this->message(KernelArticle::class), $this->message(KernelPlainRecord::class)], $messages);
        // Unchanged host-mode behaviour: the host's pending changes were flushed with the row.
        $this->assertSame(['Changed', 'Pending'], $this->articleTitles());
        $this->assertFalse($this->em->getUnitOfWork()->isScheduledForInsert($pending));
        $this->assertNotNull($plain->id);
        $this->assertSame(1, $this->actionLogCount());

        // Same unit of work: a class already reported is not reported again.
        $this->stored->title = 'Again';
        $this->assertSame([], self::probeMessages(self::deprecationsOf(fn (): ActionLogEntryInterface => $this->recorder->recordIdsAndFlush('item.viewed', $this->alice->id))));
        $this->assertSame(['Again', 'Pending'], $this->articleTitles());
    }

    public function testRecordIdsAndFlushIsProbedToo(): void
    {
        $this->boot(null);
        $this->pendingHostChanges();

        $messages = self::probeMessages(self::deprecationsOf(fn (): ActionLogEntryInterface => $this->recorder->recordIdsAndFlush('item.viewed', $this->alice->id)));

        $this->assertSame([$this->message(KernelArticle::class)], $messages);
        $this->assertSame(['Changed', 'Pending'], $this->articleTitles());
    }

    public function testNothingPendingReportsNothing(): void
    {
        $this->boot(null);

        $this->assertSame([], self::probeMessages(self::deprecationsOf(fn (): AbstractActionLog => $this->recorder->recordAndFlush('item.viewed', $this->alice))));
        $this->assertSame(1, $this->actionLogCount());
    }

    public function testAnEarlierUnflushedActionLogRowIsReportedButNotTheNewOne(): void
    {
        $this->boot(null);
        $this->recorder->record('item.created', $this->alice);

        $messages = self::probeMessages(self::deprecationsOf(fn (): AbstractActionLog => $this->recorder->recordAndFlush('item.viewed', $this->alice)));

        // The 2.0 default writes only the new row: the row recorded with record() is the host's to flush.
        $this->assertSame([$this->message(KernelActionLog::class)], $messages);
        $this->assertSame(2, $this->actionLogCount());
    }

    public function testNewRowAloneIsNeverReported(): void
    {
        $this->boot(null);

        for ($i = 0; $i < 3; ++$i) {
            $this->assertSame([], self::probeMessages(self::deprecationsOf(fn (): AbstractActionLog => $this->recorder->recordAndFlush('item.viewed', $this->alice))));
        }
        $this->assertSame(3, $this->actionLogCount());
    }

    public function testAClosedManagerIsReplacedWithoutProbing(): void
    {
        $this->boot(null);
        $this->pendingHostChanges();
        $this->em->close();

        $messages = self::probeMessages(self::deprecationsOf(fn (): ActionLogEntryInterface => $this->recorder->recordIdsAndFlush('item.viewed', $this->alice->id)));

        // The replacement manager has an empty unit of work: nothing of the host's is flushed, nothing is reported.
        $this->assertSame([], $messages);
        $this->assertSame(['Draft'], $this->articleTitles());
        $this->assertSame(1, $this->actionLogCount());
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
    public function testAnExplicitFlushModeTriggersNothing(string $mode): void
    {
        $this->assertSame([], self::ownDeprecations(self::deprecationsOf(fn () => $this->boot($mode))));
        $this->pendingHostChanges();

        $this->assertSame([], self::ownDeprecations(self::deprecationsOf(fn (): AbstractActionLog => $this->recorder->recordAndFlush('item.viewed', $this->alice))));
        $this->assertSame([], self::ownDeprecations(self::deprecationsOf(fn (): ActionLogEntryInterface => $this->recorder->recordIdsAndFlush('item.viewed', $this->alice->id))));
        $this->assertSame($mode === ActionLogRecorder::FLUSH_HOST ? ['Changed', 'Pending'] : ['Draft'], $this->articleTitles());
    }

    /**
     * Worker mode with the kernel kept alive and no services resetter: each main request reports its own pending
     * changes once, and the probe's memo is empty after every request (nothing survives into the next one).
     */
    #[DataProvider('probes')]
    public function testEveryRequestOfAKeptAliveKernelReportsAgainAndLeavesNoState(bool $transactionDoctrineBundle): void
    {
        $this->boot(null, $transactionDoctrineBundle);
        $dispatcher = $this->kernel->getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $probe = $this->kernel->getContainer()->get('test.host_flush_probe');
        self::assertInstanceOf(HostFlushProbe::class, $probe);
        $reported = new ReflectionProperty(HostFlushProbe::class, 'reported');

        for ($i = 0; $i < 50; ++$i) {
            $request = Request::create('/item/' . $i);
            $this->boundary($dispatcher, KernelEvents::REQUEST, new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST));

            $this->stored->title = 'Title ' . $i;
            $messages            = self::probeMessages(self::deprecationsOf(fn (): AbstractActionLog => $this->recorder->recordAndFlush('item.viewed', $this->alice)));
            $this->assertSame([$this->message(KernelArticle::class)], $messages, sprintf('request %d', $i));

            // A sub-request keeps the memo of its main request.
            $sub = Request::create('/fragment');
            $this->boundary($dispatcher, KernelEvents::FINISH_REQUEST, new FinishRequestEvent($this->kernel, $sub, HttpKernelInterface::SUB_REQUEST));
            $this->assertNotSame([], $reported->getValue($probe));

            $this->boundary($dispatcher, KernelEvents::FINISH_REQUEST, new FinishRequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST));
            $this->assertSame([], $reported->getValue($probe), sprintf('request %d left probe state', $i));
        }

        $this->assertSame(50, $this->actionLogCount());
    }

    public function testConsoleAndMessengerBoundariesResetTheProbe(): void
    {
        $this->boot(null);
        $dispatcher = $this->kernel->getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $probe = $this->kernel->getContainer()->get('test.host_flush_probe');
        self::assertInstanceOf(HostFlushProbe::class, $probe);
        $reported = new ReflectionProperty(HostFlushProbe::class, 'reported');

        foreach (['console.terminate', 'console.command', WorkerMessageHandledEvent::class, WorkerMessageFailedEvent::class, WorkerMessageReceivedEvent::class] as $event) {
            $this->stored->title = $event;
            self::deprecationsOf(fn (): AbstractActionLog => $this->recorder->recordAndFlush('item.viewed', $this->alice));
            $this->assertNotSame([], $reported->getValue($probe));

            $this->boundary($dispatcher, $event, new stdClass());
            $this->assertSame([], $reported->getValue($probe), $event);
        }
    }

    /**
     * Runs the unit-of-work boundary listeners of an event (this bundle's, and transaction-doctrine's when registered)
     * as the dispatcher would, without the framework's own listeners (no router or console application here).
     */
    private function boundary(EventDispatcherInterface $dispatcher, string $eventName, object $event): void
    {
        $ran = 0;
        foreach ($dispatcher->getListeners($eventName) as $listener) {
            $target = null;
            if (is_array($listener) && is_object($listener[0] ?? null)) {
                $target = $listener[0];
            } elseif ($listener instanceof Closure) {
                $target = (new ReflectionFunction($listener))->getClosureThis();
            }
            if (($target instanceof ActionLogHostFlushProbeResetSubscriber || $target instanceof UnitOfWorkBoundaryListener) && is_callable($listener)) {
                $listener($event, $eventName, $dispatcher);
                ++$ran;
            }
        }
        $this->assertGreaterThan(0, $ran, $eventName);
    }

    private function message(string $class): string
    {
        return sprintf('Your pending changes to %s were flushed by NowoAuditKitBundle; 2.0 no longer does.', $class);
    }

    private function boot(?string $flushMode, bool $transactionDoctrineBundle = false): void
    {
        (new Filesystem())->remove(sys_get_temp_dir() . '/nowo-audit-kit-test-kernel');

        $this->kernel = new AuditKitTestKernel($flushMode, null, $transactionDoctrineBundle);
        $this->kernel->boot();

        $em = $this->kernel->getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());

        $recorder = $this->kernel->getContainer()->get('test.action_log_recorder');
        self::assertInstanceOf(ActionLogRecorder::class, $recorder);
        $this->recorder = $recorder;

        $this->alice  = new KernelUser('alice@example.com');
        $this->stored = new KernelArticle('Draft');
        $this->em->persist($this->alice);
        $this->em->persist($this->stored);
        $this->em->flush();
    }

    private function pendingHostChanges(): KernelArticle
    {
        $pending = new KernelArticle('Pending');
        $this->em->persist($pending);
        $this->stored->title = 'Changed';

        return $pending;
    }

    /** @return list<string> */
    private function articleTitles(): array
    {
        /** @var list<string> $titles */
        $titles = $this->em->getConnection()->fetchFirstColumn('SELECT title FROM kernel_article ORDER BY id');

        return $titles;
    }

    private function actionLogCount(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM kernel_action_log');
    }
}
