<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\CloudflareCache\Controller\Adminhtml\System\Config;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use StackNuts\CloudflareCache\Model\Config;
use StackNuts\CloudflareCache\Model\PurgeCache;

/**
 * Verifies the configured Zone ID/API token by purging just this store's
 * own hostname - a real, low-blast-radius exercise of the same purge
 * endpoint the module uses in production, rather than a credential-only
 * check that could pass while the actual purge call fails.
 */
class TestConnection extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magento_Config::config';

    public function __construct(
        Action\Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly Config $config,
        private readonly PurgeCache $purgeCache,
        private readonly StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $result = $this->jsonFactory->create();
        $storeId = $this->resolveStoreId();

        if (!$this->config->getZoneId($storeId) || !$this->config->getApiToken($storeId)) {
            return $result->setData([
                'success' => false,
                'message' => __('Enter and save both the Zone ID and API Token first, then test.')->render(),
            ]);
        }

        try {
            $hostname = parse_url(
                $this->storeManager->getStore($storeId)->getBaseUrl(UrlInterface::URL_TYPE_LINK),
                PHP_URL_HOST
            );
        } catch (NoSuchEntityException $e) {
            $hostname = null;
        }

        if (!$hostname) {
            return $result->setData([
                'success' => false,
                'message' => __('Could not determine this store\'s hostname from its base URL.')->render(),
            ]);
        }

        if ($this->purgeCache->purgeHost($hostname)) {
            return $result->setData([
                'success' => true,
                'message' => __('Success! Purged Cloudflare\'s cache for "%1".', $hostname)->render(),
            ]);
        }

        return $result->setData([
            'success' => false,
            'message' => __(
                'Cloudflare rejected the request: %1',
                $this->purgeCache->getLastError() ?? __('unknown error, check var/log/stacknuts_cloudflare_cache.log')
            )->render(),
        ]);
    }

    private function resolveStoreId(): ?int
    {
        $storeId = (int)$this->getRequest()->getParam('store', 0);
        if ($storeId) {
            return $storeId;
        }

        $websiteId = (int)$this->getRequest()->getParam('website', 0);
        if ($websiteId) {
            try {
                return (int)$this->storeManager->getWebsite($websiteId)->getDefaultStore()->getId();
            } catch (NoSuchEntityException $e) {
                return null;
            }
        }

        return null;
    }
}
