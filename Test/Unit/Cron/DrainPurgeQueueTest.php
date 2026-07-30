<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\CloudflareCache\Test\Unit\Cron;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use StackNuts\CloudflareCache\Cron\DrainPurgeQueue;
use StackNuts\CloudflareCache\Model\Config;
use StackNuts\CloudflareCache\Model\PurgeCache;
use StackNuts\CloudflareCache\Model\ResourceModel\PurgeQueue;

class DrainPurgeQueueTest extends TestCase
{
    private function buildConfig(bool $active = true, bool $tagPurgeMode = true): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('isActive')->willReturn($active);
        $config->method('isTagPurgeMode')->willReturn($tagPurgeMode);

        return $config;
    }

    private function buildJob(Config $config, PurgeCache $purgeCache, PurgeQueue $purgeQueue): DrainPurgeQueue
    {
        return new DrainPurgeQueue($config, $purgeCache, $purgeQueue, $this->createStub(LoggerInterface::class));
    }

    public function testNoOpWhenCloudflareNotActive(): void
    {
        $purgeQueue = $this->createMock(PurgeQueue::class);
        $purgeQueue->expects($this->never())->method('recordRun');
        $purgeQueue->expects($this->never())->method('getMaxId');

        $job = $this->buildJob($this->buildConfig(active: false), $this->createStub(PurgeCache::class), $purgeQueue);
        $job->execute();
    }

    public function testNoOpWhenFullFlushOnlyMode(): void
    {
        $purgeQueue = $this->createMock(PurgeQueue::class);
        $purgeQueue->expects($this->never())->method('recordRun');
        $purgeQueue->expects($this->never())->method('getMaxId');

        $job = $this->buildJob($this->buildConfig(tagPurgeMode: false), $this->createStub(PurgeCache::class), $purgeQueue);
        $job->execute();
    }

    public function testRecordsRunEvenWhenQueueEmpty(): void
    {
        // "last ran" must reflect that cron itself fired, independent of whether there was
        // anything to drain - otherwise a healthy, empty queue would look like stalled cron.
        $purgeQueue = $this->createMock(PurgeQueue::class);
        $purgeQueue->expects($this->once())->method('recordRun');
        $purgeQueue->method('getMaxId')->willReturn(0);
        $purgeQueue->expects($this->never())->method('getPendingTags');

        $purgeCache = $this->createMock(PurgeCache::class);
        $purgeCache->expects($this->never())->method('purgeByTags');

        $job = $this->buildJob($this->buildConfig(), $purgeCache, $purgeQueue);
        $job->execute();
    }

    public function testDrainsAndRecordsRunWhenQueueHasTags(): void
    {
        $purgeQueue = $this->createMock(PurgeQueue::class);
        $purgeQueue->expects($this->once())->method('recordRun');
        $purgeQueue->method('getMaxId')->willReturn(5);
        $purgeQueue->method('getPendingTags')->with(5)->willReturn(['cat_p_1', 'cat_p_2']);
        $purgeQueue->expects($this->once())->method('deleteUpTo')->with(5);
        $purgeQueue->expects($this->never())->method('incrementAttempts');

        $purgeCache = $this->createMock(PurgeCache::class);
        $purgeCache->expects($this->once())->method('purgeByTags')->with(['cat_p_1', 'cat_p_2'])->willReturn(true);

        $job = $this->buildJob($this->buildConfig(), $purgeCache, $purgeQueue);
        $job->execute();
    }

    public function testRetriesOnFailureWithoutDeletingBelowMaxAttempts(): void
    {
        $purgeQueue = $this->createMock(PurgeQueue::class);
        $purgeQueue->expects($this->once())->method('recordRun');
        $purgeQueue->method('getMaxId')->willReturn(5);
        $purgeQueue->method('getPendingTags')->with(5)->willReturn(['cat_p_1']);
        $purgeQueue->expects($this->never())->method('deleteUpTo');
        $purgeQueue->expects($this->once())->method('incrementAttempts')->with(5);
        $purgeQueue->expects($this->once())->method('deleteExhausted')->with(5, 2);

        $purgeCache = $this->createStub(PurgeCache::class);
        $purgeCache->method('purgeByTags')->willReturn(false);

        $job = $this->buildJob($this->buildConfig(), $purgeCache, $purgeQueue);
        $job->execute();
    }
}
