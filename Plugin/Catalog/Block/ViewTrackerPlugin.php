<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Plugin\Catalog\Block;

use ETechFlow\PageSpeedOptimizer\Model\Config;
use ETechFlow\PageSpeedOptimizer\Model\Image\WebpGenerator;
use ETechFlow\PageSpeedOptimizer\Model\ViewQueue\ViewTracker;
use Magento\Catalog\Block\Product\Image;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;

/**
 * Smart-by-viewed: when a product image renders in the frontend, enqueue
 * its source path for cron-based optimization.
 *
 * Runs AFTER PictureBlockPlugin (sortOrder 110 vs picture's 100) so we
 * only enqueue when there's an actual product image render to track.
 *
 * Cheap by design: just extracts the src URL → maps to filesystem path →
 * fires INSERT IGNORE. Never blocks or fails the render.
 *
 * Skips when smart-by-viewed is disabled. Skips when the image has
 * already been optimized (we check by sibling .webp file existence; if
 * it exists, the source is already known to our system).
 */
class ViewTrackerPlugin
{
    public function __construct(
        private readonly Config $config,
        private readonly ViewTracker $tracker,
        private readonly Filesystem $filesystem,
        private readonly WebpGenerator $webpGenerator
    ) {
    }

    public function afterToHtml(Image $subject, $result)
    {
        if (!is_string($result) || $result === '') {
            return $result;
        }
        if (!$this->config->isSmartByViewedEnabled()) {
            return $result;
        }

        // Cheap try-catch — never break a page render
        try {
            if (preg_match('/<img\b[^>]*\bsrc\s*=\s*"([^"]+)"[^>]*\/?>/i', $result, $m)) {
                $srcUrl = $m[1];
                $absolutePath = $this->resolveUrlToFilesystemPath($srcUrl);
                if ($absolutePath !== null) {
                    // Skip if already converted (sibling .webp exists)
                    $webpPath = $this->webpGenerator->webpPathFor($absolutePath);
                    if (!is_file($webpPath)) {
                        $this->tracker->enqueue($absolutePath);
                    }
                }
            }
        } catch (\Throwable $e) {
            // Silent — page render must not break for tracking
        }

        return $result;
    }

    private function resolveUrlToFilesystemPath(string $url): ?string
    {
        $parts = parse_url($url);
        $path = $parts['path'] ?? $url;
        if (!is_string($path) || $path === '') {
            return null;
        }
        try {
            $pubDir = $this->filesystem->getDirectoryRead(DirectoryList::PUB)->getAbsolutePath();
        } catch (\Throwable $e) {
            return null;
        }
        $relative = ltrim($path, '/');
        $candidates = [
            rtrim($pubDir, '/') . '/' . $relative,
            rtrim($pubDir, '/') . '/' . preg_replace('#^(pub/)?#', '', $relative),
            rtrim($pubDir, '/') . '/media/' . preg_replace('#^(pub/)?media/#', '', $relative),
        ];
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        return null;
    }
}
