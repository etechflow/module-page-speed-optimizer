<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Model\Image\Engine;

/**
 * Uses PHP's GD extension. Most universally available — PHP 7.0+ has
 * `imagewebp()` when compiled with `--with-webp`. AVIF support arrived
 * in PHP 8.1 via `imageavif()` (compiled with `--with-avif`); rare on
 * shared hosts.
 *
 * Pros: works almost everywhere with no extra setup.
 * Cons: slightly larger output than cwebp (~5-10%), no support for
 * very-high-resolution images (4K+) without memory tuning.
 */
class GdEngine implements ConversionEngineInterface
{
    public function getName(): string
    {
        return 'gd';
    }

    public function available(): bool
    {
        return \extension_loaded('gd') && \function_exists('imagewebp');
    }

    public function supportsFormat(string $format): bool
    {
        if (!\extension_loaded('gd')) {
            return false;
        }
        if ($format === self::FORMAT_WEBP) {
            return \function_exists('imagewebp');
        }
        if ($format === self::FORMAT_AVIF) {
            return \function_exists('imageavif');
        }
        return false;
    }

    public function convert(string $sourcePath, string $outputPath, int $quality, string $format): bool
    {
        if (!is_readable($sourcePath)) {
            throw new \RuntimeException(sprintf('GD: source not readable: %s', $sourcePath));
        }
        if (!$this->supportsFormat($format)) {
            throw new \RuntimeException(sprintf('GD: target format %s not available', $format));
        }
        $quality = max(1, min(100, $quality));

        $mime = (string) @\mime_content_type($sourcePath);
        $resource = false;
        switch ($mime) {
            case 'image/jpeg':
                $resource = @\imagecreatefromjpeg($sourcePath);
                break;
            case 'image/png':
                $resource = @\imagecreatefrompng($sourcePath);
                if ($resource !== false) {
                    \imagepalettetotruecolor($resource);
                    \imagealphablending($resource, true);
                    \imagesavealpha($resource, true);
                }
                break;
            case 'image/gif':
                $resource = @\imagecreatefromgif($sourcePath);
                break;
            default:
                throw new \RuntimeException(sprintf('GD: unsupported MIME type %s for %s', $mime, $sourcePath));
        }
        if ($resource === false) {
            throw new \RuntimeException(sprintf('GD: failed to load source image: %s', $sourcePath));
        }
        try {
            if ($format === self::FORMAT_WEBP) {
                if (!\imagewebp($resource, $outputPath, $quality)) {
                    throw new \RuntimeException(sprintf('GD: imagewebp() failed for %s', $outputPath));
                }
            } elseif ($format === self::FORMAT_AVIF) {
                if (!\imageavif($resource, $outputPath, $quality)) {
                    throw new \RuntimeException(sprintf('GD: imageavif() failed for %s', $outputPath));
                }
            } else {
                throw new \RuntimeException(sprintf('GD: unsupported target format %s', $format));
            }
        } finally {
            \imagedestroy($resource);
        }
        return true;
    }

    public function convertToWebp(string $sourcePath, string $outputPath, int $quality): bool
    {
        return $this->convert($sourcePath, $outputPath, $quality, self::FORMAT_WEBP);
    }
}
