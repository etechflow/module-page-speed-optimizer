<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Model\Image\Compress;

/**
 * pngquant — lossy PNG palette quantization.
 *
 * Install:
 *   apt install pngquant      # Debian/Ubuntu
 *   yum install pngquant      # RHEL/CentOS
 *   brew install pngquant     # macOS
 *
 * Typical savings: 50-75% on photographic PNGs. The lossy quantization
 * is visually imperceptible at q=80+ for typical catalog images.
 */
class PngquantEngine implements CompressionEngineInterface
{
    public function getName(): string
    {
        return 'pngquant';
    }

    public function available(): bool
    {
        if (!\function_exists('exec')) {
            return false;
        }
        $output = [];
        $exitCode = 0;
        @\exec('command -v pngquant 2>/dev/null', $output, $exitCode);
        return $exitCode === 0 && !empty($output);
    }

    public function supportedMimeTypes(): array
    {
        return ['image/png'];
    }

    public function compress(string $filePath, int $quality): int
    {
        if (!is_writable($filePath)) {
            throw new \RuntimeException(sprintf('pngquant: file not writable: %s', $filePath));
        }
        // pngquant works in 0-100 quality space too. We pass a range like 65-80
        // (admin quality - 15 to admin quality) — letting pngquant pick the
        // best palette size that fits inside the range.
        $quality = max(1, min(100, $quality));
        $qmin = max(1, $quality - 15);
        $cmd = sprintf(
            'pngquant --quality=%d-%d --skip-if-larger --strip --force --output %s %s 2>&1',
            $qmin,
            $quality,
            \escapeshellarg($filePath),
            \escapeshellarg($filePath)
        );
        $output = [];
        $exitCode = 0;
        @\exec($cmd, $output, $exitCode);
        // pngquant exits non-zero (98) when --skip-if-larger triggers —
        // that's fine, means the source was already smaller than what we
        // could produce.
        if ($exitCode !== 0 && $exitCode !== 98 && $exitCode !== 99) {
            $tail = implode("\n", array_slice($output, -3));
            throw new \RuntimeException(sprintf('pngquant failed (exit %d): %s', $exitCode, $tail));
        }
        clearstatcache(true, $filePath);
        return (int) @filesize($filePath);
    }
}
