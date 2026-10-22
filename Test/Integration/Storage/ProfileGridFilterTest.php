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
use Iranimij\OpenImport\Model\ResourceModel\Profile\Grid\Collection;
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
        $collection = Bootstrap::getObjectManager()->create(Collection::class);
        $collection->addFieldToFilter('source_type', ['eq' => $filter]);
        $collection->addFieldToFilter('main_table.name', ['like' => $tag . '%']);
        $names = array_map(static fn ($item): string => (string) $item->getData('name'), $collection->getItems());
        sort($names);

        return $names;
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

        $values = array_column(
            Bootstrap::getObjectManager()->get(SourceOrDestinationFilter::class)->toOptionArray(),
            'value'
        );
        self::assertContains('in:sftp', $values);
        self::assertContains('out:email', $values);
    }
}
