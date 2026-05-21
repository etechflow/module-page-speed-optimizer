<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Model\Image\Engine;

/**
 * Format-conversion engine contract.
 *
 * v2.1+ supports BOTH WebP and AVIF. Each engine declares which formats
 * it can produce via `supportsFormat()`. The chain picks the first
 * available engine that supports the requested target format.
 *
 * Five implementations as of v2.1:
 *   - CwebpEngine    — Google libwebp binary, WebP only
 *   - CavifEngine    — Rust cavif binary, AVIF only
 *   - ImagickEngine  — PHP ext, WebP + AVIF (if compiled with libavif)
 *   - GdEngine       — PHP ext, WebP only
 *
 * Hosting heterogeneity: most shared hosts have GD; some have Imagick;
 * fewer have cwebp; very few have cavif. Chain falls back gracefully.
 */
interface ConversionEngineInterface
{
    public const FORMAT_WEBP = 'webp';
    public const FORMAT_AVIF = 'avif';

    /**
     * Engine name used in admin config + log table. Lowercase, short.
     */
    public function getName(): string;

    /**
     * Is this engine usable on the current server? Cheap check — should
     * NOT actually convert anything. Used by EngineChain to skip unusable
     * engines without touching disk.
     */
    public function available(): bool;

    /**
     * Can this engine produce the requested target format? Engines that
     * can do multiple formats (Imagick) return true for several; single-
     * format engines (cwebp, cavif) only return true for theirs.
     */
    public function supportsFormat(string $format): bool;

    /**
     * Convert a single image to the target format. Writes output to the
     * specified path. Returns true on success, throws on failure with a
     * useful message for the log table.
     *
     * @param string $sourcePath  Absolute filesystem path to source image.
     * @param string $outputPath  Absolute filesystem path to write to.
     * @param int    $quality     1-100. Engines that don't support quality MAY ignore.
     * @param string $format      'webp' | 'avif'
     * @throws \RuntimeException
     */
    public function convert(string $sourcePath, string $outputPath, int $quality, string $format): bool;

    /**
     * Backward-compat shim — convertToWebp() preserved from v2.0 for
     * any caller that hasn't been updated. Implementations dispatch to
     * convert($s, $o, $q, FORMAT_WEBP).
     */
    public function convertToWebp(string $sourcePath, string $outputPath, int $quality): bool;
}
