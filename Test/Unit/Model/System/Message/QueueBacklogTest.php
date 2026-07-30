<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\CloudflareCache\Test\Unit\Model\System\Message;

use Magento\Framework\Notification\MessageInterface;
use PHPUnit\Framework\TestCase;
use StackNuts\CloudflareCache\Model\Config;
use StackNuts\CloudflareCache\Model\ResourceModel\PurgeQueue;
use StackNuts\CloudflareCache\Model\System\Message\QueueBacklog;

class QueueBacklogTest extends TestCase
{
    private function buildConfig(bool $queueEnabled, int $threshold = 50, int $frequency = 5): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('isQueueEnabled')->willReturn($queueEnabled);
        $config->method('getQueueBacklogThreshold')->willReturn($threshold);
        $config->method('getQueueFrequencyMinutes')->willReturn($frequency);

        return $config;
    }

    public function testNotDisplayedWhenQueueDisabled(): void
    {
        $purgeQueue = $this->createMock(PurgeQueue::class);
        $purgeQueue->expects($this->never())->method('isBacklogged');
        $purgeQueue->expects($this->never())->method('isCronStale');

        $message = new QueueBacklog($this->buildConfig(queueEnabled: false), $purgeQueue);

        $this->assertFalse($message->isDisplayed());
    }

    public function testNotDisplayedWhenHealthy(): void
    {
        $purgeQueue = $this->createStub(PurgeQueue::class);
        $purgeQueue->method('isBacklogged')->willReturn(false);
        $purgeQueue->method('isCronStale')->willReturn(false);

        $message = new QueueBacklog($this->buildConfig(queueEnabled: true), $purgeQueue);

        $this->assertFalse($message->isDisplayed());
    }

    public function testDisplayedWhenBacklogged(): void
    {
        $purgeQueue = $this->createStub(PurgeQueue::class);
        $purgeQueue->method('isBacklogged')->willReturn(true);
        $purgeQueue->method('isCronStale')->willReturn(false);
        $purgeQueue->method('getPendingCount')->willReturn(120);

        $message = new QueueBacklog($this->buildConfig(queueEnabled: true), $purgeQueue);

        $this->assertTrue($message->isDisplayed());
        $this->assertStringContainsString('backlogged', (string)$message->getText());
        $this->assertStringContainsString('120', (string)$message->getText());
    }

    public function testDisplayedWhenCronStale(): void
    {
        $purgeQueue = $this->createStub(PurgeQueue::class);
        $purgeQueue->method('isBacklogged')->willReturn(false);
        $purgeQueue->method('isCronStale')->willReturn(true);
        $purgeQueue->method('getPendingCount')->willReturn(7);

        $message = new QueueBacklog($this->buildConfig(queueEnabled: true), $purgeQueue);

        $this->assertTrue($message->isDisplayed());
        $this->assertStringContainsString('doesn\'t appear to be running', (string)$message->getText());
    }

    public function testSeverityIsCriticalSoItRendersAsAVisibleBanner(): void
    {
        $message = new QueueBacklog($this->buildConfig(queueEnabled: true), $this->createStub(PurgeQueue::class));

        $this->assertSame(MessageInterface::SEVERITY_CRITICAL, $message->getSeverity());
    }

    public function testIdentityIsStable(): void
    {
        $message = new QueueBacklog($this->buildConfig(queueEnabled: true), $this->createStub(PurgeQueue::class));

        $this->assertSame('stacknuts_cloudflarecache_queue_backlog', $message->getIdentity());
    }
}
