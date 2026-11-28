<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Integration\Traceability;

use Nowo\AuditKitBundle\ActionLog\ActionLogRecorder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Records one row per run. */
final class TraceCommand extends Command
{
    public const NAME = 'app:trace';

    public function __construct(private readonly ActionLogRecorder $recorder)
    {
        parent::__construct(self::NAME);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->recorder->recordAndFlush('item.updated', null, null, [], 'cli');

        return Command::SUCCESS;
    }
}
