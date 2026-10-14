<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Integration;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Api\Data\ProfileInterfaceFactory;
use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Api\Data\RunInterfaceFactory;
use Iranimij\OpenImport\Api\ProfileRepositoryInterface;
use Iranimij\OpenImport\Api\RunRepositoryInterface;
use Iranimij\OpenImport\Model\Storage\RowHashStore;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Profiles are stored with their mapping rows and value maps, validated on save, and keep their runs when deleted.
 */
#[AppArea('adminhtml')]
#[DbIsolation(true)]
class ProfileRepositoryTest extends TestCase
{
    private function repository(): ProfileRepositoryInterface
    {
        return Bootstrap::getObjectManager()->get(ProfileRepositoryInterface::class);
    }

    private function newProfile(string $name = 'Nightly stock'): ProfileInterface
    {
        $profile = Bootstrap::getObjectManager()->get(ProfileInterfaceFactory::class)->create();
        $profile->setName($name)
            ->setDirection('import')
            ->setEntity('catalog_product')
            ->setSourceType('sftp')
            ->setSourceConfig(['host' => 'sftp', 'username' => 'shop', 'path' => '/in/stock.csv'])
            ->setFormat('csv')
            ->setFormatOptions(['delimiter' => ';'])
            ->setBehavior('only_update')
            ->setDelta(true)
            ->setSchedule('0 2 * * *')
            ->setRowFilters(['conditions' => [['column' => 'Bestand', 'operator' => 'not_empty']]])
            ->setMapping([
                ['target' => 'sku', 'source' => 'Artikelnummer', 'transforms' => ['trim']],
                ['target' => 'qty', 'source' => 'Bestand', 'transforms' => [['name' => 'number', 'args' => [0]]]],
                ['target' => 'status', 'source' => 'Aktiv', 'transforms' => ['map'], 'value_map_strict' => true],
                ['target' => 'store:default:name', 'source' => 'Name', 'required' => true],
            ])
            ->setValueMaps(['status' => ['ja' => '1', 'Nein' => '2']]);

        return $profile;
    }

    public function testSaveAndLoadEverything(): void
    {
        $saved = $this->repository()->save($this->newProfile());
        $loaded = $this->repository()->getById((int) $saved->getProfileId());

        self::assertSame('Nightly stock', $loaded->getName());
        self::assertSame(['host' => 'sftp', 'username' => 'shop', 'path' => '/in/stock.csv'], $loaded->getSourceConfig());
        self::assertSame(['delimiter' => ';'], $loaded->getFormatOptions());
        self::assertSame('only_update', $loaded->getBehavior());
        self::assertTrue($loaded->isDelta());
        self::assertFalse($loaded->isActive());
        self::assertSame('0 2 * * *', $loaded->getSchedule());
        self::assertNull($loaded->getSafetyThreshold());
        self::assertSame(
            [
                ['target' => 'sku', 'source' => 'Artikelnummer', 'static' => null, 'transforms' => ['trim'],
                    'required' => false, 'value_map_case_sensitive' => false, 'value_map_strict' => false],
                ['target' => 'qty', 'source' => 'Bestand', 'static' => null,
                    'transforms' => [['name' => 'number', 'args' => [0]]],
                    'required' => false, 'value_map_case_sensitive' => false, 'value_map_strict' => false],
                ['target' => 'status', 'source' => 'Aktiv', 'static' => null, 'transforms' => ['map'],
                    'required' => false, 'value_map_case_sensitive' => false, 'value_map_strict' => true],
                ['target' => 'store:default:name', 'source' => 'Name', 'static' => null, 'transforms' => [],
                    'required' => true, 'value_map_case_sensitive' => false, 'value_map_strict' => false],
            ],
            $loaded->getMapping()
        );
        self::assertSame(['status' => ['ja' => '1', 'Nein' => '2']], $loaded->getValueMaps());
        self::assertNotNull($loaded->getCreatedAt());
        self::assertSame($loaded->getProfileId(), $this->repository()->getByName(' NIGHTLY stock ')->getProfileId());
    }

    public function testValidationListsProblems(): void
    {
        $profile = $this->newProfile()->setBehavior('merge')->setSchedule('every night');

        try {
            $this->repository()->save($profile);
            self::fail('Expected a validation error');
        } catch (CouldNotSaveException $exception) {
            self::assertStringContainsString('The behaviour "merge" is not one of', $exception->getMessage());
            self::assertStringContainsString('A schedule needs 5 fields', $exception->getMessage());
        }
    }

    public function testNamesAreUniqueIgnoringCase(): void
    {
        $this->repository()->save($this->newProfile('Supplier A'));

        $this->expectException(CouldNotSaveException::class);
        $this->expectExceptionMessage('A profile named "supplier a" already exists.');
        $this->repository()->save($this->newProfile('supplier a'));
    }

    public function testChangingTheMappingClearsDeltaHashes(): void
    {
        $profile = $this->repository()->save($this->newProfile());
        $profileId = (int) $profile->getProfileId();
        $hashes = Bootstrap::getObjectManager()->get(RowHashStore::class);
        $hashes->save($profileId, ['A' => 'h1', 'B' => 'h2']);

        // Saving without changes keeps the hashes; a schedule change keeps them too.
        $this->repository()->save($this->repository()->getById($profileId)->setSchedule('0 3 * * *'));
        self::assertSame(2, $hashes->count($profileId));

        $changed = $this->repository()->getById($profileId);
        $mapping = $changed->getMapping();
        $mapping[1]['transforms'] = [];
        $this->repository()->save($changed->setMapping($mapping));
        self::assertSame(0, $hashes->count($profileId));
    }

    public function testDeletingKeepsRuns(): void
    {
        $profile = $this->repository()->save($this->newProfile());
        $runs = Bootstrap::getObjectManager()->get(RunRepositoryInterface::class);
        $run = Bootstrap::getObjectManager()->get(RunInterfaceFactory::class)->create();
        $run->setProfileId($profile->getProfileId())->setProfileName($profile->getName())
            ->setEntity('catalog_product')->setTrigger(RunInterface::TRIGGER_CLI)->setMode(RunInterface::MODE_LIVE);
        $runId = (int) $runs->save($run)->getRunId();

        $this->repository()->deleteById((int) $profile->getProfileId());

        $kept = $runs->getById($runId);
        self::assertNull($kept->getProfileId());
        self::assertSame('Nightly stock', $kept->getProfileName());
        self::assertSame(RunInterface::STATUS_QUEUED, $kept->getStatus());
        $this->expectException(NoSuchEntityException::class);
        $this->repository()->getById((int) $profile->getProfileId());
    }

    public function testList(): void
    {
        $this->repository()->save($this->newProfile('List A'));
        $this->repository()->save($this->newProfile('List B'));
        $criteria = Bootstrap::getObjectManager()->create(SearchCriteriaBuilder::class)
            ->addFilter('name', 'List %', 'like')
            ->create();

        $results = $this->repository()->getList($criteria);

        self::assertSame(2, $results->getTotalCount());
        self::assertSame(['List A', 'List B'], array_map(
            static fn (ProfileInterface $profile): string => $profile->getName(),
            $results->getItems()
        ));
    }
}
