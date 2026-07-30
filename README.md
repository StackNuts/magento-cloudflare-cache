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
6. In "Purge by tag" mode, optionally add **Excluded Tag Patterns** for tags that should never be sent to Cloudflare. Four patterns are excluded by default, none of which could ever appear in the `Cache-Tag` header of an actual Cloudflare-cached page (the plugin that sets that header only fires on HTML page rendering), so purging them just wastes purge-request quota for no effect:
   - `gql_*` and `inv_pl*` - GraphQL resolver-cache tags (store config, currency, country, media gallery, in-store pickup locations), which relate to GraphQL response caching, not page caching.
   - `wishlist_*` and `compare_item_*` - per-customer/per-visitor private content that Magento always keeps out of the shared page cache, never baked into a page any other visitor would see.

   End a pattern with `*` to match a prefix (e.g. `gql_*` matches `gql_store_config_1`), or enter a tag exactly to match only that one. Applies whether the delayed purge queue below is on or off. Remove any of the defaults from the admin list if they turn out not to apply to your setup.
7. In "Purge by tag" mode, optionally enable the **Delayed Purge Queue** for high-traffic stores approaching the rate limits mentioned above:
   - **Enable Delayed Purge Queue**: off (default) purges every tag instantly on save, exactly as above. On, tags are queued in a small database table instead and batched into a single purge on the schedule below - a burst of saves close together sends one purge request instead of one per save.
   - **Queue Run Frequency (minutes)**: how often the queue is drained, default 5. This directly controls the cron job's actual schedule (not just an in-code check), so it genuinely doesn't run more often than this. Requires cron to be running (`bin/magento cron:run`, or the system crontab).
   - **Backlog Alert Threshold**: pending tag count above which an admin warning banner appears, default 50. Adjust based on your store's normal save volume.

   A purge that fails is retried once on the next drain, then given up on, so one persistently-failing tag or a Cloudflare outage can't wedge the queue open indefinitely.
8. Optionally enable **Add Debug Header**. Cloudflare's edge strips the `Cache-Tag` header before it reaches the client, so there's normally no way to see which tags a page carries from outside the module. This mirrors the same tag list into an `X-Cache-Tags` response header instead, viewable in any browser's network tab, useful for confirming a page is tagged the way you expect without needing dashboard or API access.

## Monitoring the delayed purge queue

A **Delayed Purge Queue Status** overview sits at the top of the Cloudflare Configuration section (Stores → Configuration → Advanced → System → Full Page Cache) whenever the queue is enabled: pending tag count, configured run frequency, and when the drain cron last actually fired (tracked independently of whether there was anything to drain, so a healthy empty queue doesn't look like stalled cron).

If the queue is backlogged (pending count above the Backlog Alert Threshold) or the drain cron doesn't appear to be running (no run in roughly 3x the configured frequency), a warning banner also appears across every admin page, not just the configuration screen - so this doesn't require anyone to remember to go check it.

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

When the **Delayed Purge Queue** is enabled, this also proves the queue actually works rather than just reporting how many tags are currently waiting in it: it takes a real tag from the cached page's own `X-Magento-Tags` header, pushes it through the same queue a real save would, forces an immediate drain (bypassing the cron schedule so the check doesn't have to wait), and fetches the page a third time to confirm it's no longer served from cache. This proves the write to the queue, the drain, and the actual Cloudflare purge all work, not just that rows can be written to a table.

## Logging

Purge attempts and failures are logged to `var/log/stacknuts_cloudflare_cache.log`.

## License

MIT. See [LICENSE](LICENSE).
