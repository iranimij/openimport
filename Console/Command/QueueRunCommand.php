<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Console\Command;

use Iranimij\OpenImport\Model\Execution\RunProcessor;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento openimport:queue:run [--limit=N]: runs queued runs now (what OpenImport's cron does every minute).
 */
class QueueRunCommand extends Command
{
    /**
     * @param State $state
     * @param RunProcessor $processor
     */
    public function __construct(
        private readonly State $state,
        private readonly RunProcessor $processor
    ) {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName('openimport:queue:run')
            ->setDescription('Run the queued OpenImport runs now (the cron does this every minute).')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many runs', '10');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->state->setAreaCode(Area::AREA_ADMINHTML);
        } catch (LocalizedException $exception) {
            unset($exception);
        }
        $limit = max(1, (int) $input->getOption('limit'));
        $done = 0;
        while ($done < $limit && ($run = $this->processor->processNext()) !== null) {
            $done++;
            $output->writeln(sprintf(
                'Run %d (%s): %s',
                (int) $run->getRunId(),
                $run->getProfileName(),
                str_replace('_', ' ', $run->getStatus())
            ));
        }
        if ($done === 0) {
            $output->writeln('No queued runs.');
        }

        return Command::SUCCESS;
    }
}
