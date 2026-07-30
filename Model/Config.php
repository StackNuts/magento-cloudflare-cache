<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\CloudflareCache\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\PageCache\Model\Config as PageCacheConfig;
use Magento\Store\Model\ScopeInterface;
use Monolog\Logger as MonologLogger;

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
    private const XML_PATH_QUEUE_ENABLED = 'system/full_page_cache/cloudflare/queue_enabled';
    private const XML_PATH_QUEUE_FREQUENCY = 'system/full_page_cache/cloudflare/queue_frequency';
    private const XML_PATH_QUEUE_BACKLOG_THRESHOLD = 'system/full_page_cache/cloudflare/queue_backlog_threshold';
    private const XML_PATH_EXCLUDED_TAGS = 'system/full_page_cache/cloudflare/excluded_tags';
    private const XML_PATH_LOG_LEVEL = 'system/full_page_cache/cloudflare/log_level';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly PageCacheConfig $pageCacheConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly Json $json
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

    /**
     * Whether tag purges are batched through the delayed-purge queue instead of being sent
     * instantly on every save. Off (default) preserves today's exact instant-purge behavior.
     */
    public function isQueueEnabled(?int $storeId = null): bool
    {
        return (bool)$this->scopeConfig->isSetFlag(
            self::XML_PATH_QUEUE_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * How often (in minutes) the queue drain cron actually fires - this directly drives
     * crontab.xml's schedule (see Model\System\Config\Backend\QueueFrequency), it isn't just
     * an in-code check, so the job genuinely doesn't run more often than this.
     */
    public function getQueueFrequencyMinutes(?int $storeId = null): int
    {
        $minutes = (int)$this->scopeConfig->getValue(
            self::XML_PATH_QUEUE_FREQUENCY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return max(1, $minutes ?: 5);
    }

    /**
     * Pending tag count above which the admin backlog warning is shown.
     */
    public function getQueueBacklogThreshold(?int $storeId = null): int
    {
        $threshold = (int)$this->scopeConfig->getValue(
            self::XML_PATH_QUEUE_BACKLOG_THRESHOLD,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return max(1, $threshold ?: 50);
    }

    /**
     * Tag patterns that are never sent to Cloudflare for purging - e.g. gql_* tags, which
     * relate to GraphQL response caching and could never appear in a Cloudflare-cached page's
     * Cache-Tag header (CacheTagHeaderPlugin only fires on HTML page layout output), so
     * purging them is a wasted API call against the rate-limit bucket for nothing.
     *
     * A trailing "*" matches as a prefix (e.g. "gql_*" matches "gql_store_config_1");
     * otherwise the pattern must match the tag exactly.
     *
     * @return string[]
     */
    public function getExcludedTagPatterns(?int $storeId = null): array
    {
        $raw = $this->scopeConfig->getValue(self::XML_PATH_EXCLUDED_TAGS, ScopeInterface::SCOPE_STORE, $storeId);
        if (!$raw) {
            return [];
        }

        try {
            $rows = $this->json->unserialize($raw);
        } catch (\InvalidArgumentException $e) {
            return [];
        }

        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($row): string => trim((string)($row['pattern'] ?? '')),
            $rows
        )));
    }

    /**
     * Minimum severity this module actually writes to var/log/stacknuts_cloudflare_cache.log -
     * see Logger\Logger, which gates every call against this. LogLevel::LEVEL_OFF (0) disables
     * logging entirely. Defaults to Warning: logging every successful purge at Info was filling
     * the log with one line per save on stores not using the delayed queue.
     */
    public function getLogLevel(?int $storeId = null): int
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_LOG_LEVEL, ScopeInterface::SCOPE_STORE, $storeId);

        return $value !== null && $value !== '' ? (int)$value : MonologLogger::WARNING;
    }
}
