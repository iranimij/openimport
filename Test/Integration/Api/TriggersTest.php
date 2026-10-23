<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Integration\Api;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Api\Data\ProfileInterfaceFactory;
use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Api\ProfileRepositoryInterface;
use Iranimij\OpenImport\Api\RunManagementInterface;
use Iranimij\OpenImport\Model\Entity\EntityRegistry;
use Iranimij\OpenImport\Observer\QueueOrderExports;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Webapi\ServiceOutputProcessor;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Starting profiles through the service contract the REST API uses, and the order-placed trigger.
 */
#[AppArea('adminhtml')]
#[DbIsolation(false)]
#[AppIsolation(true)]
class TriggersTest extends TestCase
{
    private function profile(array $data): ProfileInterface
    {
        $profile = Bootstrap::getObjectManager()->get(ProfileInterfaceFactory::class)->create()
            ->setName('Trigger ' . uniqid())
            ->setFormat('csv');
        foreach ($data as $setter => $value) {
            $profile->{$setter}($value);
        }

        return Bootstrap::getObjectManager()->get(ProfileRepositoryInterface::class)->save($profile);
    }

    private function queued(int $profileId): int
    {
        $connection = Bootstrap::getObjectManager()->get(ResourceConnection::class)->getConnection();

        return (int) $connection->fetchOne(
            $connection->select()->from($connection->getTableName('openimport_run'), ['COUNT(*)'])
                ->where('profile_id = ?', $profileId)->where('status = ?', RunInterface::STATUS_QUEUED)
        );
    }

    public function testStartAndStatusThroughTheServiceContract(): void
    {
        $export = $this->profile([
            'setDirection' => ProfileInterface::DIRECTION_EXPORT,
            'setEntity' => 'catalog_product',
            'setExportOptions' => ['destination' => 'download'],
        ]);
        $api = Bootstrap::getObjectManager()->get(RunManagementInterface::class);
        $started = $api->start((int) $export->getProfileId(), 'live');
        self::assertSame(RunInterface::STATUS_QUEUED, $started->getStatus());
        self::assertSame('live', $started->getMode());
        self::assertSame($started->getRunId(), $api->status($started->getRunId())->getRunId());

        // The REST layer can serialise the answer.
        $output = Bootstrap::getObjectManager()->get(ServiceOutputProcessor::class)
            ->process($started, RunManagementInterface::class, 'start');
        self::assertSame($started->getRunId(), $output['run_id']);
        self::assertSame('queued', $output['status']);

        $upload = $this->profile(['setEntity' => 'catalog_product', 'setSourceType' => 'upload', 'setMapping' => [['target' => 'sku', 'source' => 'sku']]]);
        try {
            $api->start((int) $upload->getProfileId());
            self::fail('upload profiles cannot start through the API');
        } catch (LocalizedException $exception) {
            self::assertStringContainsString('uploaded file', $exception->getMessage());
        }
        try {
            $api->start((int) $export->getProfileId(), 'now');
            self::fail('unknown mode');
        } catch (LocalizedException $exception) {
            self::assertStringContainsString('dry_run', $exception->getMessage());
        }
    }

    public function testOrderPlacedQueuesOnceForProfilesThatAskForIt(): void
    {
        $wants = $this->profile([
            'setDirection' => ProfileInterface::DIRECTION_EXPORT,
            'setEntity' => EntityRegistry::ORDER,
            'setExportOptions' => ['destination' => 'download', 'order' => ['only' => 'new', 'on_place' => '1']],
        ]);
        $ignores = $this->profile([
            'setDirection' => ProfileInterface::DIRECTION_EXPORT,
            'setEntity' => EntityRegistry::ORDER,
            'setExportOptions' => ['destination' => 'download', 'order' => ['only' => 'new', 'on_place' => '0']],
        ]);
        // A JSON file or the API may store true instead of "1".
        $boolean = $this->profile([
            'setDirection' => ProfileInterface::DIRECTION_EXPORT,
            'setEntity' => EntityRegistry::ORDER,
            'setExportOptions' => ['destination' => 'download', 'order' => ['only' => 'new', 'on_place' => true]],
        ]);
        $observer = Bootstrap::getObjectManager()->get(QueueOrderExports::class);
        $observer->execute(new Observer());
        $observer->execute(new Observer());
        self::assertSame(1, $this->queued((int) $wants->getProfileId()), 'two orders, one waiting run');
        self::assertSame(0, $this->queued((int) $ignores->getProfileId()));
        self::assertSame(1, $this->queued((int) $boolean->getProfileId()));
    }
}
