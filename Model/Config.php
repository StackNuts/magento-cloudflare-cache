<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\CloudflareCache\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\PageCache\Model\Config as PageCacheConfig;
use Magento\Store\Model\ScopeInterface;

/**
 * Reads this module's own configuration and determines whether Cloudflare
 * is the currently selected Full Page Cache type.
 */
class Config
{
    /**
     * Value stored under system/full_page_cache/caching_application when
     * Cloudflare is selected. Magento_PageCache itself only defines
     * BUILT_IN (1) and VARNISH (2), so this module claims the next value
     * as its own type identifier, the same way third-party FPC integrations
     * (e.g. Fastly) do, without preferencing core Config.
     */
    public const TYPE_CLOUDFLARE = 3;

    /**
     * Default: targeted purges via Magento's own cache tags, mirrored into
     * Cloudflare's Cache-Tag header. Available on every Cloudflare plan.
     */
    public const PURGE_MODE_TAGS = 'tags';

    /**
     * Only purges Cloudflare on a full/global cache flush (admin "Flush
     * Cache Storage", theme reassignment, media cache clean, etc). Saving a
     * single entity does *not* purge Cloudflare in this mode - either wait
     * for edge TTL to expire the page, or purge it manually via the
     * Cloudflare dashboard/API.
     */
    public const PURGE_MODE_FULL_FLUSH_ONLY = 'full_flush_only';

    private const XML_PATH_ZONE_ID = 'system/full_page_cache/cloudflare/zone_id';
    private const XML_PATH_API_TOKEN = 'system/full_page_cache/cloudflare/api_token';
    private const XML_PATH_PURGE_MODE = 'system/full_page_cache/cloudflare/purge_mode';
    private const XML_PATH_DEBUG_HEADER = 'system/full_page_cache/cloudflare/enable_debug_header';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly PageCacheConfig $pageCacheConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    /**
     * Whether Cloudflare is the selected FPC type and page caching is enabled.
     */
    public function isActive(): bool
    {
        return $this->pageCacheConfig->isEnabled()
            && (int)$this->pageCacheConfig->getType() === self::TYPE_CLOUDFLARE;
    }

    public function getZoneId(?int $storeId = null): ?string
    {
        return $this->scopeConfig->getValue(self::XML_PATH_ZONE_ID, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * The api_token field's backend_model (Magento\Config\Model\Config\Backend\Encrypted)
     * only encrypts on save via the admin form - ScopeConfigInterface returns the raw
     * ciphertext straight from core_config_data, so it must be decrypted here.
     */
    public function getApiToken(?int $storeId = null): ?string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_API_TOKEN, ScopeInterface::SCOPE_STORE, $storeId);

        return $value ? $this->encryptor->decrypt($value) : $value;
    }

    public function getPurgeMode(?int $storeId = null): string
    {
        return (string)($this->scopeConfig->getValue(
            self::XML_PATH_PURGE_MODE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: self::PURGE_MODE_TAGS);
    }

    public function isTagPurgeMode(?int $storeId = null): bool
    {
        return $this->getPurgeMode($storeId) === self::PURGE_MODE_TAGS;
    }

    public function isDebugHeaderEnabled(?int $storeId = null): bool
    {
        return (bool)$this->scopeConfig->isSetFlag(
            self::XML_PATH_DEBUG_HEADER,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }
}
