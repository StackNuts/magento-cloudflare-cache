<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\CloudflareCache\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\PageCache\Model\Config as PageCacheConfig;
use PHPUnit\Framework\TestCase;
use StackNuts\CloudflareCache\Model\Config;

class ConfigTest extends TestCase
{
    private function buildConfig(ScopeConfigInterface $scopeConfig, PageCacheConfig $pageCacheConfig): Config
    {
        $encryptor = $this->createStub(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturnArgument(0);

        return new Config($scopeConfig, $pageCacheConfig, $encryptor);
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

        $config = new Config($scopeConfig, $this->createStub(PageCacheConfig::class), $encryptor);

        $this->assertSame('plain-token', $config->getApiToken());
    }

    public function testGetApiTokenDoesNotDecryptWhenEmpty(): void
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn(null);

        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->expects($this->never())->method('decrypt');

        $config = new Config($scopeConfig, $this->createStub(PageCacheConfig::class), $encryptor);

        $this->assertNull($config->getApiToken());
    }
}
