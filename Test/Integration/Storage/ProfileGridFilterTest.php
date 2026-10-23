<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Integration\Storage;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Api\Data\ProfileInterfaceFactory;
use Iranimij\OpenImport\Api\ProfileRepositoryInterface;
use Iranimij\OpenImport\Model\Config\Source\SourceOrDestinationFilter;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\SearchCriteriaBuilder;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The profiles grid's "Source / destination" filter finds imports by source and exports by destination.
 */
#[AppArea('adminhtml')]
#[DbIsolation(true)]
class ProfileGridFilterTest extends TestCase
{
    private function profile(string $name, string $direction, array $exportOptions = []): void
    {
        $profile = Bootstrap::getObjectManager()->get(ProfileInterfaceFactory::class)->create()
            ->setName($name)
            ->setDirection($direction)
            ->setEntity('openimport_cms_block')
            ->setFormat('csv')
            ->setSourceType('upload');
        if ($direction === ProfileInterface::DIRECTION_EXPORT) {
            $profile->setExportOptions($exportOptions);
        } else {
            $profile->setMapping([['target' => 'identifier', 'source' => 'identifier']]);
        }
        Bootstrap::getObjectManager()->get(ProfileRepositoryInterface::class)->save($profile);
    }

    /**
     * @return string[] names of the profiles the filter finds, among this test's
     */
    private function names(string $filter, string $tag): array
    {
        return $this->search([
            ['name', 'like', $tag . '%'],
            ['source_type', 'eq', $filter],
        ]);
    }

    /**
     * Through the grid's own Reporting object and filter pool, as the admin grid does (the pool joins a filter's
     * conditions with OR, the keyword search needs OpenImport's applier).
     *
     * @param array<int, array{0: string, 1: string, 2: string}> $filters field, condition type, value
     * @return string[]
     */
    private function search(array $filters, string $request = 'openimport_profile_listing_data_source'): array
    {
        $objects = Bootstrap::getObjectManager();
        $builder = $objects->create(SearchCriteriaBuilder::class);
        foreach ($filters as [$field, $type, $value]) {
            $builder->addFilter(
                $objects->create(FilterBuilder::class)->setField($field)->setConditionType($type)->setValue($value)
                    ->create()
            );
        }
        $criteria = $builder->create();
        $criteria->setRequestName($request);
        $result = $objects->get('Iranimij\OpenImport\Ui\DataProvider\GridReporting')->search($criteria);
        $names = array_map(
            static fn ($item): string => (string) ($item->getData('name') ?? $item->getData('profile_name')),
            $result->getItems()
        );
        sort($names);

        return $names;
    }

    public function testKeywordSearchFindsProfilesByName(): void
    {
        $tag = 'Kw' . bin2hex(random_bytes(3));
        $this->profile($tag . ' alpha', ProfileInterface::DIRECTION_IMPORT);
        $this->profile($tag . ' beta', ProfileInterface::DIRECTION_EXPORT, ['destination' => 'download']);
        $this->profile('Other ' . bin2hex(random_bytes(3)), ProfileInterface::DIRECTION_IMPORT);

        self::assertSame([$tag . ' alpha', $tag . ' beta'], $this->search([['fulltext', 'fulltext', $tag]]));
        self::assertSame([$tag . ' beta'], $this->search([['fulltext', 'fulltext', $tag . ' be']]));
        // The data provider the listing XML names uses that Reporting object.
        $provider = Bootstrap::getObjectManager()->create('Iranimij\OpenImport\Ui\DataProvider\GridDataProvider', [
            'name' => 'openimport_profile_listing_data_source',
            'primaryFieldName' => 'profile_id',
            'requestFieldName' => 'id',
        ]);
        $provider->addFilter(
            Bootstrap::getObjectManager()->create(FilterBuilder::class)->setField('fulltext')
                ->setConditionType('fulltext')->setValue($tag)->create()
        );
        self::assertSame(2, (int) $provider->getData()['totalRecords']);
    }

    public function testImportsBySourceExportsByDestination(): void
    {
        $tag = 'Grid ' . bin2hex(random_bytes(2));
        $this->profile($tag . ' import', ProfileInterface::DIRECTION_IMPORT);
        $this->profile($tag . ' download', ProfileInterface::DIRECTION_EXPORT, ['destination' => 'download']);
        // No destination stored: a download, the default.
        $this->profile($tag . ' default', ProfileInterface::DIRECTION_EXPORT, ['columns' => []]);

        self::assertSame([$tag . ' import'], $this->names(SourceOrDestinationFilter::IMPORT_PREFIX . 'upload', $tag));
        self::assertSame(
            [$tag . ' default', $tag . ' download'],
            $this->names(SourceOrDestinationFilter::EXPORT_PREFIX . 'download', $tag)
        );
        self::assertSame([], $this->names(SourceOrDestinationFilter::EXPORT_PREFIX . 'email', $tag));
        self::assertSame([], $this->names(SourceOrDestinationFilter::EXPORT_PREFIX . 'folder', $tag));
        self::assertSame([], $this->names(SourceOrDestinationFilter::IMPORT_PREFIX . 'sftp', $tag));

        $values = array_column(
            Bootstrap::getObjectManager()->get(SourceOrDestinationFilter::class)->toOptionArray(),
            'value'
        );
        self::assertContains('in:sftp', $values);
        self::assertContains('out:email', $values);
    }

    public function testTheGridNeverSendsStoredSecrets(): void
    {
        $tag = 'Sec' . bin2hex(random_bytes(3));
        $profile = Bootstrap::getObjectManager()->get(ProfileInterfaceFactory::class)->create()
            ->setName($tag . ' sftp')
            ->setDirection(ProfileInterface::DIRECTION_IMPORT)
            ->setEntity('openimport_cms_block')
            ->setFormat('csv')
            ->setSourceType('sftp')
            ->setSourceConfig(['host' => 'sftp.example.com', 'username' => 'shop', 'path' => '/in', 'secrets' => ['password' => 'encrypted-value']])
            ->setExportOptions(['secrets' => ['token' => 'encrypted-token']])
            ->setMapping([['target' => 'identifier', 'source' => 'identifier']]);
        Bootstrap::getObjectManager()->get(ProfileRepositoryInterface::class)->save($profile);

        $provider = Bootstrap::getObjectManager()->create('Iranimij\OpenImport\Ui\DataProvider\GridDataProvider', [
            'name' => 'openimport_profile_listing_data_source',
            'primaryFieldName' => 'profile_id',
            'requestFieldName' => 'id',
        ]);
        $provider->addFilter(
            Bootstrap::getObjectManager()->create(FilterBuilder::class)->setField('fulltext')
                ->setConditionType('fulltext')->setValue($tag)->create()
        );
        $items = $provider->getData()['items'];

        self::assertCount(1, $items);
        $json = (string) json_encode($items);
        self::assertStringNotContainsString('encrypted-value', $json);
        self::assertStringNotContainsString('encrypted-token', $json);
        self::assertStringNotContainsString('secrets', $json);
        self::assertStringContainsString('sftp.example.com', (string) $items[0]['source_config']);
    }
}
