<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\CloudflareCache\Model\System\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Monolog\Logger as MonologLogger;

/**
 * Only lists the levels this module actually emits (see Logger\Logger's callers in
 * Model\PurgeCache, Cron\DrainPurgeQueue, Plugin\Model\Layout\CacheTagHeaderPlugin) -
 * not the full PSR-3 level set, since exposing levels nothing ever logs at would just
 * be confusing dead options in the dropdown.
 */
class LogLevel implements OptionSourceInterface
{
    public const LEVEL_OFF = 0;

    public function toOptionArray(): array
    {
        return [
            ['value' => self::LEVEL_OFF, 'label' => __('Off')],
            ['value' => MonologLogger::ERROR, 'label' => __('Error')],
            ['value' => MonologLogger::WARNING, 'label' => __('Warning (recommended)')],
            ['value' => MonologLogger::INFO, 'label' => __('Info')],
            ['value' => MonologLogger::DEBUG, 'label' => __('Debug')],
        ];
    }
}
