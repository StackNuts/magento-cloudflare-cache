<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\CloudflareCache\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use StackNuts\CloudflareCache\Model\Config;
use StackNuts\CloudflareCache\Model\PurgeCache;
use StackNuts\CloudflareCache\Model\ResourceModel\PurgeQueue;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * End-to-end proof that Cloudflare is actually caching and purging this
 * store, not just that the configured credentials are valid (that's what
 * the admin "Test Connection" button checks). Makes real HTTP requests to
 * the live public URL and inspects cf-cache-status/Cache-Control/Set-Cookie,
 * the same checks used to debug this integration manually. When a delayed
 * purge is configured, also forces a real tag through the queue and proves
 * the drain actually purges Cloudflare, not just that rows can be written
 * to the table. Safe to run against a live/production deploy as a post-deploy
 * health check - exits non-zero on any failed check.
 */
class HealthcheckCommand extends Command
{
    private const OPTION_URL = 'url';
    private const OPTION_SKIP_PURGE = 'skip-purge';

    /**
     * Cloudflare's tag-based purge is confirmed accepted synchronously (the purge API call
     * itself succeeds), but propagating that purge across its global edge network is not
     * instant - a single immediate re-fetch after a successful purge call was producing false
     * FAILs here even though the purge had genuinely worked, just not within one round trip.
     */
    private const PROPAGATION_MAX_ATTEMPTS = 5;
    private const PROPAGATION_WAIT_SECONDS = 3;

    public function __construct(
        private readonly Config $config,
        private readonly PurgeCache $purgeCache,
        private readonly StoreManagerInterface $storeManager,
        private readonly Curl $curl,
        private readonly State $state,
        private readonly PurgeQueue $purgeQueue,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('stacknuts:cloudflare-cache:healthcheck')
            ->setDescription('Verify Cloudflare is actually caching and purging this store (not just that credentials are valid)')
            ->addOption(
                self::OPTION_URL,
                null,
                InputOption::VALUE_REQUIRED,
                'URL to test (defaults to the default store\'s base URL)'
            )
            ->addOption(
                self::OPTION_SKIP_PURGE,
                null,
                InputOption::VALUE_NONE,
                'Skip the initial purge; just inspect current cache headers instead of proving a fresh MISS -> HIT cycle'
            );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->state->setAreaCode(Area::AREA_ADMINHTML);
        } catch (\Exception $e) {
            // Area code already set; safe to continue.
        }

        $checks = [];

        if (!$this->config->isActive()) {
            $output->writeln(
                '<error>Cloudflare is not the active Full Page Cache type '
                . '(Stores > Configuration > Advanced > System > Full Page Cache).</error>'
            );
            return Command::FAILURE;
        }
        $checks[] = ['Cloudflare selected as Full Page Cache type', true, ''];

        if (!$this->config->getZoneId() || !$this->config->getApiToken()) {
            $output->writeln('<error>Zone ID or API Token is not configured.</error>');
            return Command::FAILURE;
        }
        $checks[] = ['Zone ID / API Token configured', true, ''];

        $delayEnabled = $this->config->isQueueEnabled();
        if ($delayEnabled) {
            $pending = $this->purgeQueue->getPendingCount();
            $oldestAge = $this->purgeQueue->getOldestPendingAgeInSeconds();
            $lastRunAt = $this->purgeQueue->getLastRunAt();
            $output->writeln(sprintf(
                '<comment>Delayed purge queue: %d tag(s) pending%s. Cron last ran: %s.</comment>',
                $pending,
                $oldestAge !== null ? sprintf(' (oldest queued %ds ago)', $oldestAge) : '',
                $lastRunAt !== null ? sprintf('%ds ago', max(0, time() - $lastRunAt)) : 'never'
            ));
        }

        $url = $input->getOption(self::OPTION_URL) ?: $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_LINK);
        $hostname = parse_url($url, PHP_URL_HOST);

        if (!$hostname) {
            $output->writeln(sprintf('<error>Could not determine a hostname from "%s".</error>', $url));
            return Command::FAILURE;
        }

        if (!$input->getOption(self::OPTION_SKIP_PURGE)) {
            $output->writeln(sprintf('<comment>Purging %s for a clean test...</comment>', $hostname));
            $this->purgeCache->purgeHost($hostname);
        }

        try {
            $first = $this->fetch($url);
            $second = $this->fetch($url);
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>Could not fetch %s: %s</error>', $url, $e->getMessage()));
            return Command::FAILURE;
        }

        $checks[] = $this->checkProxied($first);
        $checks[] = $this->checkCacheControl($first);
        $checks[] = $this->checkNoSetCookie($first);
        $checks[] = $this->checkHitOnSecondRequest($first, $second, (bool)$input->getOption(self::OPTION_SKIP_PURGE));

        if ($delayEnabled) {
            array_push($checks, ...$this->checkDelayedPurgeQueue($url, $second, $output));
        }

        $table = new Table($output);
        $table->setHeaders(['Check', 'Result', 'Detail'])
            ->setColumnMaxWidth(0, 40)
            ->setColumnMaxWidth(2, 50)
            ->setRows(array_map(
                static fn (array $check) => [$check[0], $check[1] ? '<info>PASS</info>' : '<error>FAIL</error>', $check[2]],
                $checks
            ));
        $table->render();

        $failed = array_filter($checks, static fn (array $check) => !$check[1]);
        if ($failed) {
            $output->writeln(sprintf('<error>%d check(s) failed.</error>', count($failed)));
            return Command::FAILURE;
        }

        $output->writeln('<info>All checks passed - Cloudflare is caching and purging this store correctly.</info>');
        return Command::SUCCESS;
    }

    /**
     * @return array{status: int, headers: array<string, mixed>}
     */
    private function fetch(string $url): array
    {
        $this->curl->get($url);

        return [
            'status' => $this->curl->getStatus(),
            'headers' => array_change_key_case($this->curl->getHeaders(), CASE_LOWER),
        ];
    }

    private function checkProxied(array $response): array
    {
        if (isset($response['headers']['cf-cache-status'])) {
            return ['Response passed through Cloudflare', true, 'cf-cache-status: ' . $response['headers']['cf-cache-status']];
        }

        return [
            'Response passed through Cloudflare',
            false,
            'No cf-cache-status header at all - this request never reached Cloudflare. Check the URL resolves to a '
            . 'proxied (orange-cloud) DNS record, and that you\'re testing the public URL, not the origin directly.',
        ];
    }

    private function checkCacheControl(array $response): array
    {
        $cacheControl = (string)($response['headers']['cache-control'] ?? '');
        if (str_contains($cacheControl, 'public')) {
            return ['Page is marked publicly cacheable', true, 'Cache-Control: ' . $cacheControl];
        }

        return [
            'Page is marked publicly cacheable',
            false,
            'Cache-Control did not include "public" (got "' . $cacheControl . '"). Confirm Full Page Cache is enabled '
            . 'and this URL is genuinely cacheable content, not cart/checkout/customer account.',
        ];
    }

    private function checkNoSetCookie(array $response): array
    {
        if (empty($response['headers']['set-cookie'])) {
            return ['No Set-Cookie header on cacheable page', true, ''];
        }

        return [
            'No Set-Cookie header on cacheable page',
            false,
            'A Set-Cookie header reached the client on a page Magento marked cacheable - Cloudflare will bypass '
            . 'caching for it. Confirm the module is active and try bin/magento cache:flush.',
        ];
    }

    private function checkHitOnSecondRequest(array $first, array $second, bool $skippedPurge): array
    {
        $secondStatus = strtoupper((string)($second['headers']['cf-cache-status'] ?? ''));

        if ($secondStatus === 'HIT') {
            return ['Second request served from Cloudflare cache', true, 'cf-cache-status: HIT'];
        }

        $detail = sprintf(
            'Second request returned cf-cache-status: %s (expected HIT).',
            $secondStatus ?: 'none'
        );
        if (!$skippedPurge) {
            $detail .= ' If the above checks passed, this may just need a moment to propagate - or check for a '
                . 'Cloudflare Cache Rule making this hostname "Eligible for cache" with "Respect origin TTL".';
        }

        return ['Second request served from Cloudflare cache', false, $detail];
    }

    /**
     * Proves the delayed-purge queue actually works end to end - not just that rows can be
     * written to the table, but that a real drain purges Cloudflare and the page is actually
     * no longer cached afterward. Reuses PurgeCache/PurgeQueue directly (the same primitives
     * DrainPurgeQueue's cron job uses) rather than DrainPurgeQueue itself, since this is a
     * forced, on-demand drain - it deliberately skips the elapsed-since-last-drain gate that
     * only makes sense for cron's fixed schedule, not an explicit manual check.
     *
     * Uses X-Magento-Tags (Magento core's own header, set for any FPC type - not gated on this
     * module's "Add Debug Header" setting) to get a real tag for the tested page, rather than a
     * synthetic one, so the final check can prove the actual page cleared, not just the queue.
     *
     * @return array[] one or more [label, bool, detail] rows, fewer than 3 if an earlier step failed
     */
    private function checkDelayedPurgeQueue(string $url, array $cachedResponse, OutputInterface $output): array
    {
        $tagsHeader = (string)($cachedResponse['headers']['x-magento-tags'] ?? '');
        $tags = array_values(array_filter(array_map('trim', explode(',', $tagsHeader))));

        if (!$tags) {
            return [[
                'Delayed purge queue actually drains and purges',
                false,
                'No X-Magento-Tags header on the cached response - cannot exercise the queue without a real cache tag.',
            ]];
        }

        $this->purgeQueue->enqueue($tags);
        $maxId = $this->purgeQueue->getMaxId();
        $queued = $this->purgeQueue->getPendingTags($maxId);
        $reachedQueue = !array_diff($tags, $queued);

        $checks = [[
            'Tag purge reaches the delayed queue',
            $reachedQueue,
            $reachedQueue
                ? sprintf('%d tag(s) queued: %s', count($tags), implode(', ', $tags))
                : 'Enqueued tags were not found in the queue afterward.',
        ]];

        if (!$reachedQueue) {
            return $checks;
        }

        $drained = $this->purgeCache->purgeByTags($tags);
        if ($drained) {
            $this->purgeQueue->deleteUpTo($maxId);
        }
        $stillQueued = (bool)$this->purgeQueue->getPendingTags($maxId);

        $checks[] = [
            'Manual drain purges Cloudflare and clears the queue',
            $drained && !$stillQueued,
            !$drained
                ? ('Purge failed: ' . ($this->purgeCache->getLastError() ?? 'unknown error'))
                : ($stillQueued ? 'Purge succeeded but the queued rows were not cleared.' : 'Purge succeeded and the queue is now empty.'),
        ];

        if (!$drained) {
            return $checks;
        }

        [$pageCleared, $thirdStatus, $attempts] = $this->waitForPurgeToPropagate($url, $output);

        $checks[] = [
            'Page no longer served from cache after the drain',
            $pageCleared,
            $pageCleared
                ? sprintf('cf-cache-status: %s (after %d attempt(s))', $thirdStatus ?: 'none', $attempts)
                : sprintf(
                    'Still showing cf-cache-status: HIT after %d attempt(s) over ~%ds - the purge may need '
                    . 'longer than that to propagate, or check Cloudflare\'s dashboard directly.',
                    $attempts,
                    ($attempts - 1) * self::PROPAGATION_WAIT_SECONDS
                ),
        ];

        return $checks;
    }

    /**
     * Re-fetches the page a few times, a short wait apart, instead of a single immediate
     * check - Cloudflare confirms a tag purge synchronously, but propagating it across the
     * edge network isn't instant, so one immediate re-fetch was producing false FAILs for
     * purges that had genuinely worked, just not within a single round trip.
     *
     * @return array{0: bool, 1: string, 2: int} [cleared, last cf-cache-status seen, attempts made]
     */
    private function waitForPurgeToPropagate(string $url, OutputInterface $output): array
    {
        for ($attempt = 1; $attempt <= self::PROPAGATION_MAX_ATTEMPTS; $attempt++) {
            if ($attempt > 1) {
                $output->writeln(sprintf(
                    '<comment>Still showing as cached, waiting %ds for the purge to propagate (attempt %d/%d)...</comment>',
                    self::PROPAGATION_WAIT_SECONDS,
                    $attempt,
                    self::PROPAGATION_MAX_ATTEMPTS
                ));
                $this->wait(self::PROPAGATION_WAIT_SECONDS);
            }

            $response = $this->fetch($url);
            $status = strtoupper((string)($response['headers']['cf-cache-status'] ?? ''));

            if ($status !== 'HIT') {
                return [true, $status, $attempt];
            }
        }

        return [false, $status, $attempt - 1];
    }

    /**
     * Wraps the actual sleep() call so tests can override it (e.g. an anonymous subclass with
     * a no-op body) to exercise the retry loop without genuinely pausing the test suite.
     */
    protected function wait(int $seconds): void
    {
        sleep($seconds);
    }
}
