<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Controller\Adminhtml\OptimizationLog;

use ETechFlow\PageSpeedOptimizer\Model\ResourceModel\OptimizationLog\CollectionFactory;
use ETechFlow\PageSpeedOptimizer\Model\ResourceModel\OptimizationLog as LogResource;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Ui\Component\MassAction\Filter;

/**
 * Mass delete LOG ROWS ONLY — does NOT touch the .webp file on disk.
 *
 * Use this when you just want to clear stale audit entries (e.g., for
 * images that were deleted from the catalog). The WebP file stays — if
 * you re-run etechflow:pso:optimize-images, it gets re-logged.
 *
 * Use MassRestore (the sibling controller) if you want to actually
 * remove the .webp files from disk too.
 */
class MassDelete extends Action
{
    public const ADMIN_RESOURCE = 'ETechFlow_PageSpeedOptimizer::optimizationlog_delete';

    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly CollectionFactory $collectionFactory,
        private readonly LogResource $logResource
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $collection = $this->filter->getCollection($this->collectionFactory->create());

        $deleted = 0;
        foreach ($collection as $log) {
            try {
                $this->logResource->delete($log);
                $deleted++;
            } catch (\Throwable $e) {
                // swallow — counted by absence in success count
            }
        }

        $this->messageManager->addSuccessMessage(
            __('Deleted %1 log entries. WebP files on disk are unaffected.', $deleted)
        );

        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        return $redirect->setPath('*/*/index');
    }
}
