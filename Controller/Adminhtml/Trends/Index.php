<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Controller\Adminhtml\Trends;

use ETechFlow\PageSpeedOptimizer\Model\LicenseValidator;
use Magento\Backend\App\Action;
use Magento\Framework\Controller\ResultFactory;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action
{
    public const ADMIN_RESOURCE = 'ETechFlow_PageSpeedOptimizer::trends';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly LicenseValidator $licenseValidator
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        if (!$this->licenseValidator->isValid()) {
            return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath('etechflow_pso/license/gate');
        }
        $page = $this->pageFactory->create();
        $page->setActiveMenu('ETechFlow_PageSpeedOptimizer::trends');
        $page->getConfig()->getTitle()->prepend(__('Page Speed Trends'));
        return $page;
    }
}