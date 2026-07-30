<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\CloudflareCache\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use StackNuts\CloudflareCache\Model\Config;
use StackNuts\CloudflareCache\Model\ResourceModel\PurgeQueue;

/**
 * Read-only overview of the delayed purge queue's health, shown inline at the top of the
 * Cloudflare Configuration section - not a dedicated admin page/grid, deliberately, since a
 * simple summary here is enough to know something needs attention (the QueueBacklog system
 * message banner is what actually surfaces that across the rest of the admin).
 */
class QueueStatus extends Field
{
    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        private readonly Config $config,
        private readonly PurgeQueue $purgeQueue,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _prepareLayout()
    {
        parent::_prepareLayout();
        $this->setTemplate('StackNuts_CloudflareCache::system/config/queuestatus.phtml');
        return $this;
    }

    public function render(AbstractElement $element)
    {
        $element = clone $element;
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        return $this->_toHtml();
    }

    public function isQueueEnabled(): bool
    {
        return $this->config->isQueueEnabled();
    }

    public function getPendingCount(): int
    {
        return $this->purgeQueue->getPendingCount();
    }

    public function getFrequencyMinutes(): int
    {
        return $this->config->getQueueFrequencyMinutes();
    }

    public function isBacklogged(): bool
    {
        return $this->purgeQueue->isBacklogged($this->config->getQueueBacklogThreshold());
    }

    public function isCronStale(): bool
    {
        return $this->isQueueEnabled() && $this->purgeQueue->isCronStale($this->config->getQueueFrequencyMinutes());
    }

    public function getLastRunLabel(): string
    {
        $lastRunAt = $this->purgeQueue->getLastRunAt();
        if ($lastRunAt === null) {
            return __('Never')->render();
        }

        $seconds = max(0, time() - $lastRunAt);
        return match (true) {
            $seconds < 60 => __('%1 second(s) ago', $seconds)->render(),
            $seconds < 3600 => __('%1 minute(s) ago', (int)floor($seconds / 60))->render(),
            $seconds < 86400 => __('%1 hour(s) ago', (int)floor($seconds / 3600))->render(),
            default => __('%1 day(s) ago', (int)floor($seconds / 86400))->render(),
        };
    }
}
