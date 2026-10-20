<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\ActionLog\Command;

use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuditKitBundle\ActionLog\AbstractActionLog;
use Nowo\AuditKitBundle\ActionLog\ActionLogRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function is_array;
use function sprintf;

/**
 * Deletes action log rows older than N days, in keyset batches.
 *
 * Without `--action`, only the configured `nowo_audit_kit.action_log.purge.actions` are purged (e.g. page views,
 * keeping audit rows). `--all-actions` purges every action; with no action list and no flag the command refuses.
 */
#[AsCommand(name: self::NAME, description: 'Delete action log rows older than N days (configured actions by default)')]
final class PurgeActionLogCommand extends Command
{
    public const NAME = 'nowo:audit-kit:action-log:purge';

    /**
     * @param class-string<AbstractActionLog> $entityClass
     * @param list<string> $defaultActions
     */
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly string $entityClass,
        private readonly array $defaultActions = [],
        private readonly int $defaultDays = 90,
        private readonly int $batchSize = ActionLogRepository::DEFAULT_BATCH_SIZE,
        private readonly ?ClockInterface $clock = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('older-than-days', null, InputOption::VALUE_REQUIRED, 'Keep rows from the last N days', (string) $this->defaultDays)
            ->addOption('action', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Action to purge (repeatable); defaults to the configured purge actions')
            ->addOption('all-actions', null, InputOption::VALUE_NONE, 'Purge every action (ignores the configured list)')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Rows deleted per batch', (string) $this->batchSize);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $days      = (int) $input->getOption('older-than-days');
        $batchSize = (int) $input->getOption('batch-size');
        if ($days < 1 || $batchSize < 1) {
            $io->error('--older-than-days and --batch-size must be at least 1.');

            return Command::INVALID;
        }

        $actions = null;
        if ($input->getOption('all-actions') !== true) {
            $option  = $input->getOption('action');
            $actions = is_array($option) && $option !== [] ? array_values(array_map(strval(...), $option)) : $this->defaultActions;
            if ($actions === []) {
                $io->error('No action to purge: pass --action=<key> (repeatable), configure nowo_audit_kit.action_log.purge.actions, or use --all-actions.');

                return Command::INVALID;
            }
        }

        $repository = $this->registry->getRepository($this->entityClass);
        if (!$repository instanceof ActionLogRepository) {
            $repository = new ActionLogRepository($this->registry, $this->entityClass);
        }

        // REQ-DI-001: the cutoff comes from the injected PSR-20 clock (the bundle wires the app clock service).
        $now     = $this->clock?->now() ?? new DateTimeImmutable();
        $deleted = $repository->purgeBefore($now->modify(sprintf('-%d days', $days)), $actions, $batchSize);
        $io->success(sprintf(
            'Action log: %d row(s) older than %d days purged (%s).',
            $deleted,
            $days,
            $actions === null ? 'all actions' : implode(', ', $actions),
        ));

        return Command::SUCCESS;
    }
}
