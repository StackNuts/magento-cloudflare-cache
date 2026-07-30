<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\CloudflareCache\Test\Integration\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\FlagManager;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;
use StackNuts\CloudflareCache\Model\ResourceModel\PurgeQueue;

/**
 * Covers the resource model's race-safety property (a row inserted mid-drain must survive
 * an in-progress drain's delete) against a real DB - mocking the adapter can't meaningfully
 * verify this, the same reasoning Magento core applies to Mview\View\Changelog's own tests.
 * Also covers recordRun()/getLastRunAt() against the real core flag table, and the
 * isBacklogged()/isCronStale() heuristics built on top of both.
 *
 * @magentoDbIsolation disabled
 */
class PurgeQueueTest extends TestCase
{
    /** Mirrors PurgeQueue's own private FLAG_LAST_RUN_AT, only for test cleanup between runs. */
    private const FLAG_LAST_RUN_AT = 'stacknuts_cloudflarecache_queue_last_run_at';

    private PurgeQueue $purgeQueue;
    private ResourceConnection $resource;
    private FlagManager $flagManager;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->resource = $objectManager->get(ResourceConnection::class);
        $this->flagManager = $objectManager->get(FlagManager::class);
        $this->purgeQueue = $objectManager->create(PurgeQueue::class);

        $this->truncateQueue();
        $this->flagManager->deleteFlag(self::FLAG_LAST_RUN_AT);
    }

    protected function tearDown(): void
    {
        $this->truncateQueue();
        $this->flagManager->deleteFlag(self::FLAG_LAST_RUN_AT);
    }

    public function testEnqueueInsertsOneRowPerTag(): void
    {
        $this->purgeQueue->enqueue(['cat_p_1', 'cat_p_2']);

        $this->assertSame(2, $this->purgeQueue->getPendingCount());
    }

    public function testEnqueueIsNoOpForEmptyArray(): void
    {
        $this->purgeQueue->enqueue([]);

        $this->assertSame(0, $this->purgeQueue->getPendingCount());
    }

    public function testGetMaxIdReturnsZeroWhenEmpty(): void
    {
        $this->assertSame(0, $this->purgeQueue->getMaxId());
    }

    public function testGetPendingTagsRespectsSnapshotBoundAndDedupes(): void
    {
        $this->purgeQueue->enqueue(['cat_p_1', 'cat_p_1', 'cat_p_2']);
        $maxId = $this->purgeQueue->getMaxId();

        $this->assertGreaterThan(0, $maxId);
        $this->assertEqualsCanonicalizing(['cat_p_1', 'cat_p_2'], $this->purgeQueue->getPendingTags($maxId));
    }

    public function testDeleteUpToLeavesRowsInsertedAfterTheSnapshotUntouched(): void
    {
        $this->purgeQueue->enqueue(['cat_p_1']);
        $maxId = $this->purgeQueue->getMaxId();

        // Simulates a row arriving concurrently, after the drain already took its snapshot.
        $this->purgeQueue->enqueue(['cat_p_2']);

        $this->purgeQueue->deleteUpTo($maxId);

        $this->assertSame(['cat_p_2'], $this->purgeQueue->getPendingTags($this->purgeQueue->getMaxId()));
    }

    public function testIncrementAttemptsThenDeleteExhaustedGivesUpAfterMaxAttempts(): void
    {
        $this->purgeQueue->enqueue(['cat_p_1']);
        $maxId = $this->purgeQueue->getMaxId();

        $this->purgeQueue->incrementAttempts($maxId);
        $this->purgeQueue->deleteExhausted($maxId, 2);
        $this->assertSame(1, $this->purgeQueue->getPendingCount(), 'one failed attempt should not be exhausted yet');

        $this->purgeQueue->incrementAttempts($maxId);
        $this->purgeQueue->deleteExhausted($maxId, 2);
        $this->assertSame(0, $this->purgeQueue->getPendingCount(), 'second failure should exhaust and delete the row');
    }

    public function testIncrementAttemptsDoesNotTouchRowsInsertedAfterTheSnapshot(): void
    {
        $this->purgeQueue->enqueue(['cat_p_1']);
        $maxId = $this->purgeQueue->getMaxId();

        $this->purgeQueue->enqueue(['cat_p_2']);
        $this->purgeQueue->incrementAttempts($maxId);
        $this->purgeQueue->deleteExhausted($maxId, 1);

        // cat_p_1 (in the snapshot) is exhausted at maxAttempts=1 and deleted;
        // cat_p_2 (inserted after the snapshot) must survive untouched.
        $this->assertSame(['cat_p_2'], $this->purgeQueue->getPendingTags($this->purgeQueue->getMaxId()));
    }

    public function testGetOldestPendingAgeInSecondsNullWhenEmpty(): void
    {
        $this->assertNull($this->purgeQueue->getOldestPendingAgeInSeconds());
    }

    public function testGetOldestPendingAgeInSecondsNonNegativeWhenPending(): void
    {
        $this->purgeQueue->enqueue(['cat_p_1']);

        $this->assertGreaterThanOrEqual(0, $this->purgeQueue->getOldestPendingAgeInSeconds());
    }

    public function testGetLastRunAtNullWhenNeverRun(): void
    {
        $this->assertNull($this->purgeQueue->getLastRunAt());
    }

    public function testRecordRunAndGetLastRunAtRoundTrip(): void
    {
        $before = time();
        $this->purgeQueue->recordRun();
        $after = time();

        $lastRunAt = $this->purgeQueue->getLastRunAt();

        $this->assertNotNull($lastRunAt);
        $this->assertGreaterThanOrEqual($before, $lastRunAt);
        $this->assertLessThanOrEqual($after, $lastRunAt);
    }

    public function testIsBackloggedRespectsThreshold(): void
    {
        $this->purgeQueue->enqueue(['cat_p_1', 'cat_p_2', 'cat_p_3']);

        $this->assertTrue($this->purgeQueue->isBacklogged(2));
        $this->assertFalse($this->purgeQueue->isBacklogged(3));
    }

    public function testIsCronStaleTrueWhenNeverRunAndTagsPending(): void
    {
        $this->purgeQueue->enqueue(['cat_p_1']);

        $this->assertTrue($this->purgeQueue->isCronStale(5));
    }

    public function testIsCronStaleFalseWhenNeverRunAndQueueEmpty(): void
    {
        $this->assertFalse($this->purgeQueue->isCronStale(5));
    }

    public function testIsCronStaleFalseImmediatelyAfterRecordRun(): void
    {
        $this->purgeQueue->enqueue(['cat_p_1']);
        $this->purgeQueue->recordRun();

        $this->assertFalse($this->purgeQueue->isCronStale(5));
    }

    private function truncateQueue(): void
    {
        $this->resource->getConnection()->delete(
            $this->resource->getTableName('stacknuts_cloudflarecache_purge_queue')
        );
    }
}
