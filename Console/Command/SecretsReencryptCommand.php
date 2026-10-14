<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Console\Command;

use Iranimij\OpenImport\Api\ProfileRepositoryInterface;
use Iranimij\OpenImport\Model\Security\Secrets;
use Magento\Framework\Api\SearchCriteriaBuilder;
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
     */
    public function __construct(
        private readonly ProfileRepositoryInterface $profiles,
        private readonly SearchCriteriaBuilder $criteriaBuilder,
        private readonly Secrets $secrets
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
            $config = $profile->getSourceConfig();
            if (!isset($config[Secrets::KEY])) {
                continue;
            }
            $this->profiles->save($profile->setSourceConfig($this->secrets->reencrypt($config)));
            $count++;
        }
        $output->writeln(sprintf('%d profile(s) with secrets encrypted again.', $count));

        return Command::SUCCESS;
    }
}
