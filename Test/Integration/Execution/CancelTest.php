<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Integration\Execution;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Api\Data\ProfileInterfaceFactory;
use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Api\ProfileRepositoryInterface;
use Iranimij\OpenImport\Model\Entity\EntityRegistry;
use Iranimij\OpenImport\Model\Execution\RunFiles;
use Iranimij\OpenImport\Model\Execution\RunProcessor;
use Iranimij\OpenImport\Model\Execution\RunQueue;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Cancel while running: the run stops at its next checkpoint while it reads, before it writes anything.
 */
#[AppArea('adminhtml')]
#[DbIsolation(false)]
#[AppIsolation(true)]
class CancelTest extends TestCase
{
    public function testARunningRunStopsWhileItReads(): void
    {
        $profile = Bootstrap::getObjectManager()->get(ProfileInterfaceFactory::class)->create()
            ->setName('Cancel ' . uniqid())
            ->setEntity(EntityRegistry::STOCK_PRICE)
            ->setFormat('csv')
            ->setSourceType('upload')
            ->setBehavior(ProfileInterface::BEHAVIOR_ONLY_UPDATE)
            ->setMapping([['target' => 'sku', 'source' => 'sku'], ['target' => 'qty', 'source' => 'qty']]);
        $profile = Bootstrap::getObjectManager()->get(ProfileRepositoryInterface::class)->save($profile);

        $lines = ["sku,qty"];
        for ($i = 1; $i <= 2500; $i++) {
            $lines[] = 'OI-CANCEL-' . $i . ',' . $i;
        }
        $var = Bootstrap::getObjectManager()->get(Filesystem::class)->getDirectoryWrite(DirectoryList::VAR_DIR);
        $relative = 'openimport/it-cancel-' . bin2hex(random_bytes(3)) . '.csv';
        $var->writeFile($relative, implode("\n", $lines) . "\n");

        $run = Bootstrap::getObjectManager()->get(RunQueue::class)->queue(
            $profile,
            RunInterface::MODE_LIVE,
            RunInterface::TRIGGER_CLI,
            ['file' => $var->getAbsolutePath($relative), 'original_name' => 'stock.csv']
        );
        // Cancel arrives while the run is going (here: before the processor reaches its first checkpoint).
        Bootstrap::getObjectManager()->get(RunFiles::class)->requestCancel((int) $run->getRunId());
        $run = Bootstrap::getObjectManager()->get(RunProcessor::class)->process((int) $run->getRunId());

        self::assertSame(RunInterface::STATUS_CANCELLED, $run->getStatus(), $run->getMessage());
        self::assertStringContainsString('Cancelled while running', $run->getMessage());
        self::assertSame(2000, $run->getCount('read'));
    }
}
