<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Integration\Export;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Api\Data\ProfileInterfaceFactory;
use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Api\ProfileRepositoryInterface;
use Iranimij\OpenImport\Model\Bridge\CoreImporter;
use Iranimij\OpenImport\Model\Bridge\ImportRequest;
use Iranimij\OpenImport\Model\Execution\RunFiles;
use Iranimij\OpenImport\Model\Execution\RunProcessor;
use Iranimij\OpenImport\Model\Execution\RunQueue;
use Iranimij\OpenImport\Model\Export\CoreEntitySource;
use Iranimij\OpenImport\Model\Reader\ReaderFactory;
use Iranimij\OpenImport\Model\Source\MappedSource;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Exports through Magento's own export entities: the file holds what core exports, after OpenImport's row
 * filters and column mapping, in every format; dry runs write but do not deliver.
 */
#[AppArea('adminhtml')]
#[DbIsolation(false)]
#[AppIsolation(true)]
class ExportPipelineTest extends TestCase
{
    private ?string $prefix = ''; // nullable: PHPUnit 9 (2.4.7) nulls typed test properties between tests

    protected function setUp(): void
    {
        $this->prefix = 'OI-EX-' . bin2hex(random_bytes(3));
        $rows = [];
        foreach ([1, 2, 3] as $i) {
            $rows[] = [
                'sku' => $this->prefix . '-' . $i,
                'attribute_set_code' => 'Default',
                'product_type' => 'simple',
                'product_websites' => 'base',
                'name' => 'Export ' . $this->prefix . ' ' . $i,
                'price' => (string) (10 * $i),
                'qty' => (string) $i,
            ];
        }
        $result = Bootstrap::getObjectManager()->get(CoreImporter::class)->run(
            new ImportRequest('catalog_product'),
            new MappedSource(array_keys($rows[0]), static fn (): array => $rows)
        );
        self::assertTrue($result->imported, $result->errors[0]->message ?? 'not imported');
    }

    /**
     * @param array<string, mixed> $data
     * @return ProfileInterface
     */
    private function profile(array $data = []): ProfileInterface
    {
        $profile = Bootstrap::getObjectManager()->get(ProfileInterfaceFactory::class)->create()
            ->setName('Export ' . uniqid())
            ->setDirection(ProfileInterface::DIRECTION_EXPORT)
            ->setEntity('catalog_product')
            ->setFormat($data['format'] ?? 'csv')
            ->setFormatOptions($data['format_options'] ?? [])
            ->setMapping($data['mapping'] ?? [])
            ->setRowFilters($data['row_filters'] ?? [])
            ->setExportOptions(['core_filters' => ['sku' => $this->prefix]] + ($data['export_options'] ?? []));

        return Bootstrap::getObjectManager()->get(ProfileRepositoryInterface::class)->save($profile);
    }

    private function process(ProfileInterface $profile, string $mode = RunInterface::MODE_LIVE): RunInterface
    {
        $run = Bootstrap::getObjectManager()->get(RunQueue::class)->queue($profile, $mode, RunInterface::TRIGGER_CLI);

        return Bootstrap::getObjectManager()->get(RunProcessor::class)->process((int) $run->getRunId());
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function rowsOf(RunInterface $run, string $format): array
    {
        $file = Bootstrap::getObjectManager()->get(RunFiles::class)->absolute((string) $run->getOutputFile());
        $rows = [];
        foreach (Bootstrap::getObjectManager()->get(ReaderFactory::class)->create($file, $format)->rows() as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    public function testProductsAreExportedAsCoreExportsThemWithFiltersAndMapping(): void
    {
        $plain = $this->process($this->profile());
        self::assertSame(RunInterface::STATUS_SUCCESS, $plain->getStatus(), $plain->getMessage());
        self::assertSame(3, $plain->getCount('exported'));
        self::assertStringContainsString('Download it from the run page', $plain->getMessage());
        $rows = $this->rowsOf($plain, 'csv');
        self::assertCount(3, $rows);
        self::assertSame($this->prefix . '-1', $rows[0]['sku']);
        self::assertSame(10.0, (float) $rows[0]['price']);
        self::assertArrayHasKey('product_websites', $rows[0]);

        $mapped = $this->process($this->profile([
            'format' => 'xlsx',
            'row_filters' => ['mode' => 'include', 'match' => 'all', 'conditions' => [['column' => 'price', 'operator' => 'greater_than', 'value' => '15']]],
            'mapping' => [
                ['target' => 'Artikelnummer', 'source' => 'sku'],
                ['target' => 'Preis', 'source' => 'price', 'transforms' => [['name' => 'number', 'args' => ['2', ',']]]],
                ['target' => 'Quelle', 'source' => null, 'static' => 'Shop'],
            ],
            'export_options' => ['file_name' => 'preise-{date}.{ext}'],
        ]));
        self::assertSame(RunInterface::STATUS_SUCCESS, $mapped->getStatus(), $mapped->getMessage());
        self::assertSame(3, $mapped->getCount('read'));
        self::assertSame(1, $mapped->getCount('filtered'));
        self::assertSame(2, $mapped->getCount('exported'));
        self::assertMatchesRegularExpression('#/preise-\d{4}-\d{2}-\d{2}\.xlsx$#', (string) $mapped->getOutputFile());
        $rows = $this->rowsOf($mapped, 'xlsx');
        self::assertSame(['Artikelnummer', 'Preis', 'Quelle'], array_keys($rows[0]));
        self::assertSame([$this->prefix . '-2', '20,00', 'Shop'], array_values($rows[0]));
        self::assertSame('30,00', $rows[1]['Preis']);
    }

    public function testJsonXmlAndDryRun(): void
    {
        $json = $this->process($this->profile(['format' => 'json']));
        self::assertSame(3, count($this->rowsOf($json, 'json')));

        $xml = $this->process($this->profile(['format' => 'xml', 'format_options' => ['root' => 'catalog', 'record' => 'product']]), RunInterface::MODE_DRY_RUN);
        self::assertSame(RunInterface::STATUS_SUCCESS, $xml->getStatus(), $xml->getMessage());
        self::assertStringContainsString('Dry run:', $xml->getMessage());
        self::assertStringContainsString('not delivered', $xml->getMessage());
        self::assertSame(3, $xml->getCount('exported'));
        $rows = $this->rowsOf($xml, 'xml');
        self::assertSame($this->prefix . '-3', $rows[2]['sku']);
    }

    public function testNothingMatching(): void
    {
        $profile = $this->profile(['row_filters' => ['mode' => 'include', 'match' => 'all', 'conditions' => [['column' => 'sku', 'operator' => 'equals', 'value' => 'nope']]]]);
        $run = $this->process($profile);

        self::assertSame(RunInterface::STATUS_SUCCESS, $run->getStatus(), $run->getMessage());
        self::assertStringContainsString('all 3 skipped by the row filters', $run->getMessage());
        self::assertNull($run->getOutputFile());
    }

    public function testEntityColumnsForTheMappingEditor(): void
    {
        $columns = Bootstrap::getObjectManager()->get(CoreEntitySource::class)->columns('catalog_product');

        self::assertContains('sku', $columns);
        self::assertContains('name', $columns);
        self::assertContains('product_websites', $columns);
        self::assertSame([], Bootstrap::getObjectManager()->get(CoreEntitySource::class)->columns('no_such_entity'));
    }
}
