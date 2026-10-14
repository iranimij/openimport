<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Console\Command;

use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Model\Config;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Filesystem;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Indexer\ConfigInterface as IndexerConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento openimport:doctor — the things that make imports slow or stuck, checked in one go: cron,
 * indexers, PHP extensions, memory limit, folders, runs that wait or hang. Exit code 1 when something is wrong.
 */
class DoctorCommand extends Command
{
    /**
     * @param ResourceConnection $resource
     * @param Filesystem $filesystem
     * @param Config $config
     * @param IndexerRegistry $indexers
     * @param IndexerConfig $indexerConfig
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Filesystem $filesystem,
        private readonly Config $config,
        private readonly IndexerRegistry $indexers,
        private readonly IndexerConfig $indexerConfig
    ) {
        parent::__construct();
    }

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('openimport:doctor')
            ->setDescription('Check cron, indexers, PHP, folders and stuck runs; say what to fix.');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $problems = 0;
        foreach ($this->checks() as [$ok, $title, $detail]) {
            $output->writeln(sprintf('%s %s: %s', $ok ? '<info>[ OK ]</info>' : '<comment>[WARN]</comment>', $title, $detail));
            if (!$ok) {
                $problems++;
            }
        }
        $output->writeln($problems === 0 ? 'Everything looks fine.' : sprintf('%d thing(s) to look at.', $problems));

        return $problems === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @return array<int, array{0: bool, 1: string, 2: string}>
     */
    private function checks(): array
    {
        $checks = [];
        $connection = $this->resource->getConnection();

        // Cron: OpenImport's tick should have run within the last few minutes.
        $last = $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName('cron_schedule'), ['executed_at' => 'MAX(executed_at)'])
                ->where('job_code = ?', 'openimport_tick')
        );
        $age = $last ? (time() - strtotime((string) $last . ' UTC')) : null;
        $checks[] = [
            $age !== null && $age < 600,
            'Cron',
            $age === null
                ? 'openimport_tick has never run. Set up Magento cron (bin/magento cron:run every minute); queued runs wait until then.'
                : sprintf('openimport_tick last ran %d minute(s) ago.', intdiv($age, 60)),
        ];

        // Indexers on "Update on Save" make product imports slow.
        $onSave = [];
        foreach (array_keys($this->indexerConfig->getIndexers()) as $indexerId) {
            $indexer = $this->indexers->get((string) $indexerId);
            if (!$indexer->isScheduled()) {
                $onSave[] = (string) $indexerId;
            }
        }
        $checks[] = [
            $onSave === [],
            'Indexers',
            $onSave === []
                ? 'all on "Update by Schedule".'
                : sprintf('on "Update on Save": %s. Imports are faster with bin/magento indexer:set-mode schedule.', implode(', ', $onSave)),
        ];

        // PHP.
        foreach (['zip', 'xmlreader', 'mbstring', 'curl'] as $extension) {
            $checks[] = [extension_loaded($extension), 'PHP ext-' . $extension, extension_loaded($extension) ? 'loaded.' : 'missing: install it.'];
        }
        $limit = (string) ini_get('memory_limit');
        $bytes = $this->bytes($limit);
        $checks[] = [
            $bytes < 0 || $bytes >= 756 * 1048576,
            'PHP memory_limit',
            sprintf('%s for the CLI/cron%s', $limit, $bytes >= 0 && $bytes < 756 * 1048576 ? ' — Magento recommends at least 756M for imports.' : '.'),
        ];

        // Folder.
        $var = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        try {
            $var->create('openimport');
            $writable = $var->isWritable('openimport');
        } catch (\Exception $exception) {
            $writable = false;
        }
        $checks[] = [$writable, 'var/openimport', $writable ? 'writable.' : 'not writable by this user.'];
        $checks[] = [
            $this->config->getKeepFilesDays() <= 90,
            'Run files',
            sprintf('kept for %d day(s).', $this->config->getKeepFilesDays()),
        ];

        // Runs.
        $runs = $this->resource->getTableName('openimport_run');
        $queued = (int) $connection->fetchOne(
            $connection->select()->from($runs, ['COUNT(*)'])
                ->where('status = ?', RunInterface::STATUS_QUEUED)
                ->where('created_at < ?', gmdate('Y-m-d H:i:s', time() - 900))
        );
        $checks[] = [$queued === 0, 'Queue', $queued === 0 ? 'no run waits longer than 15 minutes.' : sprintf('%d run(s) have been waiting for more than 15 minutes: is cron running?', $queued)];
        $running = (int) $connection->fetchOne(
            $connection->select()->from($runs, ['COUNT(*)'])
                ->where('status = ?', RunInterface::STATUS_RUNNING)
                ->where('started_at < ?', gmdate('Y-m-d H:i:s', time() - 5400))
        );
        $checks[] = [$running === 0, 'Running', $running === 0 ? 'no run is older than 90 minutes.' : sprintf('%d run(s) "running" for more than 90 minutes; housekeeping marks them failed when their lock is gone.', $running)];

        return $checks;
    }

    /**
     * @param string $limit php.ini notation
     * @return int bytes, -1 for unlimited
     */
    private function bytes(string $limit): int
    {
        $limit = trim($limit);
        if ($limit === '-1') {
            return -1;
        }
        $unit = strtolower(substr($limit, -1));
        $number = (int) $limit;

        return match ($unit) {
            'g' => $number * 1073741824,
            'm' => $number * 1048576,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
