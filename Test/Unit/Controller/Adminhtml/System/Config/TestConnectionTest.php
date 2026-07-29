<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\CloudflareCache\Test\Unit\Controller\Adminhtml\System\Config;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use StackNuts\CloudflareCache\Controller\Adminhtml\System\Config\TestConnection;
use StackNuts\CloudflareCache\Model\Config;
use StackNuts\CloudflareCache\Model\PurgeCache;

class TestConnectionTest extends TestCase
{
    private function buildController(
        Config $config,
        PurgeCache $purgeCache,
        StoreManagerInterface $storeManager,
        Json $jsonResult
    ): TestConnection {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturn(0);

        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);

        $jsonFactory = $this->createStub(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($jsonResult);

        return new TestConnection($context, $jsonFactory, $config, $purgeCache, $storeManager);
    }

    public function testReturnsFailureWhenCredentialsMissing(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getZoneId')->willReturn(null);

        $jsonResult = $this->createMock(Json::class);
        $jsonResult->expects($this->once())
            ->method('setData')
            ->with($this->callback(static fn (array $data) => $data['success'] === false))
            ->willReturnSelf();

        $controller = $this->buildController(
            $config,
            $this->createStub(PurgeCache::class),
            $this->createStub(StoreManagerInterface::class),
            $jsonResult
        );

        $controller->execute();
    }

    public function testPurgesCurrentStoreHostnameAndReportsSuccess(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getZoneId')->willReturn('zone-1');
        $config->method('getApiToken')->willReturn('token-1');

        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturn('https://magento.philr.org.uk/');

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $purgeCache = $this->createMock(PurgeCache::class);
        $purgeCache->expects($this->once())->method('purgeHost')->with('magento.philr.org.uk')->willReturn(true);

        $jsonResult = $this->createMock(Json::class);
        $jsonResult->expects($this->once())
            ->method('setData')
            ->with($this->callback(static fn (array $data) => $data['success'] === true))
            ->willReturnSelf();

        $controller = $this->buildController($config, $purgeCache, $storeManager, $jsonResult);
        $controller->execute();
    }

    public function testReportsCloudflareErrorOnFailure(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getZoneId')->willReturn('zone-1');
        $config->method('getApiToken')->willReturn('token-1');

        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturn('https://magento.philr.org.uk/');

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $purgeCache = $this->createStub(PurgeCache::class);
        $purgeCache->method('purgeHost')->willReturn(false);
        $purgeCache->method('getLastError')->willReturn('Invalid API token');

        $jsonResult = $this->createMock(Json::class);
        $jsonResult->expects($this->once())
            ->method('setData')
            ->with($this->callback(
                static fn (array $data) => $data['success'] === false
                    && str_contains($data['message'], 'Invalid API token')
            ))
            ->willReturnSelf();

        $controller = $this->buildController($config, $purgeCache, $storeManager, $jsonResult);
        $controller->execute();
    }
}
