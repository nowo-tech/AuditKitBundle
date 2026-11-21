<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Integration;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\AuditKitBundle\ActionLog\ActionLogEntryInterface;
use Nowo\AuditKitBundle\ActionLog\ActionLogRecorder;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\AuditKitTestKernel;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity\KernelArticle;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity\KernelAttributeRecord;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\AuditKitBundle\Tests\Support\CapturesDeprecations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Since 2.0 (REQ-ARCH-001 rule 6): the defaults that 1.18 announced.
 *
 * - `action_log.flush_mode` unset means `isolated`: `*AndFlush()` commit only the new row; the host's pending inserts,
 *   updates and removals stay pending until the host flushes.
 * - An explicit `flush_mode: host` keeps the 1.x behaviour, with no notice: the host's pending changes are flushed with
 *   the row. This is the pattern a host change that relied on the audit flush needs (a removal recorded with
 *   `recordAndFlush()` and never flushed by the host itself).
 * - `attribute_opt_in` unset means `true`: `#[Auditable]` alone opts a class in; an explicit `false` still ignores it.
 * - No deprecation from this bundle at boot or while recording.
 */
final class ActionLogDefaultFlushModeTest extends TestCase
{
    use CapturesDeprecations;

    private AuditKitTestKernel $kernel;

    private EntityManagerInterface $em;

    private ActionLogRecorder $recorder;

    private KernelUser $alice;

    private KernelArticle $stored;

    private KernelArticle $doomed;

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
    }

    public function testUnsetFlushModeIsIsolatedAndTheHostChangesStayPending(): void
    {
        $this->assertSame([], self::ownDeprecations(self::deprecationsOf(fn () => $this->boot(null))));
        $this->assertSame(ActionLogRecorder::FLUSH_ISOLATED, $this->kernel->getContainer()->getParameter('nowo_audit_kit.action_log.flush_mode'));
        $pending = $this->pendingHostChanges();

        $messages = self::ownDeprecations(self::deprecationsOf(function (): void {
            $this->recorder->recordAndFlush('board.unlinked', $this->alice);
            $this->recorder->recordIdsAndFlush('board.unlinked', $this->alice->id);
        }));

        $this->assertSame([], $messages);
        $this->assertSame(2, $this->actionLogCount());
        // Neither the insert, the update nor the removal reached the database; the host still holds all of them.
        $this->assertSame(['Doomed', 'Draft'], $this->articleTitles());
        $uow = $this->em->getUnitOfWork();
        $this->assertTrue($uow->isScheduledForInsert($pending));
        $this->assertTrue($uow->isScheduledForDelete($this->doomed));
        $this->assertSame('Changed', $this->stored->title);

        $this->em->flush();
        $this->assertSame(['Changed', 'Pending'], $this->articleTitles());
        $this->assertSame(2, $this->actionLogCount());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function recordings(): iterable
    {
        yield 'recordAndFlush()' => ['object'];
        yield 'recordIdsAndFlush()' => ['ids'];
    }

    /** The 1.x behaviour a host keeps with `flush_mode: host`, without any notice or probe line. */
    #[DataProvider('recordings')]
    public function testAnExplicitHostModeStillFlushesTheHostChangesAsIn1x(string $recording): void
    {
        $this->assertSame([], self::ownDeprecations(self::deprecationsOf(fn () => $this->boot(ActionLogRecorder::FLUSH_HOST))));
        $pending = $this->pendingHostChanges();

        $messages = self::ownDeprecations(self::deprecationsOf(fn (): ActionLogEntryInterface => $recording === 'object'
            ? $this->recorder->recordAndFlush('board.unlinked', $this->alice)
            : $this->recorder->recordIdsAndFlush('board.unlinked', $this->alice->id)));

        $this->assertSame([], $messages);
        $this->assertSame(1, $this->actionLogCount());
        // The removal, the update and the insert were flushed with the row, as before.
        $this->assertSame(['Changed', 'Pending'], $this->articleTitles());
        $this->assertFalse($this->em->getUnitOfWork()->isScheduledForInsert($pending));
        $this->assertFalse($this->em->contains($this->doomed));
    }

    public function testTheIsolatedRowIsDetachedAndTheHostRowIsManaged(): void
    {
        $this->boot(null);
        $isolated = $this->recorder->recordAndFlush('item.viewed', $this->alice);
        $this->assertFalse($this->em->contains($isolated));
        $this->assertTrue($this->em->contains($this->alice));

        $this->kernel->shutdown();
        $this->boot(ActionLogRecorder::FLUSH_HOST);
        $host = $this->recorder->recordAndFlush('item.viewed', $this->alice);
        $this->assertTrue($this->em->contains($host));
    }

    public function testUnsetAttributeOptInAuditsTheAttributeAlone(): void
    {
        $this->assertSame([], self::ownDeprecations(self::deprecationsOf(fn () => $this->boot(null, null))));
        $this->assertTrue($this->kernel->getContainer()->getParameter('nowo_audit_kit.attribute_opt_in'));

        $record = new KernelAttributeRecord();
        $this->em->persist($record);
        $this->em->flush();

        $this->assertEquals(new DateTimeImmutable('2026-01-01 10:00:00'), $record->createdAt);
    }

    public function testAnExplicitFalseAttributeOptInStillIgnoresTheAttributeAlone(): void
    {
        $this->assertSame([], self::ownDeprecations(self::deprecationsOf(fn () => $this->boot(null, false))));

        $record = new KernelAttributeRecord();
        $this->em->persist($record);
        $this->em->flush();

        $this->assertNull($record->createdAt);
    }

    private function boot(?string $flushMode, ?bool $attributeOptIn = true): void
    {
        (new Filesystem())->remove(sys_get_temp_dir() . '/nowo-audit-kit-test-kernel');

        $this->kernel = new AuditKitTestKernel($flushMode, null, false, $attributeOptIn);
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
        $this->doomed = new KernelArticle('Doomed');
        $this->em->persist($this->alice);
        $this->em->persist($this->stored);
        $this->em->persist($this->doomed);
        $this->em->flush();
    }

    /** A new article, a changed title and a removal (such as a sync schedule dropped when a board is unlinked). */
    private function pendingHostChanges(): KernelArticle
    {
        $pending = new KernelArticle('Pending');
        $this->em->persist($pending);
        $this->stored->title = 'Changed';
        $this->em->remove($this->doomed);

        return $pending;
    }

    /** @return list<string> */
    private function articleTitles(): array
    {
        /** @var list<string> $titles */
        $titles = $this->em->getConnection()->fetchFirstColumn('SELECT title FROM kernel_article ORDER BY title');

        return $titles;
    }

    private function actionLogCount(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM kernel_action_log');
    }
}
