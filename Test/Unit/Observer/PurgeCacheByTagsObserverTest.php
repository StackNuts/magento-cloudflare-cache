<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\CloudflareCache\Test\Unit\Observer;

use Magento\Framework\App\Cache\Tag\Resolver;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;
use StackNuts\CloudflareCache\Model\Config;
use StackNuts\CloudflareCache\Model\PurgeCache;
use StackNuts\CloudflareCache\Model\ResourceModel\PurgeQueue;
use StackNuts\CloudflareCache\Observer\PurgeCacheByTagsObserver;

class PurgeCacheByTagsObserverTest extends TestCase
{
    private function buildConfig(bool $active, bool $tagPurgeMode, bool $queueEnabled, array $excludedPatterns = []): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('isActive')->willReturn($active);
        $config->method('isTagPurgeMode')->willReturn($tagPurgeMode);
        $config->method('isQueueEnabled')->willReturn($queueEnabled);
        $config->method('getExcludedTagPatterns')->willReturn($excludedPatterns);

        return $config;
    }

    public function testDoesNothingWhenCloudflareNotActive(): void
    {
        $config = $this->buildConfig(active: false, tagPurgeMode: true, queueEnabled: false);

        $purgeCache = $this->createMock(PurgeCache::class);
        $purgeCache->expects($this->never())->method('purgeByTags');

        $purgeQueue = $this->createMock(PurgeQueue::class);
        $purgeQueue->expects($this->never())->method('enqueue');

        $observer = new PurgeCacheByTagsObserver($config, $purgeCache, $this->createStub(Resolver::class), $purgeQueue);
        $observer->execute($this->buildObserver(new \stdClass()));
    }

    public function testPurgesImmediatelyWhenQueueDisabled(): void
    {
        $config = $this->buildConfig(active: true, tagPurgeMode: true, queueEnabled: false);

        $object = new \stdClass();

        $tagResolver = $this->createMock(Resolver::class);
        $tagResolver->expects($this->once())->method('getTags')->with($object)->willReturn(['cat_p_1']);

        $purgeCache = $this->createMock(PurgeCache::class);
        $purgeCache->expects($this->once())->method('purgeByTags')->with(['cat_p_1']);

        $purgeQueue = $this->createMock(PurgeQueue::class);
        $purgeQueue->expects($this->never())->method('enqueue');

        $observer = new PurgeCacheByTagsObserver($config, $purgeCache, $tagResolver, $purgeQueue);
        $observer->execute($this->buildObserver($object));
    }

    public function testEnqueuesInsteadOfPurgingWhenQueueEnabled(): void
    {
        $config = $this->buildConfig(active: true, tagPurgeMode: true, queueEnabled: true);

        $object = new \stdClass();

        $tagResolver = $this->createMock(Resolver::class);
        $tagResolver->expects($this->once())->method('getTags')->with($object)->willReturn(['cat_p_1']);

        $purgeCache = $this->createMock(PurgeCache::class);
        $purgeCache->expects($this->never())->method('purgeByTags');

        $purgeQueue = $this->createMock(PurgeQueue::class);
        $purgeQueue->expects($this->once())->method('enqueue')->with(['cat_p_1']);

        $observer = new PurgeCacheByTagsObserver($config, $purgeCache, $tagResolver, $purgeQueue);
        $observer->execute($this->buildObserver($object));
    }

    public function testPurgesImmediatelyInFullFlushOnlyModeEvenWithQueueEnabled(): void
    {
        // A stale queue_enabled=1 left over from before switching to "full flush only" must not
        // enqueue rows the cron (gated on isTagPurgeMode()) would then never drain.
        $config = $this->buildConfig(active: true, tagPurgeMode: false, queueEnabled: true);

        $object = new \stdClass();

        $tagResolver = $this->createMock(Resolver::class);
        $tagResolver->expects($this->once())->method('getTags')->with($object)->willReturn(['cat_p_1']);

        $purgeCache = $this->createMock(PurgeCache::class);
        $purgeCache->expects($this->once())->method('purgeByTags')->with(['cat_p_1']);

        $purgeQueue = $this->createMock(PurgeQueue::class);
        $purgeQueue->expects($this->never())->method('enqueue');

        $observer = new PurgeCacheByTagsObserver($config, $purgeCache, $tagResolver, $purgeQueue);
        $observer->execute($this->buildObserver($object));
    }

    public function testSkipsWhenEventObjectIsNotAnObject(): void
    {
        $config = $this->buildConfig(active: true, tagPurgeMode: true, queueEnabled: false);

        $purgeCache = $this->createMock(PurgeCache::class);
        $purgeCache->expects($this->never())->method('purgeByTags');

        $purgeQueue = $this->createMock(PurgeQueue::class);
        $purgeQueue->expects($this->never())->method('enqueue');

        $observerModel = new Observer(['event' => new Event(['object' => null])]);

        $observer = new PurgeCacheByTagsObserver($config, $purgeCache, $this->createStub(Resolver::class), $purgeQueue);
        $observer->execute($observerModel);
    }

    public function testFiltersOutTagsMatchingWildcardExclusionPattern(): void
    {
        $config = $this->buildConfig(active: true, tagPurgeMode: true, queueEnabled: false, excludedPatterns: ['gql_*']);

        $object = new \stdClass();

        $tagResolver = $this->createMock(Resolver::class);
        $tagResolver->expects($this->once())->method('getTags')->with($object)
            ->willReturn(['cat_p_1', 'gql_store_config_1']);

        $purgeCache = $this->createMock(PurgeCache::class);
        $purgeCache->expects($this->once())->method('purgeByTags')->with(['cat_p_1']);

        $observer = new PurgeCacheByTagsObserver($config, $purgeCache, $tagResolver, $this->createStub(PurgeQueue::class));
        $observer->execute($this->buildObserver($object));
    }

    public function testFiltersOutTagsMatchingExactExclusionPattern(): void
    {
        $config = $this->buildConfig(active: true, tagPurgeMode: true, queueEnabled: false, excludedPatterns: ['noindex_1']);

        $object = new \stdClass();

        $tagResolver = $this->createMock(Resolver::class);
        $tagResolver->expects($this->once())->method('getTags')->with($object)
            ->willReturn(['cat_p_1', 'noindex_1', 'noindex_12']);

        $purgeCache = $this->createMock(PurgeCache::class);
        // "noindex_1" is an exact match and gets dropped; "noindex_12" does not match exactly and survives.
        $purgeCache->expects($this->once())->method('purgeByTags')->with(['cat_p_1', 'noindex_12']);

        $observer = new PurgeCacheByTagsObserver($config, $purgeCache, $tagResolver, $this->createStub(PurgeQueue::class));
        $observer->execute($this->buildObserver($object));
    }

    public function testDoesNothingWhenAllResolvedTagsAreExcluded(): void
    {
        $config = $this->buildConfig(active: true, tagPurgeMode: true, queueEnabled: false, excludedPatterns: ['gql_*']);

        $object = new \stdClass();

        $tagResolver = $this->createMock(Resolver::class);
        $tagResolver->expects($this->once())->method('getTags')->with($object)
            ->willReturn(['gql_store_config_1']);

        $purgeCache = $this->createMock(PurgeCache::class);
        $purgeCache->expects($this->never())->method('purgeByTags');

        $purgeQueue = $this->createMock(PurgeQueue::class);
        $purgeQueue->expects($this->never())->method('enqueue');

        $observer = new PurgeCacheByTagsObserver($config, $purgeCache, $tagResolver, $purgeQueue);
        $observer->execute($this->buildObserver($object));
    }

    public function testExclusionFilterAlsoAppliesWhenQueueEnabled(): void
    {
        $config = $this->buildConfig(active: true, tagPurgeMode: true, queueEnabled: true, excludedPatterns: ['gql_*']);

        $object = new \stdClass();

        $tagResolver = $this->createMock(Resolver::class);
        $tagResolver->expects($this->once())->method('getTags')->with($object)
            ->willReturn(['cat_p_1', 'gql_store_config_1']);

        $purgeQueue = $this->createMock(PurgeQueue::class);
        $purgeQueue->expects($this->once())->method('enqueue')->with(['cat_p_1']);

        $observer = new PurgeCacheByTagsObserver($config, $this->createStub(PurgeCache::class), $tagResolver, $purgeQueue);
        $observer->execute($this->buildObserver($object));
    }

    private function buildObserver(object $eventObject): Observer
    {
        return new Observer(['event' => new Event(['object' => $eventObject])]);
    }
}
