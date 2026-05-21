<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Model\Image\Compress;

/**
 * jpegoptim — lossless + lossy JPEG re-compression.
 *
 * Install:
 *   apt install jpegoptim     # Debian/Ubuntu
 *   yum install jpegoptim     # RHEL/CentOS
 *   brew install jpegoptim    # macOS
 *
 * Typical savings: 5-20% lossless, 30-50% at q=80 lossy.
 */
class JpegoptimEngine implements CompressionEngineInterface
{
    public function getName(): string
    {
        return 'jpegoptim';
    }

    public function available(): bool
    {
        if (!\function_exists('exec')) {
            return false;
        }
        $output = [];
        $exitCode = 0;
        @\exec('command -v jpegoptim 2>/dev/null', $output, $exitCode);
        return $exitCode === 0 && !empty($output);
    }

    public function supportedMimeTypes(): array
    {
        return ['image/jpeg'];
    }

    public function compress(string $filePath, int $quality): int
    {
        if (!is_writable($filePath)) {
            throw new \RuntimeException(sprintf('jpegoptim: file not writable: %s', $filePath));
        }
        $quality = max(1, min(100, $quality));
        // --strip-all          remove EXIF/IPTC/XMP (usually safe for catalog images)
        // --max=N              cap quality at N
        // --preserve           keep mtime so our idempotent dedupe still works
        // --quiet              suppress stderr
        $cmd = sprintf(
            'jpegoptim --strip-all --max=%d --preserve --quiet %s 2>&1',
            $quality,
            \escapeshellarg($filePath)
        );
        $output = [];
        $exitCode = 0;
        @\exec($cmd, $output, $exitCode);
        if ($exitCode !== 0) {
            $tail = implode("\n", array_slice($output, -3));
            throw new \RuntimeException(sprintf('jpegoptim failed (exit %d): %s', $exitCode, $tail));
        }
        clearstatcache(true, $filePath);
        return (int) @filesize($filePath);
    }
}
