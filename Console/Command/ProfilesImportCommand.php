<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Console\Command;

use Iranimij\OpenImport\Model\Profile\ProfileJson;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento openimport:profiles:import <file.json> [--dry-run] — profiles from a JSON export: a profile with
 * the same name is updated, others are created. Everything is checked first; one problem and nothing is saved.
 * Secrets are not in the file: enter them in the admin afterwards (stored ones of updated profiles are kept).
 */
class ProfilesImportCommand extends Command
{
    /**
     * @param ProfileJson $json
     * @param FileDriver $files
     */
    public function __construct(
        private readonly ProfileJson $json,
        private readonly FileDriver $files
    ) {
        parent::__construct();
    }

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('openimport:profiles:import')
            ->setDescription('Create or update profiles from a JSON export; all or nothing.')
            ->addArgument('file', InputArgument::REQUIRED, 'JSON file written by openimport:profiles:export')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Check the file and say what would happen, save nothing');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = (string) $input->getArgument('file');
        try {
            $prepared = $this->json->prepare((string) $this->files->fileGetContents($file));
        } catch (\InvalidArgumentException $exception) {
            $output->writeln('<error>Nothing was saved:</error>');
            $output->writeln($exception->getMessage());

            return Command::FAILURE;
        } catch (\Exception $exception) {
            $output->writeln(sprintf('<error>%s cannot be read: %s</error>', $file, $exception->getMessage()));

            return Command::FAILURE;
        }
        foreach ($prepared as [$profile, $exists]) {
            $output->writeln(sprintf('%s %s', $exists ? 'update' : 'create', $profile->getName()));
        }
        if ($input->getOption('dry-run')) {
            $output->writeln(sprintf('Dry run: %d profile(s) checked, nothing saved.', count($prepared)));

            return Command::SUCCESS;
        }
        [$created, $updated] = $this->json->save($prepared);
        $output->writeln(sprintf('%d profile(s) created, %d updated.', $created, $updated));

        return Command::SUCCESS;
    }
}
