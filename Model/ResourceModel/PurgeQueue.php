<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\CloudflareCache\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\FlagManager;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Durable queue of Cloudflare cache tags awaiting a delayed/batched purge.
 *
 * Deliberately not an AbstractDb model - this is an append-only event log,
 * not an entity with a lifecycle, mirroring Magento\Framework\Mview\View\Changelog.
 * Every write is an independent INSERT (no read-modify-write), so concurrent
 * requests enqueueing tags at the same time can never race or drop each
 * other's data. Reads/updates/deletes are always bound by an explicit
 * queue_id snapshot (see getMaxId()), so a row inserted mid-drain is simply
 * excluded from that drain and picked up cleanly by the next one.
 */
class PurgeQueue
{
    private const TABLE = 'stacknuts_cloudflarecache_purge_queue';

    /**
     * Backed by the core flag table (present in every Magento install), not this module's
     * own table - this tracks when the cron job itself last fired, for admin observability,
     * independent of whether the queue actually had anything to drain at the time.
     */
    private const FLAG_LAST_RUN_AT = 'stacknuts_cloudflarecache_queue_last_run_at';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly FlagManager $flagManager,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * @param string[] $tags
     */
    public function enqueue(array $tags): void
    {
        $tags = array_values(array_unique(array_filter($tags)));
        if (!$tags) {
            return;
        }

        $connection = $this->resource->getConnection();
        $connection->insertArray(
            $this->resource->getTableName(self::TABLE),
            ['tag'],
            array_map(static fn (string $tag): array => [$tag], $tags)
        );
    }

    /**
     * Current high-water mark, used as the snapshot bound for a drain. 0 when the queue is empty.
     */
    public function getMaxId(): int
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()->from($this->resource->getTableName(self::TABLE), ['max_id' => 'MAX(queue_id)']);

        return (int)$connection->fetchOne($select);
    }

    /**
     * @return string[]
     */
    public function getPendingTags(int $maxId): array
    {
        if ($maxId <= 0) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(self::TABLE), ['tag'])
            ->where('queue_id <= ?', $maxId)
            ->distinct();

        return $connection->fetchCol($select);
    }

    /**
     * Deletes exactly the rows this drain already processed (successfully). Never a blanket delete.
     */
    public function deleteUpTo(int $maxId): void
    {
        if ($maxId <= 0) {
            return;
        }

        $connection = $this->resource->getConnection();
        $connection->delete($this->resource->getTableName(self::TABLE), ['queue_id <= ?' => $maxId]);
    }

    /**
     * Records a failed purge attempt against every row in this drain's snapshot.
     */
    public function incrementAttempts(int $maxId): void
    {
        if ($maxId <= 0) {
            return;
        }

        $connection = $this->resource->getConnection();
        $connection->update(
            $this->resource->getTableName(self::TABLE),
            ['attempts' => new \Zend_Db_Expr('attempts + 1')],
            ['queue_id <= ?' => $maxId]
        );
    }

    /**
     * Gives up on rows that have now failed $maxAttempts times, so one persistently-failing
     * tag (or a Cloudflare outage) can't wedge the queue open indefinitely.
     */
    public function deleteExhausted(int $maxId, int $maxAttempts): void
    {
        if ($maxId <= 0) {
            return;
        }

        $connection = $this->resource->getConnection();
        $connection->delete(
            $this->resource->getTableName(self::TABLE),
            ['queue_id <= ?' => $maxId, 'attempts >= ?' => $maxAttempts]
        );
    }

    public function getPendingCount(): int
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()->from($this->resource->getTableName(self::TABLE), ['count' => 'COUNT(*)']);

        return (int)$connection->fetchOne($select);
    }

    public function getOldestPendingAgeInSeconds(): ?int
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(self::TABLE), ['created_at'])
            ->order('queue_id ASC')
            ->limit(1);

        $oldest = $connection->fetchOne($select);
        if (!$oldest) {
            return null;
        }

        return max(0, (new \DateTime('now', new \DateTimeZone('UTC')))->getTimestamp() - (new \DateTime($oldest))->getTimestamp());
    }

    /**
     * Marks that the drain cron actually fired just now - called whether or not there was
     * anything to drain, so this stays a genuine "is cron running at all" signal rather than
     * being conflated with "is the queue empty" (an empty queue is a good state, not a problem).
     */
    public function recordRun(): void
    {
        $this->flagManager->saveFlag(self::FLAG_LAST_RUN_AT, (string)$this->dateTime->gmtTimestamp());
    }

    public function getLastRunAt(): ?int
    {
        $value = $this->flagManager->getFlagData(self::FLAG_LAST_RUN_AT);

        return $value !== null ? (int)$value : null;
    }

    public function isBacklogged(int $threshold): bool
    {
        return $this->getPendingCount() > $threshold;
    }

    /**
     * Whether the drain cron looks like it isn't actually running - either it's never run
     * while tags are pending, or it's missed roughly 3 expected cycles. Shared by the admin
     * grid's status section and the QueueBacklog system message so both use one definition.
     */
    public function isCronStale(int $frequencyMinutes): bool
    {
        $lastRunAt = $this->getLastRunAt();
        if ($lastRunAt === null) {
            return $this->getPendingCount() > 0;
        }

        return ($this->dateTime->gmtTimestamp() - $lastRunAt) > ($frequencyMinutes * 60 * 3);
    }
}
