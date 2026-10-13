<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Console\Command;

use Iranimij\OpenImport\Api\RunRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento openimport:run:status <run id>
 */
class RunStatusCommand extends Command
{
    /**
     * @param RunRepositoryInterface $runs
     * @param RunReport $report
     */
    public function __construct(
        private readonly RunRepositoryInterface $runs,
        private readonly RunReport $report
    ) {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName('openimport:run:status')
            ->setDescription('Show an OpenImport run: status, counts, changes and errors.')
            ->addArgument('run', InputArgument::REQUIRED, 'Run id');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $run = $this->runs->getById((int) $input->getArgument('run'));
        } catch (NoSuchEntityException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Command::FAILURE;
        }
        $this->report->write($run, $output);
        $output->writeln(sprintf(
            'Queued %s, started %s, finished %s, peak memory %d MB.',
            $run->getCreatedAt() ?? '–',
            $run->getStartedAt() ?? '–',
            $run->getFinishedAt() ?? '–',
            $run->getPeakMemoryMb()
        ));

        return Command::SUCCESS;
    }
}
