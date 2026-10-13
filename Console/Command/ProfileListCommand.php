<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Console\Command;

use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Api\ProfileRepositoryInterface;
use Iranimij\OpenImport\Api\RunRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento openimport:profile:list
 */
class ProfileListCommand extends Command
{
    /**
     * @param ProfileRepositoryInterface $profiles
     * @param RunRepositoryInterface $runs
     * @param SearchCriteriaBuilder $criteriaBuilder
     * @param SortOrderBuilder $sortOrderBuilder
     */
    public function __construct(
        private readonly ProfileRepositoryInterface $profiles,
        private readonly RunRepositoryInterface $runs,
        private readonly SearchCriteriaBuilder $criteriaBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder
    ) {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName('openimport:profile:list')
            ->setDescription('List OpenImport profiles with their schedule and last run.');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $profiles = $this->profiles->getList($this->criteriaBuilder->create())->getItems();
        if ($profiles === []) {
            $output->writeln('No profiles yet. Create one under System › Data Transfer › OpenImport Profiles.');
            return Command::SUCCESS;
        }
        $table = new Table($output);
        $table->setHeaders(['ID', 'Name', 'Direction', 'Entity', 'Source', 'Schedule', 'Active', 'Last run']);
        foreach ($profiles as $profile) {
            $table->addRow([
                (string) $profile->getProfileId(),
                $profile->getName(),
                $profile->getDirection(),
                $profile->getEntity(),
                $profile->getSourceType(),
                $profile->getSchedule() ?? 'manual',
                $profile->isActive() ? 'yes' : 'no',
                $this->lastRun((int) $profile->getProfileId()),
            ]);
        }
        $table->render();

        return Command::SUCCESS;
    }

    private function lastRun(int $profileId): string
    {
        $sort = $this->sortOrderBuilder->setField(RunInterface::RUN_ID)->setDescendingDirection()->create();
        $criteria = $this->criteriaBuilder
            ->addFilter(RunInterface::PROFILE_ID, $profileId)
            ->setSortOrders([$sort])
            ->setPageSize(1)
            ->setCurrentPage(1)
            ->create();
        $runs = $this->runs->getList($criteria)->getItems();
        $run = reset($runs);
        if (!$run instanceof RunInterface) {
            return '–';
        }

        return sprintf(
            '#%d %s %s (%s)',
            (int) $run->getRunId(),
            $run->isDryRun() ? 'dry run' : 'live',
            str_replace('_', ' ', $run->getStatus()),
            $run->getFinishedAt() ?? $run->getCreatedAt() ?? ''
        );
    }
}
