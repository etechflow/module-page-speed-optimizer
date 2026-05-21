<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Console\Command;

use ETechFlow\PageSpeedOptimizer\Model\ViewQueue\QueueProcessor;
use ETechFlow\PageSpeedOptimizer\Model\ViewQueue\ViewTracker;
use Magento\Framework\App\Area;
use Magento\Framework\App\State as AppState;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento etechflow:pso:process-view-queue [--limit=20] [--status]`
 *
 * Manual trigger of the smart-by-viewed cron worker. Useful for:
 *   - Initial backfill (run with a large --limit after enabling the feature)
 *   - Debugging stuck queue entries
 *   - CI pipelines that want explicit control
 *
 * The same QueueProcessor.processBatch() is also invoked from crontab.xml
 * every 5 minutes by default.
 */
class ProcessViewQueueCommand extends Command
{
    private const OPT_LIMIT  = 'limit';
    private const OPT_STATUS = 'status';

    public function __construct(
        private readonly AppState $appState,
        private readonly QueueProcessor $processor,
        private readonly ViewTracker $tracker
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('etechflow:pso:process-view-queue')
            ->setDescription('Process the smart-by-viewed image queue (run by cron every 5 min by default).')
            ->addOption(self::OPT_LIMIT, null, InputOption::VALUE_REQUIRED,
                'Max entries to process this run (default: admin batch_size, fallback 20).')
            ->addOption(self::OPT_STATUS, null, InputOption::VALUE_NONE,
                'Show queue status counts and exit without processing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_GLOBAL);
        } catch (\Exception $e) {
            // already set
        }

        if ($input->getOption(self::OPT_STATUS)) {
            $counts = $this->tracker->getStatusCounts();
            $output->writeln('');
            $output->writeln('=== Smart-by-viewed queue status ===');
            $output->writeln(sprintf('  <comment>Queued:</comment>     %d', $counts['queued']));
            $output->writeln(sprintf('  <comment>Processing:</comment> %d', $counts['processing']));
            $output->writeln(sprintf('  <info>Processed:</info>  %d', $counts['processed']));
            $output->writeln(sprintf('  <error>Failed:</error>     %d', $counts['failed']));
            $output->writeln('');
            return Command::SUCCESS;
        }

        $limit = $input->getOption(self::OPT_LIMIT) !== null
            ? (int) $input->getOption(self::OPT_LIMIT)
            : null;

        $output->writeln('<info>Processing view queue...</info>');
        $counts = $this->processor->processBatch($limit);
        $output->writeln(sprintf(
            '  Processed: <info>%d</info>   Failed: <error>%d</error>   Skipped: %d',
            $counts['processed'], $counts['failed'], $counts['skipped']
        ));
        return Command::SUCCESS;
    }
}
