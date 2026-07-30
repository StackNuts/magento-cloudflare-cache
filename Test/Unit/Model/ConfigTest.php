<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\CloudflareCache\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\PageCache\Model\Config as PageCacheConfig;
use PHPUnit\Framework\TestCase;
use StackNuts\CloudflareCache\Model\Config;

class ConfigTest extends TestCase
{
    private function buildConfig(ScopeConfigInterface $scopeConfig, PageCacheConfig $pageCacheConfig): Config
    {
        $encryptor = $this->createStub(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturnArgument(0);

        return new Config($scopeConfig, $pageCacheConfig, $encryptor, new Json());
    }

    public function testIsActiveRequiresBothCloudflareTypeAndEnabledCache(): void
    {
        $pageCacheConfig = $this->createStub(PageCacheConfig::class);
        $pageCacheConfig->method('isEnabled')->willReturn(true);
        $pageCacheConfig->method('getType')->willReturn(Config::TYPE_CLOUDFLARE);

        $config = $this->buildConfig($this->createStub(ScopeConfigInterface::class), $pageCacheConfig);

        $this->assertTrue($config->isActive());
    }

    public function testIsActiveFalseWhenAnotherTypeSelected(): void
    {
        $pageCacheConfig = $this->createStub(PageCacheConfig::class);
        $pageCacheConfig->method('isEnabled')->willReturn(true);
        $pageCacheConfig->method('getType')->willReturn(PageCacheConfig::VARNISH);

        $config = $this->buildConfig($this->createStub(ScopeConfigInterface::class), $pageCacheConfig);

        $this->assertFalse($config->isActive());
    }

    public function testIsActiveFalseWhenCacheDisabled(): void
    {
        $pageCacheConfig = $this->createStub(PageCacheConfig::class);
        $pageCacheConfig->method('isEnabled')->willReturn(false);
        $pageCacheConfig->method('getType')->willReturn(Config::TYPE_CLOUDFLARE);

        $config = $this->buildConfig($this->createStub(ScopeConfigInterface::class), $pageCacheConfig);

        $this->assertFalse($config->isActive());
    }

    public function testGetPurgeModeDefaultsToTags(): void
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn(null);

        $config = $this->buildConfig($scopeConfig, $this->createStub(PageCacheConfig::class));

        $this->assertSame(Config::PURGE_MODE_TAGS, $config->getPurgeMode());
        $this->assertTrue($config->isTagPurgeMode());
    }

    public function testIsTagPurgeModeFalseInFullFlushOnlyMode(): void
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn(Config::PURGE_MODE_FULL_FLUSH_ONLY);

        $config = $this->buildConfig($scopeConfig, $this->createStub(PageCacheConfig::class));

        $this->assertFalse($config->isTagPurgeMode());
    }

    public function testGetApiTokenDecryptsStoredValue(): void
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn('ciphertext');

        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->expects($this->once())->method('decrypt')->with('ciphertext')->willReturn('plain-token');

        $config = new Config($scopeConfig, $this->createStub(PageCacheConfig::class), $encryptor, new Json());

        $this->assertSame('plain-token', $config->getApiToken());
    }

    public function testGetApiTokenDoesNotDecryptWhenEmpty(): void
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn(null);

        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->expects($this->never())->method('decrypt');

        $config = new Config($scopeConfig, $this->createStub(PageCacheConfig::class), $encryptor, new Json());

        $this->assertNull($config->getApiToken());
    }

    public function testIsQueueEnabledDefaultsToFalse(): void
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturn(false);

        $config = $this->buildConfig($scopeConfig, $this->createStub(PageCacheConfig::class));

        $this->assertFalse($config->isQueueEnabled());
    }

    public function testIsQueueEnabledReflectsConfiguredFlag(): void
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturn(true);

        $config = $this->buildConfig($scopeConfig, $this->createStub(PageCacheConfig::class));

        $this->assertTrue($config->isQueueEnabled());
    }

    public function testGetQueueFrequencyMinutesDefaultsToFive(): void
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn(null);

        $config = $this->buildConfig($scopeConfig, $this->createStub(PageCacheConfig::class));

        $this->assertSame(5, $config->getQueueFrequencyMinutes());
    }

    public function testGetQueueFrequencyMinutesReturnsConfiguredValue(): void
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn('15');

        $config = $this->buildConfig($scopeConfig, $this->createStub(PageCacheConfig::class));

        $this->assertSame(15, $config->getQueueFrequencyMinutes());
    }

    public function testGetQueueFrequencyMinutesNeverBelowOne(): void
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn('-5');

        $config = $this->buildConfig($scopeConfig, $this->createStub(PageCacheConfig::class));

        $this->assertSame(1, $config->getQueueFrequencyMinutes());
    }

    public function testGetQueueBacklogThresholdDefaultsToFifty(): void
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn(null);

        $config = $this->buildConfig($scopeConfig, $this->createStub(PageCacheConfig::class));

        $this->assertSame(50, $config->getQueueBacklogThreshold());
    }

    public function testGetQueueBacklogThresholdReturnsConfiguredValue(): void
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn('200');

        $config = $this->buildConfig($scopeConfig, $this->createStub(PageCacheConfig::class));

        $this->assertSame(200, $config->getQueueBacklogThreshold());
    }

    public function testGetExcludedTagPatternsEmptyWhenNotSet(): void
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn(null);

        $config = $this->buildConfig($scopeConfig, $this->createStub(PageCacheConfig::class));

        $this->assertSame([], $config->getExcludedTagPatterns());
    }

    public function testGetExcludedTagPatternsParsesTheShippedDefaults(): void
    {
        // Mirrors the literal default value in etc/config.xml - all four are GraphQL-resolver-only
        // or per-customer private-content tags that could never appear in a Cloudflare-cached page.
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn(
            '{"_1000000000000_001":{"pattern":"gql_*"},"_1000000000000_002":{"pattern":"inv_pl*"},'
            . '"_1000000000000_003":{"pattern":"wishlist_*"},"_1000000000000_004":{"pattern":"compare_item_*"}}'
        );

        $config = $this->buildConfig($scopeConfig, $this->createStub(PageCacheConfig::class));

        $this->assertSame(['gql_*', 'inv_pl*', 'wishlist_*', 'compare_item_*'], $config->getExcludedTagPatterns());
    }

    public function testGetExcludedTagPatternsReturnsMultipleRowsAndIgnoresBlankOnes(): void
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn(
            '{"_1":{"pattern":"gql_*"},"_2":{"pattern":""},"_3":{"pattern":"noindex_1"}}'
        );

        $config = $this->buildConfig($scopeConfig, $this->createStub(PageCacheConfig::class));

        $this->assertSame(['gql_*', 'noindex_1'], $config->getExcludedTagPatterns());
    }

    public function testGetExcludedTagPatternsEmptyOnMalformedJson(): void
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn('not valid json');

        $config = $this->buildConfig($scopeConfig, $this->createStub(PageCacheConfig::class));

        $this->assertSame([], $config->getExcludedTagPatterns());
    }
}
