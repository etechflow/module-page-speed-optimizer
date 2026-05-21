<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Model\Image\Compress;

/**
 * gifsicle — GIF size reduction.
 *
 * Install:
 *   apt install gifsicle      # Debian/Ubuntu
 *   yum install gifsicle      # RHEL/CentOS
 *   brew install gifsicle     # macOS
 *
 * Typical savings: 20-40% lossless on animated GIFs (frame-pair dedupe).
 * GIFs are rare in modern e-commerce catalogs, but this engine is here
 * for legacy stores migrating from older platforms.
 */
class GifsicleEngine implements CompressionEngineInterface
{
    public function getName(): string
    {
        return 'gifsicle';
    }

    public function available(): bool
    {
        if (!\function_exists('exec')) {
            return false;
        }
        $output = [];
        $exitCode = 0;
        @\exec('command -v gifsicle 2>/dev/null', $output, $exitCode);
        return $exitCode === 0 && !empty($output);
    }

    public function supportedMimeTypes(): array
    {
        return ['image/gif'];
    }

    public function compress(string $filePath, int $quality): int
    {
        if (!is_writable($filePath)) {
            throw new \RuntimeException(sprintf('gifsicle: file not writable: %s', $filePath));
        }
        // -O3 max-level optimisation (frame-pair dedupe, palette pruning).
        // --batch lets us specify the file as input AND output.
        $cmd = sprintf(
            'gifsicle -O3 --batch %s 2>&1',
            \escapeshellarg($filePath)
        );
        $output = [];
        $exitCode = 0;
        @\exec($cmd, $output, $exitCode);
        if ($exitCode !== 0) {
            $tail = implode("\n", array_slice($output, -3));
            throw new \RuntimeException(sprintf('gifsicle failed (exit %d): %s', $exitCode, $tail));
        }
        clearstatcache(true, $filePath);
        return (int) @filesize($filePath);
    }
}
