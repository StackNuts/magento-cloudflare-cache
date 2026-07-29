<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\CloudflareCache\Plugin\Model\App\Response;

use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Stdlib\CookieDisablerInterface;
use StackNuts\CloudflareCache\Model\Config;

/**
 * Cloudflare (like Varnish, and like Magento's own Built-in FPC storage path
 * in Magento\Framework\App\PageCache\Kernel::process()) will not cache a
 * response that carries a Set-Cookie header, and Magento sets a session
 * cookie on every request - including fully public, cacheable pages. The
 * native PHP session cookie is sent via PHP's raw SAPI header list (from
 * session_start()), not through Magento\Framework\App\Response\Http's own
 * header collection, so clearHeader('Set-Cookie') alone does not remove it -
 * CookieDisablerInterface (the same mechanism Kernel::process() uses for
 * Built-in caching) calls header_remove('Set-Cookie') to actually strip it.
 *
 * This has to run at beforeSendResponse, the very last point before headers
 * go out, not earlier in the Layout lifecycle: core's own
 * Magento\PageCache\Model\App\Response\HttpPlugin::beforeSendResponse adds a
 * fresh Set-Cookie: X-Magento-Vary=... cookie at that exact same terminal
 * stage (via sendVary()), so stripping cookies any earlier just gets undone.
 * This plugin's sortOrder must be higher than core's (which is unset,
 * i.e. 0) so it runs after core's beforeSendResponse.
 *
 * @see \Magento\Framework\App\PageCache\Kernel::process() for the same fix
 *      applied to Built-in caching.
 */
class HttpPlugin
{
    /**
     * Matches the Cache-Control header Magento\PageCache\Model\Layout\LayoutPlugin
     * sets for genuinely public/cacheable pages - same pattern
     * Magento\Framework\App\PageCache\Kernel::process() checks for Built-in caching.
     */
    private const PUBLIC_CACHE_CONTROL_PATTERN = '/public.*s-maxage=(\d+)/';

    public function __construct(
        private readonly Config $config,
        private readonly CookieDisablerInterface $cookieDisabler
    ) {
    }

    public function beforeSendResponse(HttpResponse $subject): void
    {
        if (!$this->config->isActive()) {
            return;
        }

        $cacheControlHeader = $subject->getHeader('Cache-Control');
        if ($cacheControlHeader
            && preg_match(self::PUBLIC_CACHE_CONTROL_PATTERN, (string)$cacheControlHeader->getFieldValue())
        ) {
            $subject->clearHeader('Set-Cookie');
            $this->cookieDisabler->setCookiesDisabled(true);
        }
    }
}
