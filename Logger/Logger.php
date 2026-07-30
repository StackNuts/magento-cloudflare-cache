<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\CloudflareCache\Logger;

use Monolog\Logger as MonologLogger;
use Psr\Log\LoggerInterface;
use StackNuts\CloudflareCache\Model\Config;
use StackNuts\CloudflareCache\Model\System\Config\Source\LogLevel;

/**
 * Gates every call against the admin-configured "Log Level" before writing to
 * var/log/stacknuts_cloudflare_cache.log, so a busy store doing instant (non-queued) purges
 * doesn't fill that file with one Info line per save. Wraps the actual Monolog writer
 * (Logger\Handler via the "writer" virtualType in etc/di.xml) rather than extending it, since
 * the level check has to happen before a message ever reaches Monolog's own handler.
 */
class Logger implements LoggerInterface
{
    public function __construct(
        private readonly LoggerInterface $writer,
        private readonly Config $config
    ) {
    }

    public function emergency(string|\Stringable $message, array $context = []): void
    {
        $this->log(MonologLogger::EMERGENCY, $message, $context);
    }

    public function alert(string|\Stringable $message, array $context = []): void
    {
        $this->log(MonologLogger::ALERT, $message, $context);
    }

    public function critical(string|\Stringable $message, array $context = []): void
    {
        $this->log(MonologLogger::CRITICAL, $message, $context);
    }

    public function error(string|\Stringable $message, array $context = []): void
    {
        $this->log(MonologLogger::ERROR, $message, $context);
    }

    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->log(MonologLogger::WARNING, $message, $context);
    }

    public function notice(string|\Stringable $message, array $context = []): void
    {
        $this->log(MonologLogger::NOTICE, $message, $context);
    }

    public function info(string|\Stringable $message, array $context = []): void
    {
        $this->log(MonologLogger::INFO, $message, $context);
    }

    public function debug(string|\Stringable $message, array $context = []): void
    {
        $this->log(MonologLogger::DEBUG, $message, $context);
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if (!is_int($level) || !$this->shouldLog($level)) {
            return;
        }

        $this->writer->log($level, $message, $context);
    }

    private function shouldLog(int $level): bool
    {
        $configuredLevel = $this->config->getLogLevel();

        return $configuredLevel !== LogLevel::LEVEL_OFF && $level >= $configuredLevel;
    }
}
