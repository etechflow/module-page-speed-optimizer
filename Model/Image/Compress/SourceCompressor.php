<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Model\Image\Compress;

use ETechFlow\PageSpeedOptimizer\Model\Config;
use ETechFlow\PageSpeedOptimizer\Model\OptimizationLog;
use ETechFlow\PageSpeedOptimizer\Model\Performance\Profiler;
use ETechFlow\PageSpeedOptimizer\Model\ResourceModel\OptimizationLog as OptimizationLogResource;
use Psr\Log\LoggerInterface;

/**
 * Dispatches a source file to the right compression engine based on its
 * MIME type and runs the compression. Rewrites the source in place.
 *
 * Pairs with WebpGenerator + AvifGenerator: typical pipeline is
 *   compress source → generate WebP from compressed → generate AVIF from compressed
 * so both modern formats benefit from the source-level shrinkage.
 *
 * Skips gracefully when no compressor is installed (admin gets a warning
 * in verify CLI) — the source file is left as-is.
 */
class SourceCompressor
{
    public const RESULT_COMPRESSED = 'compressed';
    public const RESULT_SKIPPED    = 'skipped';
    public const RESULT_FAILED     = 'failed';

    /** @var CompressionEngineInterface[] */
    private array $engines;

    /** @var array<string, bool> */
    private array $availabilityCache = [];

    public function __construct(
        private readonly Config $config,
        JpegoptimEngine $jpegoptim,
        PngquantEngine $pngquant,
        GifsicleEngine $gifsicle,
        private readonly OptimizationLogResource $logResource,
        private readonly LoggerInterface $logger
    ) {
        $this->engines = [
            $jpegoptim->getName() => $jpegoptim,
            $pngquant->getName()  => $pngquant,
            $gifsicle->getName()  => $gifsicle,
        ];
    }

    /**
     * Compress one file in place. Returns result code.
     */
    public function compress(string $filePath): string
    {
        $span = Profiler::start('ETechFlow_PSO_SourceCompress');
        try {
            if (!is_file($filePath) || !is_writable($filePath)) {
                return self::RESULT_SKIPPED;
            }
            $mime = (string) @\mime_content_type($filePath);
            $engine = $this->findEngineForMime($mime);
            if ($engine === null) {
                return self::RESULT_SKIPPED;
            }
            $bytesBefore = (int) @filesize($filePath);
            $quality = $this->config->getQuality();
            $bytesAfter = $engine->compress($filePath, $quality);

            if ($bytesAfter === 0 || $bytesAfter >= $bytesBefore) {
                // No improvement — silently skip the log row.
                return self::RESULT_SKIPPED;
            }

            $savings = $bytesBefore > 0
                ? (int) round((($bytesBefore - $bytesAfter) * 100) / $bytesBefore)
                : 0;

            $this->log($filePath, $mime, $bytesBefore, $bytesAfter, $savings, $engine->getName(), null);
            return self::RESULT_COMPRESSED;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'ETechFlow_PSO source compression failed',
                ['file' => $filePath, 'exception' => $e->getMessage()]
            );
            try {
                $this->log($filePath, (string) @mime_content_type($filePath),
                    (int) @filesize($filePath), 0, 0, 'none', $e->getMessage());
            } catch (\Throwable $logException) {
                // ignore
            }
            return self::RESULT_FAILED;
        } finally {
            Profiler::stop($span);
        }
    }

    /**
     * For verify CLI: report each known compressor's availability.
     *
     * @return array<string, bool>
     */
    public function getAvailabilityReport(): array
    {
        $report = [];
        foreach ($this->engines as $name => $engine) {
            $report[$name] = $this->isAvailable($name);
        }
        return $report;
    }

    private function findEngineForMime(string $mime): ?CompressionEngineInterface
    {
        foreach ($this->engines as $name => $engine) {
            if (!in_array($mime, $engine->supportedMimeTypes(), true)) {
                continue;
            }
            if (!$this->isAvailable($name)) {
                continue;
            }
            return $engine;
        }
        return null;
    }

    private function isAvailable(string $name): bool
    {
        if (!isset($this->availabilityCache[$name])) {
            $this->availabilityCache[$name] = $this->engines[$name]->available();
        }
        return $this->availabilityCache[$name];
    }

    private function log(
        string $filePath,
        string $mime,
        int $bytesBefore,
        int $bytesAfter,
        int $savingsPct,
        string $engine,
        ?string $errorMessage
    ): void {
        $format = $this->mimeToFormat($mime);
        $connection = $this->logResource->getConnection();
        $connection->insertOnDuplicate(
            $this->logResource->getMainTable(),
            [
                'source_path'   => $filePath,
                'output_path'   => $filePath,           // compressed in place
                'format_from'   => $format,
                'format_to'     => $format,             // same format, just smaller
                'bytes_before'  => $bytesBefore,
                'bytes_after'   => $bytesAfter,
                'savings_pct'   => $savingsPct,
                'engine'        => $engine,
                'source_mtime'  => @filemtime($filePath) ?: null,
                'status'        => $errorMessage === null ? OptimizationLog::STATUS_OK : OptimizationLog::STATUS_FAILED,
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

    private function mimeToFormat(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpeg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            default      => 'unknown',
        };
    }
}
