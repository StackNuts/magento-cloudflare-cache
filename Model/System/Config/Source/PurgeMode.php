<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\CloudflareCache\Model\System\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use StackNuts\CloudflareCache\Model\Config;

class PurgeMode implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            [
                'value' => Config::PURGE_MODE_TAGS,
                'label' => __('Purge by tag (recommended)'),
            ],
            [
                'value' => Config::PURGE_MODE_FULL_FLUSH_ONLY,
                'label' => __('Full flush only (manual purge required for individual page changes)'),
            ],
        ];
    }
}
