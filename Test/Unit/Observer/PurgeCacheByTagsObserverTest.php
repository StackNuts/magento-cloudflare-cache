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
use StackNuts\CloudflareCache\Observer\PurgeCacheByTagsObserver;

class PurgeCacheByTagsObserverTest extends TestCase
{
    public function testDoesNothingWhenCloudflareNotActive(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isActive')->willReturn(false);

        $purgeCache = $this->createMock(PurgeCache::class);
        $purgeCache->expects($this->never())->method('purgeByTags');

        $observer = new PurgeCacheByTagsObserver($config, $purgeCache, $this->createStub(Resolver::class));
        $observer->execute($this->buildObserver(new \stdClass()));
    }

    public function testPurgesResolvedTagsWhenActive(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isActive')->willReturn(true);

        $object = new \stdClass();

        $tagResolver = $this->createMock(Resolver::class);
        $tagResolver->expects($this->once())->method('getTags')->with($object)->willReturn(['cat_p_1']);

        $purgeCache = $this->createMock(PurgeCache::class);
        $purgeCache->expects($this->once())->method('purgeByTags')->with(['cat_p_1']);

        $observer = new PurgeCacheByTagsObserver($config, $purgeCache, $tagResolver);
        $observer->execute($this->buildObserver($object));
    }

    public function testSkipsWhenEventObjectIsNotAnObject(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isActive')->willReturn(true);

        $purgeCache = $this->createMock(PurgeCache::class);
        $purgeCache->expects($this->never())->method('purgeByTags');

        $observerModel = new Observer(['event' => new Event(['object' => null])]);

        $observer = new PurgeCacheByTagsObserver($config, $purgeCache, $this->createStub(Resolver::class));
        $observer->execute($observerModel);
    }

    private function buildObserver(object $eventObject): Observer
    {
        return new Observer(['event' => new Event(['object' => $eventObject])]);
    }
}
