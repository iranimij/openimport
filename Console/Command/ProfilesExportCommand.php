<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Console\Command;

use Iranimij\OpenImport\Api\ProfileRepositoryInterface;
use Iranimij\OpenImport\Model\Profile\ProfileJson;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\NoSuchEntityException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento openimport:profiles:export [profile ...] — profiles as JSON on standard output (all when no
 * profile is named), without secrets. Pipe into a file and keep it in version control.
 */
class ProfilesExportCommand extends Command
{
    /**
     * @param ProfileRepositoryInterface $profiles
     * @param SearchCriteriaBuilder $criteriaBuilder
     * @param ProfileFinder $finder
     * @param ProfileJson $json
     */
    public function __construct(
        private readonly ProfileRepositoryInterface $profiles,
        private readonly SearchCriteriaBuilder $criteriaBuilder,
        private readonly ProfileFinder $finder,
        private readonly ProfileJson $json
    ) {
        parent::__construct();
    }

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('openimport:profiles:export')
            ->setDescription('Write profiles as JSON (all, or the named ones), without passwords and keys.')
            ->addArgument('profile', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Profile ids or names; empty = every profile');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $names = (array) $input->getArgument('profile');
        if ($names === []) {
            $list = $this->profiles->getList($this->criteriaBuilder->create())->getItems();
        } else {
            $list = [];
            foreach ($names as $name) {
                try {
                    $list[] = $this->finder->find((string) $name);
                } catch (NoSuchEntityException $exception) {
                    $output->writeln(sprintf('<error>There is no profile "%s".</error>', (string) $name));

                    return Command::FAILURE;
                }
            }
        }
        $output->write($this->json->encode(array_values($list)));

        return Command::SUCCESS;
    }
}
