<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Model\Image\Engine;

/**
 * Uses ImageMagick's PHP extension. Available where the host has the imagick
 * PHP extension AND ImageMagick is compiled with WebP (always-on for modern
 * builds) and/or AVIF support (built-in since ImageMagick 7.0.25 from 2020).
 *
 * Pros over GD: better quality output, supports more source formats, can
 * produce AVIF. Cons: heavier memory use, slower than the binary engines.
 */
class ImagickEngine implements ConversionEngineInterface
{
    public function getName(): string
    {
        return 'imagick';
    }

    public function available(): bool
    {
        if (!\extension_loaded('imagick') || !\class_exists(\Imagick::class)) {
            return false;
        }
        try {
            return !empty(\Imagick::queryFormats('WEBP'))
                || !empty(\Imagick::queryFormats('AVIF'));
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function supportsFormat(string $format): bool
    {
        if (!$this->available()) {
            return false;
        }
        try {
            $formatUpper = strtoupper($format);
            return !empty(\Imagick::queryFormats($formatUpper));
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function convert(string $sourcePath, string $outputPath, int $quality, string $format): bool
    {
        if (!\extension_loaded('imagick')) {
            throw new \RuntimeException('Imagick extension not loaded');
        }
        if (!is_readable($sourcePath)) {
            throw new \RuntimeException(sprintf('Imagick: source not readable: %s', $sourcePath));
        }
        if (!$this->supportsFormat($format)) {
            throw new \RuntimeException(sprintf('Imagick was not compiled with %s support', strtoupper($format)));
        }
        $quality = max(1, min(100, $quality));

        $imagick = new \Imagick();
        try {
            $imagick->readImage($sourcePath);
            $imagick->stripImage();
            $imagick->setImageFormat($format);
            $imagick->setImageCompressionQuality($quality);
            if ($format === self::FORMAT_WEBP) {
                $imagick->setOption('webp:method', '4');
            } elseif ($format === self::FORMAT_AVIF) {
                // heic:speed values map 1-10: lower = slower/smaller, 5 = balanced.
                $imagick->setOption('heic:speed', '5');
            }
            if (!$imagick->writeImage($outputPath)) {
                throw new \RuntimeException(sprintf('Imagick: writeImage failed: %s', $outputPath));
            }
        } catch (\ImagickException $e) {
            throw new \RuntimeException(sprintf('Imagick: %s', $e->getMessage()), 0, $e);
        } finally {
            $imagick->clear();
            $imagick->destroy();
        }
        return true;
    }

    public function convertToWebp(string $sourcePath, string $outputPath, int $quality): bool
    {
        return $this->convert($sourcePath, $outputPath, $quality, self::FORMAT_WEBP);
    }
}
