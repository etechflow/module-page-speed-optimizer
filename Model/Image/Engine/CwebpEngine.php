<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Model\Image\Engine;

/**
 * Shells out to the `cwebp` binary from Google's libwebp package. WebP only.
 *
 * Available on most Linux/macOS hosts via:
 *   apt install webp           # Debian/Ubuntu
 *   yum install libwebp-tools  # RHEL/CentOS
 *   brew install webp          # macOS
 *
 * Smallest WebP files of any encoder (Google's reference implementation).
 */
class CwebpEngine implements ConversionEngineInterface
{
    public function getName(): string
    {
        return 'cwebp';
    }

    public function available(): bool
    {
        if (!\function_exists('exec')) {
            return false;
        }
        $output = [];
        $exitCode = 0;
        @\exec('command -v cwebp 2>/dev/null', $output, $exitCode);
        return $exitCode === 0 && !empty($output);
    }

    public function supportsFormat(string $format): bool
    {
        return $format === self::FORMAT_WEBP;
    }

    public function convert(string $sourcePath, string $outputPath, int $quality, string $format): bool
    {
        if ($format !== self::FORMAT_WEBP) {
            throw new \RuntimeException(sprintf('cwebp does not support format: %s', $format));
        }
        if (!is_readable($sourcePath)) {
            throw new \RuntimeException(sprintf('cwebp: source not readable: %s', $sourcePath));
        }
        $quality = max(1, min(100, $quality));
        $cmd = sprintf(
            'cwebp -q %d -m 4 -mt -quiet %s -o %s 2>&1',
            $quality,
            \escapeshellarg($sourcePath),
            \escapeshellarg($outputPath)
        );
        $output = [];
        $exitCode = 0;
        @\exec($cmd, $output, $exitCode);
        if ($exitCode !== 0 || !is_file($outputPath)) {
            $tail = implode("\n", array_slice($output, -5));
            throw new \RuntimeException(sprintf('cwebp failed (exit %d): %s', $exitCode, $tail));
        }
        return true;
    }

    public function convertToWebp(string $sourcePath, string $outputPath, int $quality): bool
    {
        return $this->convert($sourcePath, $outputPath, $quality, self::FORMAT_WEBP);
    }
}
