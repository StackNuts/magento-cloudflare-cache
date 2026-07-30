<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\CloudflareCache\Test\Unit\Console\Command;

use Magento\Framework\App\State;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use StackNuts\CloudflareCache\Console\Command\HealthcheckCommand;
use StackNuts\CloudflareCache\Model\Config;
use StackNuts\CloudflareCache\Model\PurgeCache;
use StackNuts\CloudflareCache\Model\ResourceModel\PurgeQueue;
use Symfony\Component\Console\Tester\CommandTester;

class HealthcheckCommandTest extends TestCase
{
    private function buildConfig(
        bool $active = true,
        ?string $zoneId = 'zone-1',
        ?string $apiToken = 'token-1',
        bool $queueEnabled = false
    ): Config {
        $config = $this->createStub(Config::class);
        $config->method('isActive')->willReturn($active);
        $config->method('getZoneId')->willReturn($zoneId);
        $config->method('getApiToken')->willReturn($apiToken);
        $config->method('isQueueEnabled')->willReturn($queueEnabled);

        return $config;
    }

    /**
     * The table's Detail column wraps long content across multiple lines (see
     * HealthcheckCommand's setColumnMaxWidth calls), and each wrapped line is still its own
     * bordered table row - so a substring spanning a wrap point has a literal "|" cell border
     * sitting inside it, not just a newline. Strip borders and collapse whitespace so
     * assertions only depend on the words, not wherever Symfony's word-wrap happens to break.
     */
    private function normalizeDisplay(CommandTester $tester): string
    {
        return preg_replace('/\s+/', ' ', str_replace('|', ' ', $tester->getDisplay()));
    }

    private function buildStoreManager(): StoreManagerInterface
    {
        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturn('https://magento.philr.org.uk/');

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return $storeManager;
    }

    /**
     * Always builds the no-op-wait anonymous subclass (see below) rather than the real
     * HealthcheckCommand, so no test can ever accidentally sleep for real seconds regardless
     * of which retry path it happens to exercise.
     */
    private function buildCommand(
        Config $config,
        Curl $curl,
        ?PurgeCache $purgeCache = null,
        ?StoreManagerInterface $storeManager = null,
        ?PurgeQueue $purgeQueue = null
    ): HealthcheckCommand {
        return new class(
            $config,
            $purgeCache ?? $this->createStub(PurgeCache::class),
            $storeManager ?? $this->buildStoreManager(),
            $curl,
            $this->createStub(State::class),
            $purgeQueue ?? $this->createStub(PurgeQueue::class)
        ) extends HealthcheckCommand {
            protected function wait(int $seconds): void
            {
                // no-op: skip the real sleep() between propagation-check retries in tests
            }
        };
    }

    public function testFailsFastWhenCloudflareNotActive(): void
    {
        $command = $this->buildCommand($this->buildConfig(active: false), $this->createStub(Curl::class));
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('not the active Full Page Cache type', $tester->getDisplay());
    }

    public function testFailsFastWhenCredentialsMissing(): void
    {
        $command = $this->buildCommand($this->buildConfig(zoneId: null), $this->createStub(Curl::class));
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Zone ID or API Token', $tester->getDisplay());
    }

    public function testAllChecksPassOnHealthyCache(): void
    {
        $curl = $this->createStub(Curl::class);
        $curl->method('getStatus')->willReturn(200);
        $curl->method('getHeaders')->willReturnOnConsecutiveCalls(
            ['cf-cache-status' => 'MISS', 'Cache-Control' => 'public, max-age=86400, s-maxage=86400'],
            ['cf-cache-status' => 'HIT', 'Cache-Control' => 'public, max-age=86400, s-maxage=86400']
        );

        $purgeCache = $this->createMock(PurgeCache::class);
        $purgeCache->expects($this->once())->method('purgeHost')->with('magento.philr.org.uk');

        $command = $this->buildCommand($this->buildConfig(), $curl, $purgeCache);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('All checks passed', $tester->getDisplay());
    }

    public function testFailsWhenSetCookiePresentOnCacheableResponse(): void
    {
        $curl = $this->createStub(Curl::class);
        $curl->method('getStatus')->willReturn(200);
        $curl->method('getHeaders')->willReturn([
            'cf-cache-status' => 'HIT',
            'Cache-Control' => 'public, max-age=86400, s-maxage=86400',
            'Set-Cookie' => ['PHPSESSID=abc123'],
        ]);

        $command = $this->buildCommand($this->buildConfig(), $curl);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--skip-purge' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Set-Cookie header reached the client', $this->normalizeDisplay($tester));
    }

    public function testFailsWhenNeverProxiedThroughCloudflare(): void
    {
        $curl = $this->createStub(Curl::class);
        $curl->method('getStatus')->willReturn(200);
        $curl->method('getHeaders')->willReturn(['Cache-Control' => 'public, max-age=86400, s-maxage=86400']);

        $command = $this->buildCommand($this->buildConfig(), $curl);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--skip-purge' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('never reached Cloudflare', $this->normalizeDisplay($tester));
    }

    public function testShowsPendingQueueStatusWhenQueueEnabled(): void
    {
        $curl = $this->createStub(Curl::class);
        $curl->method('getStatus')->willReturn(200);
        $curl->method('getHeaders')->willReturnOnConsecutiveCalls(
            ['cf-cache-status' => 'MISS', 'Cache-Control' => 'public, max-age=86400, s-maxage=86400'],
            ['cf-cache-status' => 'HIT', 'Cache-Control' => 'public, max-age=86400, s-maxage=86400']
        );

        $purgeQueue = $this->createStub(PurgeQueue::class);
        $purgeQueue->method('getPendingCount')->willReturn(3);
        $purgeQueue->method('getOldestPendingAgeInSeconds')->willReturn(42);
        $purgeQueue->method('getLastRunAt')->willReturn(null);

        $command = $this->buildCommand(
            $this->buildConfig(queueEnabled: true),
            $curl,
            $this->createStub(PurgeCache::class),
            null,
            $purgeQueue
        );
        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertStringContainsString('Delayed purge queue: 3 tag(s) pending (oldest queued 42s ago). Cron last ran: never.', $tester->getDisplay());
    }

    public function testOmitsQueueStatusLineWhenQueueDisabled(): void
    {
        $curl = $this->createStub(Curl::class);
        $curl->method('getStatus')->willReturn(200);
        $curl->method('getHeaders')->willReturnOnConsecutiveCalls(
            ['cf-cache-status' => 'MISS', 'Cache-Control' => 'public, max-age=86400, s-maxage=86400'],
            ['cf-cache-status' => 'HIT', 'Cache-Control' => 'public, max-age=86400, s-maxage=86400']
        );

        $purgeQueue = $this->createMock(PurgeQueue::class);
        $purgeQueue->expects($this->never())->method('getPendingCount');

        $command = $this->buildCommand(
            $this->buildConfig(queueEnabled: false),
            $curl,
            $this->createStub(PurgeCache::class),
            null,
            $purgeQueue
        );
        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertStringNotContainsString('Delayed purge queue', $tester->getDisplay());
    }

    public function testDelayedQueueCheckFailsWhenNoMagentoTagsHeaderPresent(): void
    {
        $curl = $this->createStub(Curl::class);
        $curl->method('getStatus')->willReturn(200);
        $curl->method('getHeaders')->willReturnOnConsecutiveCalls(
            ['cf-cache-status' => 'MISS', 'Cache-Control' => 'public, max-age=86400, s-maxage=86400'],
            ['cf-cache-status' => 'HIT', 'Cache-Control' => 'public, max-age=86400, s-maxage=86400']
        );

        $purgeQueue = $this->createMock(PurgeQueue::class);
        $purgeQueue->expects($this->never())->method('enqueue');

        $command = $this->buildCommand(
            $this->buildConfig(queueEnabled: true),
            $curl,
            $this->createStub(PurgeCache::class),
            null,
            $purgeQueue
        );
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('cannot exercise the queue without a real cache tag', $this->normalizeDisplay($tester));
    }

    public function testDelayedQueueCheckFailsWhenTagsDoNotReachQueue(): void
    {
        $curl = $this->createStub(Curl::class);
        $curl->method('getStatus')->willReturn(200);
        $curl->method('getHeaders')->willReturnOnConsecutiveCalls(
            ['cf-cache-status' => 'MISS', 'Cache-Control' => 'public, max-age=86400, s-maxage=86400', 'X-Magento-Tags' => 'cat_p_1'],
            ['cf-cache-status' => 'HIT', 'Cache-Control' => 'public, max-age=86400, s-maxage=86400', 'X-Magento-Tags' => 'cat_p_1']
        );

        $purgeQueue = $this->createMock(PurgeQueue::class);
        $purgeQueue->expects($this->once())->method('enqueue')->with(['cat_p_1']);
        $purgeQueue->method('getMaxId')->willReturn(42);
        $purgeQueue->method('getPendingTags')->with(42)->willReturn([]);

        $purgeCache = $this->createMock(PurgeCache::class);
        $purgeCache->expects($this->never())->method('purgeByTags');

        $command = $this->buildCommand($this->buildConfig(queueEnabled: true), $curl, $purgeCache, null, $purgeQueue);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Enqueued tags were not found in the queue afterward', $this->normalizeDisplay($tester));
    }

    public function testDelayedQueueCheckFailsWhenDrainPurgeFails(): void
    {
        $curl = $this->createStub(Curl::class);
        $curl->method('getStatus')->willReturn(200);
        $curl->method('getHeaders')->willReturnOnConsecutiveCalls(
            ['cf-cache-status' => 'MISS', 'Cache-Control' => 'public, max-age=86400, s-maxage=86400', 'X-Magento-Tags' => 'cat_p_1'],
            ['cf-cache-status' => 'HIT', 'Cache-Control' => 'public, max-age=86400, s-maxage=86400', 'X-Magento-Tags' => 'cat_p_1']
        );

        $purgeQueue = $this->createMock(PurgeQueue::class);
        $purgeQueue->method('getMaxId')->willReturn(42);
        $purgeQueue->method('getPendingTags')->with(42)->willReturn(['cat_p_1']);
        $purgeQueue->expects($this->never())->method('deleteUpTo');

        $purgeCache = $this->createStub(PurgeCache::class);
        $purgeCache->method('purgeByTags')->willReturn(false);
        $purgeCache->method('getLastError')->willReturn('API error');

        $command = $this->buildCommand($this->buildConfig(queueEnabled: true), $curl, $purgeCache, null, $purgeQueue);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Purge failed: API error', $this->normalizeDisplay($tester));
    }

    public function testDelayedQueueCheckFailsWhenPageStillCachedAfterAllRetries(): void
    {
        $curl = $this->createStub(Curl::class);
        $curl->method('getStatus')->willReturn(200);
        // 2 initial fetches (first, second) + 5 propagation-check attempts, all still HIT -
        // proves the check genuinely exhausts all retries before giving up, not just one shot.
        $curl->method('getHeaders')->willReturnOnConsecutiveCalls(
            ['cf-cache-status' => 'MISS', 'Cache-Control' => 'public, max-age=86400, s-maxage=86400', 'X-Magento-Tags' => 'cat_p_1'],
            ['cf-cache-status' => 'HIT', 'Cache-Control' => 'public, max-age=86400, s-maxage=86400', 'X-Magento-Tags' => 'cat_p_1'],
            ['cf-cache-status' => 'HIT', 'Cache-Control' => 'public, max-age=86400, s-maxage=86400'],
            ['cf-cache-status' => 'HIT', 'Cache-Control' => 'public, max-age=86400, s-maxage=86400'],
            ['cf-cache-status' => 'HIT', 'Cache-Control' => 'public, max-age=86400, s-maxage=86400'],
            ['cf-cache-status' => 'HIT', 'Cache-Control' => 'public, max-age=86400, s-maxage=86400'],
            ['cf-cache-status' => 'HIT', 'Cache-Control' => 'public, max-age=86400, s-maxage=86400']
        );

        $purgeQueue = $this->createMock(PurgeQueue::class);
        $purgeQueue->method('getMaxId')->willReturn(42);
        $purgeQueue->method('getPendingTags')->with(42)->willReturnOnConsecutiveCalls(['cat_p_1'], []);
        $purgeQueue->expects($this->once())->method('deleteUpTo')->with(42);

        $purgeCache = $this->createStub(PurgeCache::class);
        $purgeCache->method('purgeByTags')->willReturn(true);

        $command = $this->buildCommand($this->buildConfig(queueEnabled: true), $curl, $purgeCache, null, $purgeQueue);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        // Asserted as two fragments, not one combined phrase: the Check column's own wrapped
        // remainder ("the drain") can land between them on the same table row, since both
        // columns wrap independently - normalizeDisplay() can't fully undo that interleaving.
        $display = $this->normalizeDisplay($tester);
        $this->assertStringContainsString('Still showing cf-cache-status: HIT after 5', $display);
        $this->assertStringContainsString('attempt(s) over ~12s', $display);
    }

    public function testDelayedQueueCheckSucceedsAfterRetryingPropagation(): void
    {
        $curl = $this->createStub(Curl::class);
        $curl->method('getStatus')->willReturn(200);
        // Still HIT for the first two propagation-check attempts, clears on the third -
        // proves the retry loop actually gives a slow-to-propagate purge a chance to succeed.
        $curl->method('getHeaders')->willReturnOnConsecutiveCalls(
            ['cf-cache-status' => 'MISS', 'Cache-Control' => 'public, max-age=86400, s-maxage=86400', 'X-Magento-Tags' => 'cat_p_1'],
            ['cf-cache-status' => 'HIT', 'Cache-Control' => 'public, max-age=86400, s-maxage=86400', 'X-Magento-Tags' => 'cat_p_1'],
            ['cf-cache-status' => 'HIT', 'Cache-Control' => 'public, max-age=86400, s-maxage=86400'],
            ['cf-cache-status' => 'HIT', 'Cache-Control' => 'public, max-age=86400, s-maxage=86400'],
            ['cf-cache-status' => 'MISS', 'Cache-Control' => 'public, max-age=86400, s-maxage=86400']
        );

        $purgeQueue = $this->createMock(PurgeQueue::class);
        $purgeQueue->method('getMaxId')->willReturn(42);
        $purgeQueue->method('getPendingTags')->with(42)->willReturnOnConsecutiveCalls(['cat_p_1'], []);
        $purgeQueue->expects($this->once())->method('deleteUpTo')->with(42);

        $purgeCache = $this->createStub(PurgeCache::class);
        $purgeCache->method('purgeByTags')->willReturn(true);

        $command = $this->buildCommand($this->buildConfig(queueEnabled: true), $curl, $purgeCache, null, $purgeQueue);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('after 3 attempt(s)', $this->normalizeDisplay($tester));
    }

    public function testDelayedQueueFullFlowSucceeds(): void
    {
        $curl = $this->createStub(Curl::class);
        $curl->method('getStatus')->willReturn(200);
        $curl->method('getHeaders')->willReturnOnConsecutiveCalls(
            ['cf-cache-status' => 'MISS', 'Cache-Control' => 'public, max-age=86400, s-maxage=86400', 'X-Magento-Tags' => 'cat_p_1,cat_p_2'],
            ['cf-cache-status' => 'HIT', 'Cache-Control' => 'public, max-age=86400, s-maxage=86400', 'X-Magento-Tags' => 'cat_p_1,cat_p_2'],
            ['cf-cache-status' => 'MISS', 'Cache-Control' => 'public, max-age=86400, s-maxage=86400']
        );

        $purgeQueue = $this->createMock(PurgeQueue::class);
        $purgeQueue->expects($this->once())->method('enqueue')->with(['cat_p_1', 'cat_p_2']);
        $purgeQueue->method('getMaxId')->willReturn(42);
        $purgeQueue->method('getPendingTags')->with(42)->willReturnOnConsecutiveCalls(['cat_p_1', 'cat_p_2'], []);
        $purgeQueue->expects($this->once())->method('deleteUpTo')->with(42);

        $purgeCache = $this->createMock(PurgeCache::class);
        $purgeCache->expects($this->once())->method('purgeHost')->with('magento.philr.org.uk');
        $purgeCache->expects($this->once())->method('purgeByTags')->with(['cat_p_1', 'cat_p_2'])->willReturn(true);

        $command = $this->buildCommand($this->buildConfig(queueEnabled: true), $curl, $purgeCache, null, $purgeQueue);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('All checks passed', $tester->getDisplay());
    }
}
