<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Model\ViewQueue;

use ETechFlow\PageSpeedOptimizer\Model\Config;
use ETechFlow\PageSpeedOptimizer\Model\Image\AvifGenerator;
use ETechFlow\PageSpeedOptimizer\Model\Image\Compress\SourceCompressor;
use ETechFlow\PageSpeedOptimizer\Model\Image\Resize\ImageResizer;
use ETechFlow\PageSpeedOptimizer\Model\Image\WebpGenerator;
use ETechFlow\PageSpeedOptimizer\Model\Performance\Profiler;
use Psr\Log\LoggerInterface;

/**
 * Cron + CLI worker that drains the view queue.
 *
 * For each queued source image:
 *   1. Compress source in place (if SourceCompressor enabled and an
 *      engine is installed)
 *   2. Generate WebP sibling
 *   3. Generate AVIF sibling (if AVIF enabled)
 *   4. Generate responsive variants (if image resize enabled)
 *
 * Each step is wrapped in its own try/catch so a single failure (e.g.
 * AVIF encoder missing) doesn't block subsequent steps for the same image.
 */
class QueueProcessor
{
    public function __construct(
        private readonly Config $config,
        private readonly ViewTracker $tracker,
        private readonly SourceCompressor $sourceCompressor,
        private readonly WebpGenerator $webpGenerator,
        private readonly AvifGenerator $avifGenerator,
        private readonly ImageResizer $imageResizer,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Process up to $batchSize queued entries. Returns counts.
     *
     * @return array{processed: int, failed: int, skipped: int}
     */
    public function processBatch(?int $batchSize = null): array
    {
        $counts = ['processed' => 0, 'failed' => 0, 'skipped' => 0];

        if (!$this->config->isSmartByViewedEnabled()) {
            return $counts;
        }

        $limit = $batchSize !== null ? $batchSize : $this->config->getSmartByViewedBatchSize();
        $batch = $this->tracker->dequeueBatch($limit);
        if (empty($batch)) {
            return $counts;
        }

        $span = Profiler::start('ETechFlow_PSO_SmartByViewed_Batch');
        try {
            foreach ($batch as $row) {
                $queueId = (int) $row['queue_id'];
                $sourcePath = (string) $row['source_path'];

                if (!is_file($sourcePath)) {
                    $this->tracker->markFailed($queueId, 'Source file no longer exists');
                    $counts['failed']++;
                    continue;
                }

                $errors = [];

                // 1. Source compression
                if ($this->config->isSourceCompressEnabled()) {
                    try {
                        $this->sourceCompressor->compress($sourcePath);
                    } catch (\Throwable $e) {
                        $errors[] = 'compress: ' . $e->getMessage();
                    }
                }

                // 2. WebP
                try {
                    $this->webpGenerator->generate($sourcePath);
                } catch (\Throwable $e) {
                    $errors[] = 'webp: ' . $e->getMessage();
                }

                // 3. AVIF (silent skip if no encoder)
                if ($this->config->isAvifEnabled()) {
                    try {
                        $this->avifGenerator->generate($sourcePath);
                    } catch (\Throwable $e) {
                        $errors[] = 'avif: ' . $e->getMessage();
                    }
                }

                // 4. Responsive variants
                if ($this->config->isImageResizeEnabled()) {
                    try {
                        $this->imageResizer->generateVariants($sourcePath);
                    } catch (\Throwable $e) {
                        $errors[] = 'resize: ' . $e->getMessage();
                    }
                }

                if (empty($errors)) {
                    $this->tracker->markProcessed($queueId);
                    $counts['processed']++;
                } else {
                    $this->tracker->markFailed($queueId, implode('; ', $errors));
                    $counts['failed']++;
                }
            }
        } finally {
            Profiler::stop($span);
        }

        $this->logger->info('ETechFlow_PSO view-queue batch processed', $counts);
        return $counts;
    }
}
