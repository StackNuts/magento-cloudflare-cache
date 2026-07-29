<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\CloudflareCache\Test\Unit\Model;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use StackNuts\CloudflareCache\Model\Config;
use StackNuts\CloudflareCache\Model\PurgeCache;

class PurgeCacheTest extends TestCase
{
    private function createConfig(?string $zoneId = 'zone-1', ?string $apiToken = 'token-1', string $purgeMode = Config::PURGE_MODE_TAGS): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('getZoneId')->willReturn($zoneId);
        $config->method('getApiToken')->willReturn($apiToken);
        $config->method('isTagPurgeMode')->willReturn($purgeMode === Config::PURGE_MODE_TAGS);

        return $config;
    }

    public function testPurgeAllPostsPurgeEverything(): void
    {
        $curl = $this->createMock(Curl::class);
        $curl->expects($this->once())->method('setHeaders');
        $curl->expects($this->once())
            ->method('post')
            ->with(
                'https://api.cloudflare.com/client/v4/zones/zone-1/purge_cache',
                json_encode(['purge_everything' => true])
            );
        $curl->method('getStatus')->willReturn(200);
        $curl->method('getBody')->willReturn(json_encode(['success' => true]));

        $purgeCache = new PurgeCache($curl, $this->createConfig(), new Json(), $this->createStub(LoggerInterface::class));

        $this->assertTrue($purgeCache->purgeAll());
    }

    public function testPurgeAllReturnsFalseWhenNotConfigured(): void
    {
        $curl = $this->createMock(Curl::class);
        $curl->expects($this->never())->method('post');

        $purgeCache = new PurgeCache(
            $curl,
            $this->createConfig(null, null),
            new Json(),
            $this->createStub(LoggerInterface::class)
        );

        $this->assertFalse($purgeCache->purgeAll());
    }

    public function testPurgeByTagsNoOpsWhenNotInTagMode(): void
    {
        $curl = $this->createMock(Curl::class);
        $curl->expects($this->never())->method('post');

        $purgeCache = new PurgeCache(
            $curl,
            $this->createConfig('zone-1', 'token-1', Config::PURGE_MODE_FULL_FLUSH_ONLY),
            new Json(),
            $this->createStub(LoggerInterface::class)
        );

        $this->assertTrue($purgeCache->purgeByTags(['cat_p_1']));
    }

    public function testPurgeByTagsChunksAtOneHundredTagsPerRequest(): void
    {
        $tags = array_map(static fn (int $i) => "tag_$i", range(1, 150));

        $curl = $this->createMock(Curl::class);
        $curl->expects($this->exactly(2))->method('post');
        $curl->method('getStatus')->willReturn(200);
        $curl->method('getBody')->willReturn(json_encode(['success' => true]));

        $purgeCache = new PurgeCache($curl, $this->createConfig(), new Json(), $this->createStub(LoggerInterface::class));

        $this->assertTrue($purgeCache->purgeByTags($tags));
    }

    public function testPurgeByTagsWithNoTagsIsNoOp(): void
    {
        $curl = $this->createMock(Curl::class);
        $curl->expects($this->never())->method('post');

        $purgeCache = new PurgeCache($curl, $this->createConfig(), new Json(), $this->createStub(LoggerInterface::class));

        $this->assertTrue($purgeCache->purgeByTags([]));
    }

    public function testPurgeHostPostsHostsPayload(): void
    {
        $curl = $this->createMock(Curl::class);
        $curl->expects($this->once())
            ->method('post')
            ->with(
                'https://api.cloudflare.com/client/v4/zones/zone-1/purge_cache',
                json_encode(['hosts' => ['example.com']])
            );
        $curl->method('getStatus')->willReturn(200);
        $curl->method('getBody')->willReturn(json_encode(['success' => true]));

        $purgeCache = new PurgeCache($curl, $this->createConfig(), new Json(), $this->createStub(LoggerInterface::class));

        $this->assertTrue($purgeCache->purgeHost('example.com'));
        $this->assertNull($purgeCache->getLastError());
    }

    public function testGetLastErrorReflectsCloudflareErrorMessage(): void
    {
        $curl = $this->createStub(Curl::class);
        $curl->method('getStatus')->willReturn(400);
        $curl->method('getBody')->willReturn(json_encode([
            'success' => false,
            'errors' => [['code' => 6003, 'message' => 'Invalid request headers']],
        ]));

        $purgeCache = new PurgeCache($curl, $this->createConfig(), new Json(), $this->createStub(LoggerInterface::class));

        $this->assertFalse($purgeCache->purgeHost('example.com'));
        $this->assertSame('Invalid request headers', $purgeCache->getLastError());
    }

    public function testGetLastErrorWhenNotConfigured(): void
    {
        $purgeCache = new PurgeCache(
            $this->createStub(Curl::class),
            $this->createConfig(null, null),
            new Json(),
            $this->createStub(LoggerInterface::class)
        );

        $purgeCache->purgeHost('example.com');

        $this->assertSame('Zone ID or API token is not configured.', $purgeCache->getLastError());
    }
}
