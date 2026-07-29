<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\CloudflareCache\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use StackNuts\CloudflareCache\Model\PurgeCache;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class PurgeCacheCommand extends Command
{
    private const ARGUMENT_TAGS = 'tags';
    private const OPTION_ALL = 'all';

    public function __construct(
        private readonly PurgeCache $purgeCache,
        private readonly State $state,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('stacknuts:cloudflare-cache:purge')
            ->setDescription('Purge the Cloudflare cache for this store')
            ->addArgument(
                self::ARGUMENT_TAGS,
                InputArgument::IS_ARRAY | InputArgument::OPTIONAL,
                'One or more cache tags to purge (ignored with --all). Requires "Purge by tag" mode.'
            )
            ->addOption(
                self::OPTION_ALL,
                'a',
                InputOption::VALUE_NONE,
                'Purge the entire Cloudflare cache instead of specific tags'
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

        if ($input->getOption(self::OPTION_ALL)) {
            $success = $this->purgeCache->purgeAll();
        } else {
            $tags = $input->getArgument(self::ARGUMENT_TAGS);
            if (!$tags) {
                $output->writeln('<error>Provide one or more tags, or pass --all to purge everything.</error>');
                return Command::FAILURE;
            }
            $success = $this->purgeCache->purgeByTags($tags);
        }

        if ($success) {
            $output->writeln('<info>Cloudflare cache purge request sent successfully.</info>');
            return Command::SUCCESS;
        }

        $output->writeln('<error>Cloudflare cache purge failed. Check var/log/stacknuts_cloudflare_cache.log for details.</error>');
        return Command::FAILURE;
    }
}
