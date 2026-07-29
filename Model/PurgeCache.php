<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\CloudflareCache\Model;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends purge requests to the Cloudflare API.
 *
 * @see https://developers.cloudflare.com/api/operations/zone-purge
 */
class PurgeCache
{
    /**
     * Cloudflare's limit on the number of cache tags accepted per purge_cache request
     * (same 100-operation cap applies to tags, hostnames, and prefixes, on every plan).
     */
    private const TAGS_PER_REQUEST = 100;

    private ?string $lastError = null;

    public function __construct(
        private readonly Curl $curl,
        private readonly Config $config,
        private readonly Json $json,
        private readonly LoggerInterface $logger
    ) {
    }

    public function purgeAll(): bool
    {
        return $this->request(['purge_everything' => true], 'purge everything');
    }

    /**
     * Purge a single hostname. Used by the admin "Test Connection" button -
     * a real, low-blast-radius purge that proves the zone ID and API token
     * are actually valid and correctly scoped, rather than a fake check.
     */
    public function purgeHost(string $hostname): bool
    {
        return $this->request(['hosts' => [$hostname]], 'purge host');
    }

    /**
     * Human-readable detail for the most recent failed request, if any.
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Purge specific cache tags. Only sent when the module is configured for
     * tag-based purging - in "full flush only" mode, entity-level saves
     * intentionally do not purge Cloudflare (see Config::PURGE_MODE_FULL_FLUSH_ONLY),
     * so a single product/category/CMS edit never blows away the whole zone cache.
     *
     * @param string[] $tags
     */
    public function purgeByTags(array $tags): bool
    {
        $tags = array_values(array_unique(array_filter($tags)));
        if (!$tags) {
            return true;
        }

        if (!$this->config->isTagPurgeMode()) {
            $this->logger->debug(
                'Skipping tag purge (purge mode is "full flush only"); affected pages will need a '
                . 'manual purge via the Cloudflare dashboard/API, or will expire naturally via edge TTL.',
                ['tags' => $tags]
            );
            return true;
        }

        $success = true;
        foreach (array_chunk($tags, self::TAGS_PER_REQUEST) as $chunk) {
            // Cloudflare treats "tags" and "hosts"/"purge_everything" as OR'd
            // conditions, so each request must carry tags only.
            $success = $this->request(['tags' => $chunk], 'purge by tags') && $success;
        }

        return $success;
    }

    private function request(array $payload, string $description): bool
    {
        $this->lastError = null;
        $zoneId = $this->config->getZoneId();
        $apiToken = $this->config->getApiToken();

        if (!$zoneId || !$apiToken) {
            $this->lastError = 'Zone ID or API token is not configured.';
            $this->logger->warning(sprintf('Cannot %s: Cloudflare zone ID or API token is not configured.', $description));
            return false;
        }

        try {
            $this->curl->setHeaders([
                'Authorization' => 'Bearer ' . $apiToken,
                'Content-Type' => 'application/json',
            ]);
            $this->curl->post(
                sprintf('https://api.cloudflare.com/client/v4/zones/%s/purge_cache', $zoneId),
                $this->json->serialize($payload)
            );

            $status = $this->curl->getStatus();
            $response = $this->json->unserialize($this->curl->getBody() ?: '{}');
            $success = $status === 200 && !empty($response['success']);

            if ($success) {
                $this->logger->info(sprintf('Cloudflare %s succeeded.', $description), $payload);
            } else {
                $this->lastError = $response['errors'][0]['message'] ?? sprintf('HTTP %s', $status);
                $this->logger->error(
                    sprintf('Cloudflare %s failed (HTTP %s).', $description, $status),
                    ['payload' => $payload, 'response' => $response]
                );
            }

            return $success;
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            $this->logger->error(sprintf('Cloudflare %s threw an exception: %s', $description, $e->getMessage()));
            return false;
        }
    }
}
