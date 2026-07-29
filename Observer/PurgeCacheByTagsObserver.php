<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\CloudflareCache\Observer;

use Magento\Framework\App\Cache\Tag\Resolver;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use StackNuts\CloudflareCache\Model\Config;
use StackNuts\CloudflareCache\Model\PurgeCache;

/**
 * Purges Cloudflare by tag whenever Magento invalidates cache for a specific
 * entity/set of entities, mirroring Magento\CacheInvalidate\Observer\InvalidateVarnishObserver
 * but gated on Cloudflare being the selected Full Page Cache type.
 */
class PurgeCacheByTagsObserver implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly PurgeCache $purgeCache,
        private readonly Resolver $tagResolver
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->config->isActive()) {
            return;
        }

        $object = $observer->getEvent()->getObject();
        if (!is_object($object)) {
            return;
        }

        $tags = $this->tagResolver->getTags($object);
        if ($tags) {
            $this->purgeCache->purgeByTags($tags);
        }
    }
}
