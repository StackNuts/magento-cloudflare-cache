<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\CloudflareCache\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use StackNuts\CloudflareCache\Model\Config;
use StackNuts\CloudflareCache\Model\PurgeCache;

/**
 * Purges the entire Cloudflare cache on full-flush events, mirroring
 * Magento\CacheInvalidate\Observer\FlushAllCacheObserver but gated on
 * Cloudflare being the selected Full Page Cache type.
 */
class FlushAllCacheObserver implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly PurgeCache $purgeCache
    ) {
    }

    public function execute(Observer $observer): void
    {
        if ($this->config->isActive()) {
            $this->purgeCache->purgeAll();
        }
    }
}
