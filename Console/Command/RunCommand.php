<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Console\Command;

use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Model\Execution\RunProcessor;
use Iranimij\OpenImport\Model\Execution\RunQueue;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento openimport:run <profile> [--dry-run] [--full] [--file=] [--confirm] [--queue]
 */
class RunCommand extends Command
{
    /**
     * @param State $state
     * @param ProfileFinder $profiles
     * @param RunQueue $queue
     * @param RunProcessor $processor
     * @param RunReport $report
     */
    public function __construct(
        private readonly State $state,
        private readonly ProfileFinder $profiles,
        private readonly RunQueue $queue,
        private readonly RunProcessor $processor,
        private readonly RunReport $report
    ) {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName('openimport:run')
            ->setDescription('Run an OpenImport profile now (a dry run with --dry-run).')
            ->addArgument('profile', InputArgument::REQUIRED, 'Profile id or name')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Check and preview; change nothing')
            ->addOption('full', null, InputOption::VALUE_NONE, 'Ignore the delta state and send every row')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Use this file instead of the profile source')
            ->addOption('confirm', null, InputOption::VALUE_NONE, 'Confirm a run above the safety threshold')
            ->addOption('queue', null, InputOption::VALUE_NONE, 'Only add the run to the queue (cron runs it)');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->state->setAreaCode(Area::AREA_ADMINHTML);
        } catch (LocalizedException $exception) {
            // The area is already set (another command ran first in this process).
            unset($exception);
        }
        try {
            $profile = $this->profiles->find((string) $input->getArgument('profile'));
        } catch (LocalizedException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Command::FAILURE;
        }
        $options = [];
        $file = (string) $input->getOption('file');
        if ($file !== '') {
            $info = new \SplFileInfo($file);
            $real = $info->getRealPath();
            if ($real === false || !$info->isFile()) {
                $output->writeln(sprintf('<error>The file %s does not exist.</error>', $file));
                return Command::FAILURE;
            }
            $options['file'] = $real;
            $options['original_name'] = $info->getFilename();
        }
        if ($input->getOption('full')) {
            $options['full'] = true;
        }
        if ($input->getOption('confirm')) {
            $options['confirmed'] = true;
        }
        $run = $this->queue->queue(
            $profile,
            $input->getOption('dry-run') ? RunInterface::MODE_DRY_RUN : RunInterface::MODE_LIVE,
            RunInterface::TRIGGER_CLI,
            $options,
            'cli'
        );
        if ($input->getOption('queue')) {
            $output->writeln(sprintf('Run %d queued for "%s".', (int) $run->getRunId(), $profile->getName()));
            return Command::SUCCESS;
        }
        $output->writeln(sprintf(
            '%s "%s" (run %d) …',
            $run->isDryRun() ? 'Dry run of' : 'Running',
            $profile->getName(),
            (int) $run->getRunId()
        ));
        $run = $this->processor->process((int) $run->getRunId());
        $this->report->write($run, $output);

        return in_array($run->getStatus(), [RunInterface::STATUS_SUCCESS, RunInterface::STATUS_PARTIAL], true)
            ? Command::SUCCESS
            : Command::FAILURE;
    }
}
