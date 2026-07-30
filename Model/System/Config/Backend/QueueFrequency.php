<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\CloudflareCache\Model\System\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\App\Config\ValueFactory;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;

/**
 * Converts the plain "every N minutes" admin field into the cron expression
 * etc/crontab.xml's <config_path> for the drain job actually reads, so changing this
 * setting changes the job's real firing frequency without a code deploy. Mirrors
 * Magento\Cron\Model\Config\Backend\Sitemap, minus the run/model path (our crontab.xml
 * entry already hardcodes instance/method, only the schedule needs to be dynamic).
 */
class QueueFrequency extends Value
{
    private const CRON_STRING_PATH = 'crontab/default/jobs/stacknuts_cloudflarecache_drain_purge_queue/schedule/cron_expr';

    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly ValueFactory $configValueFactory,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    public function afterSave()
    {
        $minutes = max(1, (int)$this->getValue());
        $cronExpr = sprintf('*/%d * * * *', $minutes);

        try {
            $this->configValueFactory->create()
                ->load(self::CRON_STRING_PATH, 'path')
                ->setValue($cronExpr)
                ->setPath(self::CRON_STRING_PATH)
                ->save();
        } catch (\Exception $e) {
            throw new LocalizedException(__('We can\'t save the queue frequency as a cron expression.'));
        }

        return parent::afterSave();
    }
}
