<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Model\Image\Compress;

/**
 * Source-file compression engine contract.
 *
 * Distinct from ConversionEngineInterface — these engines REWRITE the
 * source file in place (lossless or near-lossless) rather than producing
 * a new format. Run before WebP/AVIF conversion to compound the savings.
 *
 * Three engines:
 *   - JpegoptimEngine — for JPEG sources
 *   - PngquantEngine  — for PNG sources (quantises palette)
 *   - GifsicleEngine  — for GIF sources
 */
interface CompressionEngineInterface
{
    public function getName(): string;

    /**
     * Is this engine usable on the current server?
     */
    public function available(): bool;

    /**
     * Mime types this engine handles: ['image/jpeg'] | ['image/png'] | ['image/gif'].
     *
     * @return string[]
     */
    public function supportedMimeTypes(): array;

    /**
     * Rewrite the source file in place to a smaller size at the requested
     * quality. Returns the new file size in bytes on success.
     *
     * @param int $quality 1-100. 100 = lossless (where supported).
     * @throws \RuntimeException
     */
    public function compress(string $filePath, int $quality): int;
}
