<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\CloudflareCache\Test\Unit\Logger;

use Monolog\Logger as MonologLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use StackNuts\CloudflareCache\Logger\Logger;
use StackNuts\CloudflareCache\Model\Config;
use StackNuts\CloudflareCache\Model\System\Config\Source\LogLevel;

class LoggerTest extends TestCase
{
    private function buildConfig(int $logLevel): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('getLogLevel')->willReturn($logLevel);

        return $config;
    }

    public function testWritesMessagesAtOrAboveTheConfiguredLevel(): void
    {
        $writer = $this->createMock(LoggerInterface::class);
        $writer->expects($this->once())->method('log')->with(MonologLogger::ERROR, 'something broke', []);

        $logger = new Logger($writer, $this->buildConfig(MonologLogger::WARNING));
        $logger->error('something broke');
    }

    public function testSuppressesMessagesBelowTheConfiguredLevel(): void
    {
        $writer = $this->createMock(LoggerInterface::class);
        $writer->expects($this->never())->method('log');

        $logger = new Logger($writer, $this->buildConfig(MonologLogger::WARNING));
        $logger->info('purge succeeded');
    }

    public function testOffSuppressesEverythingIncludingErrors(): void
    {
        $writer = $this->createMock(LoggerInterface::class);
        $writer->expects($this->never())->method('log');

        $logger = new Logger($writer, $this->buildConfig(LogLevel::LEVEL_OFF));
        $logger->error('this would normally always log');
        $logger->emergency('so would this');
    }

    public function testDebugLevelWritesEverything(): void
    {
        $writer = $this->createMock(LoggerInterface::class);
        $writer->expects($this->exactly(8))->method('log');

        $logger = new Logger($writer, $this->buildConfig(MonologLogger::DEBUG));
        $logger->emergency('m');
        $logger->alert('m');
        $logger->critical('m');
        $logger->error('m');
        $logger->warning('m');
        $logger->notice('m');
        $logger->info('m');
        $logger->debug('m');
    }

    public function testLogPassesThroughContext(): void
    {
        $writer = $this->createMock(LoggerInterface::class);
        $writer->expects($this->once())->method('log')->with(MonologLogger::WARNING, 'msg', ['tags' => ['cat_p_1']]);

        $logger = new Logger($writer, $this->buildConfig(MonologLogger::WARNING));
        $logger->warning('msg', ['tags' => ['cat_p_1']]);
    }

    public function testLogWithNonIntegerLevelIsIgnored(): void
    {
        $writer = $this->createMock(LoggerInterface::class);
        $writer->expects($this->never())->method('log');

        $logger = new Logger($writer, $this->buildConfig(MonologLogger::DEBUG));
        $logger->log('not-a-valid-psr-level', 'msg');
    }
}
