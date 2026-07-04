<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Controller\Adminhtml\OptimizationLog;

use ETechFlow\PageSpeedOptimizer\Model\ResourceModel\OptimizationLog\CollectionFactory;
use ETechFlow\PageSpeedOptimizer\Model\ResourceModel\OptimizationLog as LogResource;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Filesystem;
use Magento\Ui\Component\MassAction\Filter;
use Psr\Log\LoggerInterface;

/**
 * Restore originals — deletes the .webp file on disk AND removes the
 * log row. After this runs, the picture-block plugin no longer finds
 * a WebP sibling for those source images, so it falls back to plain
 * <img> rendering.
 *
 * Defensive: never deletes anything outside DirectoryList::PUB. A
 * tampered output_path can't be used to delete unrelated files.
 */
class MassRestore extends Action
{
    public const ADMIN_RESOURCE = 'ETechFlow_PageSpeedOptimizer::optimizationlog_restore';

    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly CollectionFactory $collectionFactory,
        private readonly LogResource $logResource,
        private readonly Filesystem $filesystem,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $collection = $this->filter->getCollection($this->collectionFactory->create());

        try {
            $pubDir = rtrim($this->filesystem->getDirectoryRead(DirectoryList::PUB)->getAbsolutePath(), '/');
        } catch (\Throwable $e) {
            $pubDir = '';
        }

        $filesRemoved = 0;
        $logsRemoved = 0;
        foreach ($collection as $log) {
            $outputPath = (string) $log->getOutputPath();
            // Resolve to absolute path under pub/ and ensure no path-traversal.
            if ($outputPath !== '' && $pubDir !== '') {
                $absolute = $this->resolveSafe($pubDir, $outputPath);
                if ($absolute !== null && is_file($absolute)) {
                    if (@\unlink($absolute)) {
                        $filesRemoved++;
                    }
                }
            }
            try {
                $this->logResource->delete($log);
                $logsRemoved++;
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'ETechFlow_IO MassRestore failed to delete log row',
                    ['log_id' => $log->getLogId(), 'exception' => $e->getMessage()]
                );
            }
        }

        $this->messageManager->addSuccessMessage(
            __('Restored %1 entries: deleted %2 WebP file(s) from disk + %3 log row(s).',
               $logsRemoved, $filesRemoved, $logsRemoved)
        );

        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        return $redirect->setPath('*/*/index');
    }

    /**
     * Safely resolve a stored output path into an absolute filesystem path
     * UNDER pub/. Returns null on anything suspicious (path traversal, file
     * outside pub/, non-existent intermediate).
     */
    private function resolveSafe(string $pubDir, string $outputPath): ?string
    {
        // Normalise the stored path — may be absolute, relative, or
        // partially-relative depending on what WebpGenerator stored.
        $candidate = $outputPath;
        if (!str_starts_with($candidate, '/')) {
            $candidate = $pubDir . '/' . $candidate;
        }
        // Real-path to collapse symlinks + relative segments.
        $resolved = @realpath($candidate);
        if ($resolved === false) {
            // File doesn't exist OR an intermediate dir doesn't. Either way, skip.
            return null;
        }
        // Must be inside pub/.
        if (!str_starts_with($resolved, $pubDir . '/')) {
            return null;
        }
        // Must be a .webp (we're not deleting source JPEGs by accident).
        if (!str_ends_with($resolved, '.webp')) {
            return null;
        }
        return $resolved;
    }
}
