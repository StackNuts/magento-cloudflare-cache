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
use StackNuts\CloudflareCache\Model\ResourceModel\PurgeQueue;

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
        private readonly Resolver $tagResolver,
        private readonly PurgeQueue $purgeQueue
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
        if (!$tags) {
            return;
        }

        $tags = $this->filterExcludedTags($tags);
        if (!$tags) {
            return;
        }

        // Only tag-purge mode with the queue enabled defers to it; everything else
        // (full-flush-only mode, or the queue disabled) purges immediately exactly as it
        // always has - purgeByTags() already no-ops safely for full-flush-only mode on its own.
        if (!$this->config->isTagPurgeMode() || !$this->config->isQueueEnabled()) {
            $this->purgeCache->purgeByTags($tags);
            return;
        }

        $this->purgeQueue->enqueue($tags);
    }

    /**
     * Drops tags matching an admin-configured exclusion pattern (e.g. gql_* GraphQL cache
     * tags) before they ever reach Cloudflare - see Model\Config::getExcludedTagPatterns().
     *
     * @param string[] $tags
     * @return string[]
     */
    private function filterExcludedTags(array $tags): array
    {
        $patterns = $this->config->getExcludedTagPatterns();
        if (!$patterns) {
            return $tags;
        }

        return array_values(array_filter($tags, function (string $tag) use ($patterns): bool {
            foreach ($patterns as $pattern) {
                if (str_ends_with($pattern, '*')) {
                    if (str_starts_with($tag, rtrim($pattern, '*'))) {
                        return false;
                    }
                } elseif ($tag === $pattern) {
                    return false;
                }
            }
            return true;
        }));
    }
}
