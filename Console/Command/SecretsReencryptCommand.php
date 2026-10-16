<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Console\Command;

use Iranimij\OpenImport\Api\ConnectionRepositoryInterface;
use Iranimij\OpenImport\Api\ProfileRepositoryInterface;
use Iranimij\OpenImport\Model\Security\Secrets;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento openimport:secrets:reencrypt — after `encryption:key:change`, encrypts every stored source secret
 * with the current key (Magento still decrypts with the old key, so this must run before the old key is removed).
 */
class SecretsReencryptCommand extends Command
{
    /**
     * @param ProfileRepositoryInterface $profiles
     * @param SearchCriteriaBuilder $criteriaBuilder
     * @param Secrets $secrets
     * @param ConnectionRepositoryInterface $connections
     */
    public function __construct(
        private readonly ProfileRepositoryInterface $profiles,
        private readonly SearchCriteriaBuilder $criteriaBuilder,
        private readonly Secrets $secrets,
        private readonly ConnectionRepositoryInterface $connections
    ) {
        parent::__construct();
    }

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('openimport:secrets:reencrypt')
            ->setDescription('Encrypt stored source passwords, keys and tokens again with the current encryption key.');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = 0;
        foreach ($this->profiles->getList($this->criteriaBuilder->create())->getItems() as $profile) {
            $source = $profile->getSourceConfig();
            $export = $profile->getExportOptions();
            if (!isset($source[Secrets::KEY]) && !isset($export[Secrets::KEY])) {
                continue;
            }
            $profile->setSourceConfig($this->secrets->reencrypt($source))
                ->setExportOptions($this->secrets->reencrypt($export));
            try {
                $this->profiles->save($profile);
                $count++;
            } catch (LocalizedException $exception) {
                $output->writeln(sprintf('<error>%s: %s</error>', $profile->getName(), $exception->getMessage()));
            }
        }
        $connections = 0;
        foreach ($this->connections->getAll() as $connection) {
            $settings = $connection->getSettings();
            if (!isset($settings[Secrets::KEY])) {
                continue;
            }
            $this->connections->save($connection->setSettings($this->secrets->reencrypt($settings)));
            $connections++;
        }
        $output->writeln(sprintf(
            '%d profile(s) and %d connection(s) with secrets encrypted again.',
            $count,
            $connections
        ));

        return Command::SUCCESS;
    }
}
