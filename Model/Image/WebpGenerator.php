<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Model\Image;

use ETechFlow\PageSpeedOptimizer\Model\Image\Engine\EngineChain;
use ETechFlow\PageSpeedOptimizer\Model\Config;
use ETechFlow\PageSpeedOptimizer\Model\OptimizationLog;
use ETechFlow\PageSpeedOptimizer\Model\OptimizationLogFactory;
use ETechFlow\PageSpeedOptimizer\Model\Performance\Profiler;
use ETechFlow\PageSpeedOptimizer\Model\ResourceModel\OptimizationLog as OptimizationLogResource;
use Psr\Log\LoggerInterface;

/**
 * Converts a single source image to WebP and records the result.
 *
 * Idempotency: if the source's mtime matches what we last logged, skip
 * the conversion. Customers can re-run the bulk CLI nightly via cron
 * without redoing work — only changed images get reconverted.
 *
 * Skips silently if:
 *   - Source is already a WebP (no point converting WebP → WebP)
 *   - Source is unreadable
 *   - Output WebP exists AND matches the logged mtime
 *
 * Throws on real failure (engine error) so the caller can record + retry.
 */
class WebpGenerator
{
    /** Result codes returned by generate() so the caller can count outcomes. */
    public const RESULT_CONVERTED = 'converted';
    public const RESULT_SKIPPED   = 'skipped';
    public const RESULT_FAILED    = 'failed';

    private const SUPPORTED_INPUT_EXT = ['jpg', 'jpeg', 'png', 'gif'];

    public function __construct(
        private readonly Config $config,
        private readonly EngineChain $engineChain,
        private readonly OptimizationLogFactory $logFactory,
        private readonly OptimizationLogResource $logResource,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Convert one file. Returns one of the RESULT_* constants.
     *
     * @param string $sourcePath Absolute filesystem path under pub/.
     * @return string RESULT_*
     */
    public function generate(string $sourcePath): string
    {
        $span = Profiler::start('ETechFlow_PSO_WebpGenerate');
        try {
            if (!is_file($sourcePath) || !is_readable($sourcePath)) {
                return self::RESULT_SKIPPED;
            }

            $ext = strtolower((string) pathinfo($sourcePath, PATHINFO_EXTENSION));
            if (!in_array($ext, self::SUPPORTED_INPUT_EXT, true)) {
                return self::RESULT_SKIPPED;
            }

            $outputPath = $this->webpPathFor($sourcePath);
            $sourceMtime = @filemtime($sourcePath) ?: null;

            // Dedupe: if the output exists AND we have a log row matching
            // the source mtime, skip — already converted from this source
            // version.
            if (is_file($outputPath) && $sourceMtime !== null && $this->isAlreadyLogged($sourcePath, $sourceMtime)) {
                return self::RESULT_SKIPPED;
            }

            $engine = $this->engineChain->getFirstAvailable();
            if ($engine === null) {
                throw new \RuntimeException(
                    'No WebP conversion engine available — install one of: cwebp binary, php-imagick (with WebP), php-gd (with WebP).'
                );
            }

            $bytesBefore = @filesize($sourcePath) ?: 0;
            $quality = $this->config->getQuality();
            $engine->convertToWebp($sourcePath, $outputPath, $quality);
            $bytesAfter = @filesize($outputPath) ?: 0;

            $savings = $bytesBefore > 0
                ? (int) round((($bytesBefore - $bytesAfter) * 100) / $bytesBefore)
                : 0;

            $this->log(
                sourcePath: $sourcePath,
                outputPath: $outputPath,
                formatFrom: $ext === 'jpg' ? 'jpeg' : $ext,
                bytesBefore: $bytesBefore,
                bytesAfter: $bytesAfter,
                savingsPct: $savings,
                engine: $engine->getName(),
                sourceMtime: $sourceMtime,
                status: OptimizationLog::STATUS_OK,
                errorMessage: null
            );
            return self::RESULT_CONVERTED;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'ETechFlow_IO WebP conversion failed',
                ['source' => $sourcePath, 'exception' => $e->getMessage()]
            );
            // Record the failure so the admin grid can surface it.
            try {
                $this->log(
                    sourcePath: $sourcePath,
                    outputPath: $this->webpPathFor($sourcePath),
                    formatFrom: strtolower((string) pathinfo($sourcePath, PATHINFO_EXTENSION)),
                    bytesBefore: (int) (@filesize($sourcePath) ?: 0),
                    bytesAfter: 0,
                    savingsPct: 0,
                    engine: 'none',
                    sourceMtime: @filemtime($sourcePath) ?: null,
                    status: OptimizationLog::STATUS_FAILED,
                    errorMessage: $e->getMessage()
                );
            } catch (\Throwable $logException) {
                // Don't recursively explode if logging itself fails.
                $this->logger->warning(
                    'ETechFlow_IO failed to log a failed conversion',
                    ['exception' => $logException->getMessage()]
                );
            }
            return self::RESULT_FAILED;
        } finally {
            Profiler::stop($span);
        }
    }

    /**
     * WebP output path = source path with `.webp` appended (not replacing
     * the extension). This keeps the source still resolvable + the WebP
     * served as a sibling. e.g.:
     *   pub/media/.../foo.jpg  →  pub/media/.../foo.jpg.webp
     */
    public function webpPathFor(string $sourcePath): string
    {
        return $sourcePath . '.webp';
    }

    private function isAlreadyLogged(string $sourcePath, int $sourceMtime): bool
    {
        $connection = $this->logResource->getConnection();
        $select = $connection->select()
            ->from($this->logResource->getMainTable(), ['source_mtime'])
            ->where('source_path = ?', $sourcePath)
            ->where('status = ?', OptimizationLog::STATUS_OK)
            ->limit(1);
        $logged = $connection->fetchOne($select);
        return $logged !== false && (int) $logged === $sourceMtime;
    }

    private function log(
        string $sourcePath,
        string $outputPath,
        string $formatFrom,
        int $bytesBefore,
        int $bytesAfter,
        int $savingsPct,
        string $engine,
        ?int $sourceMtime,
        string $status,
        ?string $errorMessage
    ): void {
        // Upsert on (source_path, output_path) — if we've logged this pair
        // before (e.g., from a previous failed run), update the row rather
        // than throwing a unique-constraint violation.
        $connection = $this->logResource->getConnection();
        $connection->insertOnDuplicate(
            $this->logResource->getMainTable(),
            [
                'source_path'   => $sourcePath,
                'output_path'   => $outputPath,
                'format_from'   => $formatFrom,
                'format_to'     => 'webp',
                'bytes_before'  => $bytesBefore,
                'bytes_after'   => $bytesAfter,
                'savings_pct'   => $savingsPct,
                'engine'        => $engine,
                'source_mtime'  => $sourceMtime,
                'status'        => $status,
                'error_message' => $errorMessage,
                'optimized_at'  => date('Y-m-d H:i:s'),
            ],
            [
                'format_from', 'format_to', 'bytes_before', 'bytes_after',
                'savings_pct', 'engine', 'source_mtime', 'status',
                'error_message', 'optimized_at',
            ]
        );
    }
}
