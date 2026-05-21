<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Cron;

use ETechFlow\PageSpeedOptimizer\Model\ViewQueue\QueueProcessor;
use Psr\Log\LoggerInterface;

/**
 * Cron entry point — wired up via etc/crontab.xml to run every 5 minutes.
 *
 * Thin wrapper around QueueProcessor so the cron framework has a clean
 * `execute()` to call.
 */
class ProcessViewQueue
{
    public function __construct(
        private readonly QueueProcessor $processor,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        try {
            $this->processor->processBatch();
        } catch (\Throwable $e) {
            // Cron framework already logs unhandled exceptions, but we
            // want a clean ETechFlow-prefixed entry in the log for grep'ability.
            $this->logger->error(
                'ETechFlow_PSO smart-by-viewed cron failed',
                ['exception' => $e->getMessage()]
            );
        }
    }
}
