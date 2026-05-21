<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Model\Image;

use ETechFlow\PageSpeedOptimizer\Model\Image\Engine\ConversionEngineInterface;
use ETechFlow\PageSpeedOptimizer\Model\Image\Engine\EngineChain;
use ETechFlow\PageSpeedOptimizer\Model\Config;
use ETechFlow\PageSpeedOptimizer\Model\OptimizationLog;
use ETechFlow\PageSpeedOptimizer\Model\Performance\Profiler;
use ETechFlow\PageSpeedOptimizer\Model\ResourceModel\OptimizationLog as OptimizationLogResource;
use Psr\Log\LoggerInterface;

/**
 * AVIF variant of WebpGenerator.
 *
 * Same idempotency/dedupe rules: writes `<source>.avif` next to the source,
 * skips if already exists and mtime matches the log row.
 *
 * Falls through gracefully when no AVIF engine is available (cavif binary
 * not installed, no Imagick-with-libavif, no GD-with-libavif). Returns
 * RESULT_SKIPPED in that case — silent. The merchant can still ship the
 * site, they just don't get AVIF until they install an encoder.
 *
 * v2.2 will fold this into a unified MultiFormatGenerator.
 */
class AvifGenerator
{
    public const RESULT_CONVERTED = 'converted';
    public const RESULT_SKIPPED   = 'skipped';
    public const RESULT_FAILED    = 'failed';

    private const SUPPORTED_INPUT_EXT = ['jpg', 'jpeg', 'png', 'gif'];

    public function __construct(
        private readonly Config $config,
        private readonly EngineChain $engineChain,
        private readonly OptimizationLogResource $logResource,
        private readonly LoggerInterface $logger
    ) {
    }

    public function generate(string $sourcePath): string
    {
        $span = Profiler::start('ETechFlow_PSO_AvifGenerate');
        try {
            if (!is_file($sourcePath) || !is_readable($sourcePath)) {
                return self::RESULT_SKIPPED;
            }

            $ext = strtolower((string) pathinfo($sourcePath, PATHINFO_EXTENSION));
            if (!in_array($ext, self::SUPPORTED_INPUT_EXT, true)) {
                return self::RESULT_SKIPPED;
            }

            $outputPath = $this->avifPathFor($sourcePath);
            $sourceMtime = @filemtime($sourcePath) ?: null;

            if (is_file($outputPath) && $sourceMtime !== null && $this->isAlreadyLogged($sourcePath, $outputPath, $sourceMtime)) {
                return self::RESULT_SKIPPED;
            }

            $engine = $this->engineChain->getFirstAvailable(ConversionEngineInterface::FORMAT_AVIF);
            if ($engine === null) {
                // No AVIF encoder on this host. Skip silently — WebP still
                // works via the WebpGenerator path. Encourage install via verify.
                return self::RESULT_SKIPPED;
            }

            $bytesBefore = @filesize($sourcePath) ?: 0;
            $quality = $this->config->getQuality();
            $engine->convert($sourcePath, $outputPath, $quality, ConversionEngineInterface::FORMAT_AVIF);
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
                'ETechFlow_PSO AVIF conversion failed',
                ['source' => $sourcePath, 'exception' => $e->getMessage()]
            );
            try {
                $this->log(
                    sourcePath: $sourcePath,
                    outputPath: $this->avifPathFor($sourcePath),
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
                $this->logger->warning(
                    'ETechFlow_PSO failed to log a failed AVIF conversion',
                    ['exception' => $logException->getMessage()]
                );
            }
            return self::RESULT_FAILED;
        } finally {
            Profiler::stop($span);
        }
    }

    public function avifPathFor(string $sourcePath): string
    {
        return $sourcePath . '.avif';
    }

    private function isAlreadyLogged(string $sourcePath, string $outputPath, int $sourceMtime): bool
    {
        $connection = $this->logResource->getConnection();
        $select = $connection->select()
            ->from($this->logResource->getMainTable(), ['source_mtime'])
            ->where('source_path = ?', $sourcePath)
            ->where('output_path = ?', $outputPath)
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
        $connection = $this->logResource->getConnection();
        $connection->insertOnDuplicate(
            $this->logResource->getMainTable(),
            [
                'source_path'   => $sourcePath,
                'output_path'   => $outputPath,
                'format_from'   => $formatFrom,
                'format_to'     => 'avif',
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
