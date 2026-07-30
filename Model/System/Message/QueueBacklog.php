<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\CloudflareCache\Model\System\Message;

use Magento\Framework\Notification\MessageInterface;
use Magento\Framework\Phrase;
use StackNuts\CloudflareCache\Model\Config;
use StackNuts\CloudflareCache\Model\ResourceModel\PurgeQueue;

/**
 * Admin-wide banner warning that the delayed purge queue is backlogged or the drain cron
 * doesn't appear to be running. Uses SEVERITY_CRITICAL deliberately - Magento only renders
 * an actual visible banner across page tops for critical messages; lower severities only
 * bump the bell-icon badge count, which wouldn't meet the "global alert" goal here.
 */
class QueueBacklog implements MessageInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly PurgeQueue $purgeQueue
    ) {
    }

    public function getIdentity(): string
    {
        return 'stacknuts_cloudflarecache_queue_backlog';
    }

    public function isDisplayed(): bool
    {
        if (!$this->config->isQueueEnabled()) {
            return false;
        }

        return $this->purgeQueue->isBacklogged($this->config->getQueueBacklogThreshold())
            || $this->purgeQueue->isCronStale($this->config->getQueueFrequencyMinutes());
    }

    public function getText(): Phrase
    {
        $pending = $this->purgeQueue->getPendingCount();

        if ($this->purgeQueue->isCronStale($this->config->getQueueFrequencyMinutes())) {
            return __(
                'Cloudflare Cache: the delayed purge queue\'s drain cron doesn\'t appear to be running '
                . '(%1 tag(s) pending). Check that bin/magento cron:run is being invoked - '
                . 'see Cloudflare Cache > Purge Queue in the admin menu.',
                $pending
            );
        }

        return __(
            'Cloudflare Cache: the delayed purge queue is backlogged (%1 tag(s) pending, above the configured '
            . 'threshold). See Cloudflare Cache > Purge Queue in the admin menu.',
            $pending
        );
    }

    public function getSeverity(): int
    {
        return MessageInterface::SEVERITY_CRITICAL;
    }
}
