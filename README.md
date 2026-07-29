# StackNuts Cloudflare Cache

Cloudflare as a first-class Full Page Cache type for Magento 2, right there in Stores → Configuration → Advanced → System → Full Page Cache, next to Built-in and Varnish, with cache tag support for only clearing content that's actually changed.

## Why

Most Cloudflare integrations for Magento 2 just add support for clearing the whole cache and administering Cloudflare's settings from the Magento admin. Cloudflare never becomes a first-class choice in the admin, and cache invalidation stays all-or-nothing: any change purges everything, whether it's one product or the whole catalog.

This module takes the same approach Magento uses for Varnish itself: it adds "Cloudflare" as a genuine `caching_application` option, and wires purge requests into the same core events (`clean_cache_by_tags`, `adminhtml_cache_flush_all`, etc.) that `Magento_CacheInvalidate` uses for Varnish, just pointed at the Cloudflare API instead of a Varnish host. Magento's own tag generation (`X-Magento-Tags`) is untouched; this module mirrors it into a Cloudflare `Cache-Tag` response header on every cacheable page, which is what makes the targeted `tags` purges below possible.

## Installation

```bash
composer require stacknuts/magento-cloudflare-cache
bin/magento module:enable StackNuts_CloudflareCache
bin/magento setup:upgrade
```

## Configuration

1. In the Cloudflare dashboard, create an API token scoped to **Zone → Cache Purge** for the zone you want to manage.
2. **Add a Cache Rule for your storefront hostname** (Caching → Cache Rules): hostname equals your store's domain, action **Eligible for cache**, Edge TTL and Browser TTL both **Respect origin TTL**. This step is required: Cloudflare does not cache HTML by default, only recognized static file extensions, so without this rule the module's purges have nothing to do and every page request shows `cf-cache-status: DYNAMIC` or `BYPASS` regardless of how caching is configured in Magento. "Respect origin TTL" matters too: Magento already sends the correct `Cache-Control` per page (public for cacheable pages, private/no-store for cart, checkout, customer account, admin), so overriding the edge TTL instead of respecting origin risks caching pages Magento explicitly marked private.
3. In Magento admin, go to **Stores → Configuration → Advanced → System → Full Page Cache**, and set **Caching Application** to **Cloudflare**.
4. Under the new **Cloudflare Configuration** section, enter the **Zone ID** (from the Cloudflare dashboard's zone Overview page) and the **API Token**.
5. Choose a **Purge Mode**:
   - **Purge by tag** (default, recommended): targeted purges using Magento's own cache tags (`cat_p_123`, `cms_p_45`, etc.), mirrored into Cloudflare's `Cache-Tag` response header and purged via the `tags` API parameter, so saving one product only clears that product's pages. Available on every Cloudflare plan, but [purge-request rate limits scale with plan](https://developers.cloudflare.com/cache/how-to/purge-cache/) (Free: 5 requests/minute; Pro: 5/second; Business: 10/second; Enterprise: 50/second), each request covering up to 100 tags. A very high-traffic Free/Pro store doing frequent saves could hit that limit.
   - **Full flush only**: entity saves (product/category/CMS edits, etc.) never purge Cloudflare on their own. Only a global cache event (admin "Flush Cache Storage"/"Flush Magento Cache", a media/catalog-image cache clean, a cache-type refresh, or a theme reassignment) sends a `purge_everything` request. This avoids ever purging the whole zone over a single product edit, but it also means an edited page keeps serving the old Cloudflare-cached version until you purge it manually (Cloudflare dashboard or API) or its edge TTL expires.
6. Optionally enable **Add Debug Header**. Cloudflare's edge strips the `Cache-Tag` header before it reaches the client, so there's normally no way to see which tags a page carries from outside the module. This mirrors the same tag list into an `X-Cache-Tags` response header instead, viewable in any browser's network tab, useful for confirming a page is tagged the way you expect without needing dashboard or API access.

## CLI

```bash
# Purge everything
bin/magento stacknuts:cloudflare-cache:purge --all

# Purge specific tags (only takes effect in "Purge by tag" mode)
bin/magento stacknuts:cloudflare-cache:purge cat_p_123 cms_p_45
```

### Checking whether Cloudflare is actually caching

The admin "Test Connection" button (next to the API Token field) only proves the Zone ID/API Token are valid. It doesn't prove Cloudflare is actually caching and purging your storefront. For that, use:

```bash
bin/magento stacknuts:cloudflare-cache:healthcheck
```

This makes real HTTP requests to your store's public URL (through whatever DNS/proxy path a real visitor uses) and runs the same checks worked out by hand while building this module: that the response actually passed through Cloudflare, that Magento marked the page publicly cacheable, that no `Set-Cookie` header leaked onto a cacheable response (Cloudflare silently refuses to cache anything carrying one), and that a second request comes back as `cf-cache-status: HIT`. It purges the tested hostname first for a clean MISS → HIT proof (add `--skip-purge` to just inspect current headers instead), and exits non-zero if any check fails. Safe to run after a live/production deploy as an automated health check. Use `--url` to test a specific page instead of the default store's base URL.

## Logging

Purge attempts and failures are logged to `var/log/stacknuts_cloudflare_cache.log`.

## License

MIT. See [LICENSE](LICENSE).
