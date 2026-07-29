<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\CloudflareCache\Plugin\Model\System\Config\Source;

use Magento\PageCache\Model\System\Config\Source\Application;
use StackNuts\CloudflareCache\Model\Config;

/**
 * Adds "Cloudflare" as a third option in Stores > Configuration > Advanced >
 * System > Full Page Cache > Caching Application, next to Built-in and Varnish.
 */
class ApplicationPlugin
{
    public function afterToOptionArray(Application $subject, array $result): array
    {
        $result[] = [
            'value' => Config::TYPE_CLOUDFLARE,
            'label' => __('Cloudflare'),
        ];

        return $result;
    }

    public function afterToArray(Application $subject, array $result): array
    {
        $result[Config::TYPE_CLOUDFLARE] = __('Cloudflare');

        return $result;
    }
}
