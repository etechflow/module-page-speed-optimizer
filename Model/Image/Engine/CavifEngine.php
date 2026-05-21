<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Model\Image\Engine;

/**
 * Shells out to the `cavif` binary (Rust port of libavif).
 *
 * AVIF (AV1 Image File Format) is ~30-50% smaller than WebP at equivalent
 * visual quality. Supported in Chrome 85+, Firefox 93+, Safari 16.4+ —
 * meaningful coverage in 2026.
 *
 * Install:
 *   cargo install cavif         # any host with Rust
 *   brew install cavif-rs       # macOS
 *   (Linux: build from source — there's no widely-packaged distribution
 *    package yet)
 *
 * Falls through silently in EngineChain if not available.
 */
class CavifEngine implements ConversionEngineInterface
{
    public function getName(): string
    {
        return 'cavif';
    }

    public function available(): bool
    {
        if (!\function_exists('exec')) {
            return false;
        }
        $output = [];
        $exitCode = 0;
        @\exec('command -v cavif 2>/dev/null', $output, $exitCode);
        return $exitCode === 0 && !empty($output);
    }

    public function supportsFormat(string $format): bool
    {
        return $format === self::FORMAT_AVIF;
    }

    public function convert(string $sourcePath, string $outputPath, int $quality, string $format): bool
    {
        if ($format !== self::FORMAT_AVIF) {
            throw new \RuntimeException(sprintf('cavif does not support format: %s', $format));
        }
        if (!is_readable($sourcePath)) {
            throw new \RuntimeException(sprintf('cavif: source not readable: %s', $sourcePath));
        }
        $quality = max(1, min(100, $quality));
        // cavif args:
        //   --quality N         1-100
        //   --speed N           1 (slowest, smallest) - 10 (fastest, bigger).
        //                       5 is the sweet spot for production cron batches.
        //   -o PATH             output file
        $cmd = sprintf(
            'cavif --quality %d --speed 5 -o %s %s 2>&1',
            $quality,
            \escapeshellarg($outputPath),
            \escapeshellarg($sourcePath)
        );
        $output = [];
        $exitCode = 0;
        @\exec($cmd, $output, $exitCode);
        if ($exitCode !== 0 || !is_file($outputPath)) {
            $tail = implode("\n", array_slice($output, -5));
            throw new \RuntimeException(sprintf('cavif failed (exit %d): %s', $exitCode, $tail));
        }
        return true;
    }

    public function convertToWebp(string $sourcePath, string $outputPath, int $quality): bool
    {
        throw new \RuntimeException('cavif does not support WebP output');
    }
}
