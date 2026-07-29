<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\CloudflareCache\Test\Unit\Plugin\Model\App\Response;

use Laminas\Http\Header\HeaderInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Stdlib\CookieDisablerInterface;
use PHPUnit\Framework\TestCase;
use StackNuts\CloudflareCache\Model\Config;
use StackNuts\CloudflareCache\Plugin\Model\App\Response\HttpPlugin;

class HttpPluginTest extends TestCase
{
    private function stubCacheControlHeader(string $value): HeaderInterface
    {
        $header = $this->createStub(HeaderInterface::class);
        $header->method('getFieldValue')->willReturn($value);

        return $header;
    }

    public function testClearsSetCookieOnPublicCacheableResponse(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isActive')->willReturn(true);

        $response = $this->createMock(HttpResponse::class);
        $response->method('getHeader')->with('Cache-Control')
            ->willReturn($this->stubCacheControlHeader('max-age=86400, public, s-maxage=86400'));
        $response->expects($this->once())->method('clearHeader')->with('Set-Cookie');

        $cookieDisabler = $this->createMock(CookieDisablerInterface::class);
        $cookieDisabler->expects($this->once())->method('setCookiesDisabled')->with(true);

        (new HttpPlugin($config, $cookieDisabler))->beforeSendResponse($response);
    }

    public function testDoesNothingWhenCloudflareNotActive(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isActive')->willReturn(false);

        $response = $this->createMock(HttpResponse::class);
        $response->expects($this->never())->method('getHeader');
        $response->expects($this->never())->method('clearHeader');

        $cookieDisabler = $this->createMock(CookieDisablerInterface::class);
        $cookieDisabler->expects($this->never())->method('setCookiesDisabled');

        (new HttpPlugin($config, $cookieDisabler))->beforeSendResponse($response);
    }

    public function testDoesNothingWhenResponseIsNotPubliclyCacheable(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isActive')->willReturn(true);

        $response = $this->createMock(HttpResponse::class);
        $response->method('getHeader')->with('Cache-Control')
            ->willReturn($this->stubCacheControlHeader('no-cache, no-store, must-revalidate'));
        $response->expects($this->never())->method('clearHeader');

        $cookieDisabler = $this->createMock(CookieDisablerInterface::class);
        $cookieDisabler->expects($this->never())->method('setCookiesDisabled');

        (new HttpPlugin($config, $cookieDisabler))->beforeSendResponse($response);
    }

    public function testDoesNothingWhenNoCacheControlHeaderPresent(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isActive')->willReturn(true);

        $response = $this->createMock(HttpResponse::class);
        $response->method('getHeader')->with('Cache-Control')->willReturn(false);
        $response->expects($this->never())->method('clearHeader');

        $cookieDisabler = $this->createMock(CookieDisablerInterface::class);
        $cookieDisabler->expects($this->never())->method('setCookiesDisabled');

        (new HttpPlugin($config, $cookieDisabler))->beforeSendResponse($response);
    }
}
