<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Observer;

use ETechFlow\PageSpeedOptimizer\Model\Config;
use ETechFlow\PageSpeedOptimizer\Model\Image\AvifGenerator;
use ETechFlow\PageSpeedOptimizer\Model\Image\Compress\SourceCompressor;
use ETechFlow\PageSpeedOptimizer\Model\Image\WebpGenerator;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Filesystem;
use Magento\Framework\App\Filesystem\DirectoryList;
use Psr\Log\LoggerInterface;

/**
 * Observer on `catalog_product_save_after`.
 *
 * When a product is saved with images, this observer:
 *   1. Compresses each source JPEG/PNG/GIF in place (if compressor available)
 *   2. Generates a WebP sibling (if WebP encoder available)
 *   3. Generates an AVIF sibling (if AVIF encoder available)
 *
 * Runs SYNCHRONOUSLY in the admin save flow — for stores with many product
 * images this can slow admin save. v2.2 will move this to a queue/cron
 * job for async processing. For v2.1 we accept the trade-off because:
 *   - Most admin saves touch 1-3 images at a time
 *   - Most stores have cwebp+jpegoptim installed and conversion is ~50ms/image
 *   - Customers who want async can disable this observer and use the
 *     bulk CLI on a cron
 *
 * Skips silently if:
 *   - PSO image optimization disabled
 *   - PSO auto-optimize-on-upload disabled
 *   - Product has no media gallery entries
 */
class AutoOptimizeOnUpload implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly SourceCompressor $sourceCompressor,
        private readonly WebpGenerator $webpGenerator,
        private readonly AvifGenerator $avifGenerator,
        private readonly Filesystem $filesystem,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->config->isImageOptimizationEnabled()) {
            return;
        }
        if (!$this->config->isAutoOptimizeOnUploadEnabled()) {
            return;
        }

        try {
            $product = $observer->getEvent()->getProduct();
            if (!$product instanceof ProductInterface) {
                return;
            }
            $gallery = $product->getMediaGalleryImages();
            if ($gallery === null) {
                return;
            }
            $mediaDir = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA)->getAbsolutePath();
            foreach ($gallery as $image) {
                $relativeFile = ltrim((string) $image->getFile(), '/');
                if ($relativeFile === '') {
                    continue;
                }
                $absolutePath = rtrim($mediaDir, '/') . '/catalog/product/' . $relativeFile;
                if (!is_file($absolutePath)) {
                    continue;
                }
                // 1. Source compression in place
                $this->sourceCompressor->compress($absolutePath);
                // 2. WebP sibling
                $this->webpGenerator->generate($absolutePath);
                // 3. AVIF sibling (silent if no encoder)
                $this->avifGenerator->generate($absolutePath);
            }
        } catch (\Throwable $e) {
            // Never break admin save on optimization failure
            $this->logger->warning(
                'ETechFlow_PSO auto-optimize observer failed',
                ['exception' => $e->getMessage()]
            );
        }
    }
}
