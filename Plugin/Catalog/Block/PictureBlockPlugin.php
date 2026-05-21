<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Plugin\Catalog\Block;

use ETechFlow\PageSpeedOptimizer\Model\Config;
use ETechFlow\PageSpeedOptimizer\Model\Performance\Profiler;
use Magento\Catalog\Block\Product\Image;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Psr\Log\LoggerInterface;

/**
 * Wraps Magento's product image `<img>` output in a `<picture>` element
 * with AVIF + WebP sources so capable browsers grab the smaller files.
 *
 * Hyvä compatibility: Magento\Catalog\Block\Product\Image is the SAME
 * block used by both Luma and Hyvä product templates — Hyvä just re-skins
 * the HTML wrapper around it. So this plugin works on both.
 *
 * v2.1 transformation:
 *   <img src=".../foo.jpg" alt="..." />
 * →
 *   <picture>
 *     <source srcset=".../foo.jpg.avif" type="image/avif">    (if AVIF enabled + file exists)
 *     <source srcset=".../foo.jpg.webp" type="image/webp">    (if WebP file exists)
 *     <img src=".../foo.jpg" alt="..." loading="lazy">
 *   </picture>
 *
 * Source order matters: browsers pick the FIRST source they support.
 * AVIF goes first (newest, smallest) → WebP → original.
 *
 * Defensive: if anything goes wrong (regex doesn't match, sibling files
 * don't exist), pass through the original HTML unchanged so a bug here
 * never breaks a PDP.
 */
class PictureBlockPlugin
{
    public function __construct(
        private readonly Config $config,
        private readonly Filesystem $filesystem,
        private readonly LoggerInterface $logger
    ) {
    }

    public function afterToHtml(Image $subject, $result)
    {
        if (!is_string($result) || $result === '') {
            return $result;
        }
        if (!$this->config->isEnabled()) {
            return $result;
        }
        $span = Profiler::start('ETechFlow_PSO_PictureBlock');
        try {
            return $this->transform($result);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'ETechFlow_PSO PictureBlockPlugin suppressed exception',
                ['exception' => $e->getMessage()]
            );
            return $result;
        } finally {
            Profiler::stop($span);
        }
    }

    private function transform(string $html): string
    {
        if (!preg_match('/<img\b[^>]*\bsrc\s*=\s*"([^"]+)"[^>]*\/?>/i', $html, $m)) {
            return $html;
        }
        $imgTag = $m[0];
        $srcUrl = $m[1];

        $lower = strtolower($srcUrl);
        if (str_ends_with($lower, '.webp')
            || str_ends_with($lower, '.avif')
            || str_starts_with($lower, 'data:')
            || (!str_starts_with($lower, '/') && !str_contains($lower, '://'))) {
            return $html;
        }

        $sources = [];
        // AVIF — first source (best compression, modern browsers prefer)
        if ($this->config->isAvifEnabled()) {
            $avifUrl = $srcUrl . '.avif';
            if ($this->siblingFileExists($avifUrl)) {
                $sources[] = sprintf('<source srcset="%s" type="image/avif">',
                    $this->escapeAttribute($avifUrl));
            }
        }
        // WebP — fallback source
        $webpUrl = $srcUrl . '.webp';
        if ($this->siblingFileExists($webpUrl)) {
            $sources[] = sprintf('<source srcset="%s" type="image/webp">',
                $this->escapeAttribute($webpUrl));
        }

        $wrappedImg = $this->injectLazyLoadAttribute($imgTag);

        if (empty($sources)) {
            // No modern-format siblings on disk yet — just inject lazy-load,
            // skip the <picture> wrapper.
            return $wrappedImg === $imgTag ? $html : str_replace($imgTag, $wrappedImg, $html);
        }

        $picture = sprintf(
            '<picture>%s%s</picture>',
            implode('', $sources),
            $wrappedImg
        );
        return str_replace($imgTag, $picture, $html);
    }

    private function injectLazyLoadAttribute(string $imgTag): string
    {
        if (!$this->config->isLazyLoadEnabled()) {
            return $imgTag;
        }
        if (preg_match('/\bloading\s*=/i', $imgTag)) {
            return $imgTag;
        }
        return (string) preg_replace('/(\s*\/?>)$/', ' loading="lazy"$1', $imgTag, 1);
    }

    /**
     * Resolve a URL like `https://.../pub/media/.../foo.jpg.webp` or
     * `/media/.../foo.jpg.avif` to an absolute filesystem path under
     * DirectoryList::PUB and check if the file exists.
     */
    private function siblingFileExists(string $url): bool
    {
        $parts = parse_url($url);
        $path = $parts['path'] ?? $url;
        if (!is_string($path) || $path === '') {
            return false;
        }
        try {
            $pubDir = $this->filesystem->getDirectoryRead(DirectoryList::PUB)->getAbsolutePath();
        } catch (\Throwable $e) {
            return false;
        }
        $relative = ltrim($path, '/');
        $candidates = [
            rtrim($pubDir, '/') . '/' . $relative,
            rtrim($pubDir, '/') . '/' . preg_replace('#^(pub/)?#', '', $relative),
            rtrim($pubDir, '/') . '/media/' . preg_replace('#^(pub/)?media/#', '', $relative),
        ];
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return true;
            }
        }
        return false;
    }

    private function escapeAttribute(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
