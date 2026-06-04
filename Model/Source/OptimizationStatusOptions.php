<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Model\Source;

use ETechFlow\PageSpeedOptimizer\Model\OptimizationLog;
use Magento\Framework\Data\OptionSourceInterface;

class OptimizationStatusOptions implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => OptimizationLog::STATUS_OK,      'label' => __('OK')],
            ['value' => OptimizationLog::STATUS_FAILED,  'label' => __('Failed')],
            ['value' => OptimizationLog::STATUS_SKIPPED, 'label' => __('Skipped')],
        ];
    }
}
