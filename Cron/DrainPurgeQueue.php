<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\CloudflareCache\Cron;

use Psr\Log\LoggerInterface;
use StackNuts\CloudflareCache\Model\Config;
use StackNuts\CloudflareCache\Model\PurgeCache;
use StackNuts\CloudflareCache\Model\ResourceModel\PurgeQueue;

/**
 * Drains the delayed-purge queue. How often this actually fires is controlled entirely by
 * cron's own schedule (see etc/crontab.xml, driven by the admin "Queue Run Frequency" field
 * via Model\System\Config\Backend\QueueFrequency) - there's no in-code elapsed-time check here,
 * so every firing is a legitimate scheduled drain and this job stays a simple "is there
 * anything to do" check.
 */
class DrainPurgeQueue
{
    /**
     * A tag is retried once after a failed purge, then given up on - so a single
     * persistently-failing tag or a Cloudflare outage can't wedge the queue open forever.
     */
    private const MAX_ATTEMPTS = 2;

    public function __construct(
        private readonly Config $config,
        private readonly PurgeCache $purgeCache,
        private readonly PurgeQueue $purgeQueue,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isActive() || !$this->config->isTagPurgeMode()) {
            return;
        }

        $this->purgeQueue->recordRun();

        $maxId = $this->purgeQueue->getMaxId();
        if ($maxId === 0) {
            return;
        }

        $tags = $this->purgeQueue->getPendingTags($maxId);
        if (!$tags) {
            return;
        }

        if ($this->purgeCache->purgeByTags($tags)) {
            $this->purgeQueue->deleteUpTo($maxId);
            return;
        }

        $this->logger->warning(
            'Delayed Cloudflare purge failed; will retry on the next drain until '
            . self::MAX_ATTEMPTS . ' attempts have been made.',
            ['tags' => $tags]
        );
        $this->purgeQueue->incrementAttempts($maxId);
        $this->purgeQueue->deleteExhausted($maxId, self::MAX_ATTEMPTS);
    }
}
