<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\CloudflareCache\Test\Unit\Console\Command;

use Magento\Framework\App\State;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use StackNuts\CloudflareCache\Console\Command\HealthcheckCommand;
use StackNuts\CloudflareCache\Model\Config;
use StackNuts\CloudflareCache\Model\PurgeCache;
use Symfony\Component\Console\Tester\CommandTester;

class HealthcheckCommandTest extends TestCase
{
    private function buildConfig(bool $active = true, ?string $zoneId = 'zone-1', ?string $apiToken = 'token-1'): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('isActive')->willReturn($active);
        $config->method('getZoneId')->willReturn($zoneId);
        $config->method('getApiToken')->willReturn($apiToken);

        return $config;
    }

    private function buildStoreManager(): StoreManagerInterface
    {
        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturn('https://magento.philr.org.uk/');

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return $storeManager;
    }

    private function buildCommand(
        Config $config,
        Curl $curl,
        ?PurgeCache $purgeCache = null,
        ?StoreManagerInterface $storeManager = null
    ): HealthcheckCommand {
        return new HealthcheckCommand(
            $config,
            $purgeCache ?? $this->createStub(PurgeCache::class),
            $storeManager ?? $this->buildStoreManager(),
            $curl,
            $this->createStub(State::class)
        );
    }

    public function testFailsFastWhenCloudflareNotActive(): void
    {
        $command = $this->buildCommand($this->buildConfig(active: false), $this->createStub(Curl::class));
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('not the active Full Page Cache type', $tester->getDisplay());
    }

    public function testFailsFastWhenCredentialsMissing(): void
    {
        $command = $this->buildCommand($this->buildConfig(zoneId: null), $this->createStub(Curl::class));
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Zone ID or API Token', $tester->getDisplay());
    }

    public function testAllChecksPassOnHealthyCache(): void
    {
        $curl = $this->createStub(Curl::class);
        $curl->method('getStatus')->willReturn(200);
        $curl->method('getHeaders')->willReturnOnConsecutiveCalls(
            ['cf-cache-status' => 'MISS', 'Cache-Control' => 'public, max-age=86400, s-maxage=86400'],
            ['cf-cache-status' => 'HIT', 'Cache-Control' => 'public, max-age=86400, s-maxage=86400']
        );

        $purgeCache = $this->createMock(PurgeCache::class);
        $purgeCache->expects($this->once())->method('purgeHost')->with('magento.philr.org.uk');

        $command = $this->buildCommand($this->buildConfig(), $curl, $purgeCache);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('All checks passed', $tester->getDisplay());
    }

    public function testFailsWhenSetCookiePresentOnCacheableResponse(): void
    {
        $curl = $this->createStub(Curl::class);
        $curl->method('getStatus')->willReturn(200);
        $curl->method('getHeaders')->willReturn([
            'cf-cache-status' => 'HIT',
            'Cache-Control' => 'public, max-age=86400, s-maxage=86400',
            'Set-Cookie' => ['PHPSESSID=abc123'],
        ]);

        $command = $this->buildCommand($this->buildConfig(), $curl);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--skip-purge' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Set-Cookie header reached the client', $tester->getDisplay());
    }

    public function testFailsWhenNeverProxiedThroughCloudflare(): void
    {
        $curl = $this->createStub(Curl::class);
        $curl->method('getStatus')->willReturn(200);
        $curl->method('getHeaders')->willReturn(['Cache-Control' => 'public, max-age=86400, s-maxage=86400']);

        $command = $this->buildCommand($this->buildConfig(), $curl);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--skip-purge' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('never reached Cloudflare', $tester->getDisplay());
    }
}
