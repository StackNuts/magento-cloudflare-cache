<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\CloudflareCache\Test\Unit\Observer;

use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;
use StackNuts\CloudflareCache\Model\Config;
use StackNuts\CloudflareCache\Model\PurgeCache;
use StackNuts\CloudflareCache\Observer\FlushAllCacheObserver;

class FlushAllCacheObserverTest extends TestCase
{
    public function testPurgesAllWhenCloudflareActive(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isActive')->willReturn(true);

        $purgeCache = $this->createMock(PurgeCache::class);
        $purgeCache->expects($this->once())->method('purgeAll');

        $observer = new FlushAllCacheObserver($config, $purgeCache);
        $observer->execute($this->createStub(Observer::class));
    }

    public function testDoesNothingWhenCloudflareNotActive(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isActive')->willReturn(false);

        $purgeCache = $this->createMock(PurgeCache::class);
        $purgeCache->expects($this->never())->method('purgeAll');

        $observer = new FlushAllCacheObserver($config, $purgeCache);
        $observer->execute($this->createStub(Observer::class));
    }
}
