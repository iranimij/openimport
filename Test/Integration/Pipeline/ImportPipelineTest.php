<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Integration\Pipeline;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Api\Data\ProfileInterfaceFactory;
use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Api\ProfileRepositoryInterface;
use Iranimij\OpenImport\Model\Entity\Reader\ProductRecordReader;
use Iranimij\OpenImport\Model\Execution\RunProcessor;
use Iranimij\OpenImport\Model\Execution\RunQueue;
use Iranimij\OpenImport\Model\Storage\ErrorLog;
use Iranimij\OpenImport\Model\Storage\PreviewRecord;
use Iranimij\OpenImport\Model\Storage\PreviewStore;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The whole import pipeline against a real store: supplier file → profile → queued run → processor → Magento.
 *
 * Every test uses its own SKU prefix and profile name; isolation as in the core-bridge test (core import manages
 * its own transactions).
 */
#[AppArea('adminhtml')]
#[DbIsolation(false)]
#[AppIsolation(true)]
class ImportPipelineTest extends TestCase
{
    /**
     * Nullable: the 2.4.7 test framework resets test properties to null after each test.
     *
     * @var string[]|null
     */
    private ?array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files ?? [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * A semicolon CSV the way German suppliers send it.
     *
     * @param array<int, array<string, string>> $rows
     * @return string
     */
    private function csv(array $rows): string
    {
        $file = sys_get_temp_dir() . '/openimport-it-' . bin2hex(random_bytes(5)) . '.csv';
        $handle = fopen($file, 'wb');
        fputcsv($handle, array_keys($rows[0]), ';', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, array_values($row), ';', '"', '');
        }
        fclose($handle);
        $this->files[] = $file;

        return $file;
    }

    /**
     * @param string $prefix
     * @param int $count
     * @param array<string, array<string, string>> $changes sku suffix => column => value
     * @return array<int, array<string, string>>
     */
    private function supplierRows(string $prefix, int $count, array $changes = []): array
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $row = [
                'Artikelnummer' => $prefix . '-' . $i,
                // Names carry the prefix: core derives URL keys from names, and keys must be unique across tests.
                'Bezeichnung' => 'Artikel ' . $i . ' ' . $prefix,
                'Bezeichnung DE' => $i % 2 === 0 ? 'Deutscher Artikel ' . $i . ' ' . $prefix : '',
                'Preis' => number_format(10 + $i, 2, ',', '.'),
                'Bestand' => (string) ($i * 3),
                'Aktiv' => 'ja',
            ];
            $rows[] = array_merge($row, $changes[(string) $i] ?? []);
        }

        return $rows;
    }

    /**
     * @param string $behavior
     * @param array<string, mixed> $settings extra profile data
     * @return ProfileInterface
     */
    private function profile(string $behavior = 'add_update', array $settings = []): ProfileInterface
    {
        $profile = Bootstrap::getObjectManager()->get(ProfileInterfaceFactory::class)->create();
        $profile->setName('Pipeline ' . bin2hex(random_bytes(4)))
            ->setEntity('catalog_product')
            ->setFormat('csv')
            ->setBehavior($behavior)
            ->setOnError($settings['on_error'] ?? 'stop')
            ->setAllowedErrors((int) ($settings['allowed_errors'] ?? 0))
            ->setDelta((bool) ($settings['delta'] ?? false))
            ->setSafetyThreshold($settings['safety_threshold'] ?? null)
            ->setMapping([
                ['target' => 'sku', 'source' => 'Artikelnummer', 'transforms' => ['trim']],
                ['target' => 'name', 'source' => 'Bezeichnung'],
                ['target' => 'store:default:name', 'source' => 'Bezeichnung DE'],
                ['target' => 'price', 'source' => 'Preis', 'transforms' => ['decimal_comma']],
                ['target' => 'qty', 'source' => 'Bestand', 'transforms' => ['number']],
                ['target' => 'is_in_stock', 'source' => null, 'static' => '1'],
                ['target' => 'product_online', 'source' => 'Aktiv', 'transforms' => ['map']],
                ['target' => 'attribute_set_code', 'source' => null, 'static' => 'Default'],
                ['target' => 'product_type', 'source' => null, 'static' => 'simple'],
                ['target' => 'product_websites', 'source' => null, 'static' => 'base'],
                ['target' => 'visibility', 'source' => null, 'static' => 'Catalog, Search'],
            ])
            ->setValueMaps(['product_online' => ['ja' => '1', 'nein' => '0']]);

        return Bootstrap::getObjectManager()->get(ProfileRepositoryInterface::class)->save($profile);
    }

    /**
     * @param ProfileInterface $profile
     * @param string $file
     * @param string $mode
     * @param array<string, mixed> $options
     * @return RunInterface
     */
    private function runFile(ProfileInterface $profile, string $file, string $mode, array $options = []): RunInterface
    {
        $run = Bootstrap::getObjectManager()->get(RunQueue::class)->queue(
            $profile,
            $mode,
            RunInterface::TRIGGER_CLI,
            $options + ['file' => $file, 'original_name' => 'lieferant.csv']
        );

        return Bootstrap::getObjectManager()->get(RunProcessor::class)->process((int) $run->getRunId());
    }

    /**
     * @param string[] $skus
     * @param string[] $columns
     * @param string|null $store
     * @return array<string, array<string, string>>
     */
    private function current(array $skus, array $columns, ?string $store = null): array
    {
        return Bootstrap::getObjectManager()->create(ProductRecordReader::class)->current($skus, $columns, $store);
    }

    public function testSupplierFileIsImported(): void
    {
        $prefix = 'OI-PL-' . bin2hex(random_bytes(3));
        $run = $this->runFile($this->profile(), $this->csv($this->supplierRows($prefix, 4)), RunInterface::MODE_LIVE);

        self::assertSame(RunInterface::STATUS_SUCCESS, $run->getStatus(), $run->getMessage());
        self::assertSame(4, $run->getCount('read'));
        self::assertSame(4, $run->getCount('created'));
        self::assertSame(0, $run->getCount('errors'));
        self::assertNotNull($run->getSourceFile());
        $sku = strtolower($prefix . '-2');
        $values = $this->current([$prefix . '-2'], ['name', 'price', 'qty', 'is_in_stock', 'product_online', 'visibility']);
        self::assertSame(
            ['name' => 'Artikel 2 ' . $prefix, 'price' => '12', 'product_online' => '1', 'visibility' => 'Catalog, Search',
                'qty' => '6', 'is_in_stock' => '1'],
            $values[$sku]
        );
        self::assertSame('Deutscher Artikel 2 ' . $prefix, $this->current([$prefix . '-2'], ['name'], 'default')[$sku]['name']);
        self::assertSame('Artikel 1 ' . $prefix, $this->current([$prefix . '-1'], ['name'], 'default')[strtolower($prefix . '-1')]['name']);
    }

    public function testDryRunPreviewEqualsTheRealChanges(): void
    {
        $prefix = 'OI-PV-' . bin2hex(random_bytes(3));
        $profile = $this->profile();
        $this->runFile($profile, $this->csv($this->supplierRows($prefix, 5)), RunInterface::MODE_LIVE);
        $file = $this->csv($this->supplierRows($prefix, 6, [
            '2' => ['Preis' => '99,90'],
            '3' => ['Bestand' => '0', 'Bezeichnung' => 'Neu 3 ' . $prefix],
        ]));

        $dry = $this->runFile($profile, $file, RunInterface::MODE_DRY_RUN);
        self::assertSame(RunInterface::STATUS_SUCCESS, $dry->getStatus(), $dry->getMessage());
        self::assertSame(1, $dry->getCount('created'));
        self::assertSame(2, $dry->getCount('updated'));
        $preview = [];
        foreach (Bootstrap::getObjectManager()->get(PreviewStore::class)->fetch((int) $dry->getRunId()) as $record) {
            $preview[$record->identifier] = $record;
        }
        self::assertSame(PreviewRecord::CHANGED, $preview[$prefix . '-2']->changeType);
        self::assertSame(['price' => ['12', '99.9']], $preview[$prefix . '-2']->diff);
        self::assertSame(
            ['name' => ['Artikel 3 ' . $prefix, 'Neu 3 ' . $prefix], 'qty' => ['9', '0']],
            $preview[$prefix . '-3']->diff
        );
        self::assertSame(PreviewRecord::NEW, $preview[$prefix . '-6']->changeType);
        self::assertSame(PreviewRecord::UNCHANGED, $preview[$prefix . '-1']->changeType);
        self::assertSame(PreviewRecord::UNCHANGED, $preview[$prefix . '-4 [default]']->changeType);
        // Nothing was written by the dry run.
        self::assertSame([], $this->current([$prefix . '-6'], ['name']));

        $live = $this->runFile($profile, $file, RunInterface::MODE_LIVE);
        self::assertSame(RunInterface::STATUS_SUCCESS, $live->getStatus(), $live->getMessage());
        self::assertSame(1, $live->getCount('created'));
        // Every diff of the preview is what the shop now has.
        foreach ($preview as $identifier => $record) {
            if (str_contains($identifier, '[')) {
                continue;
            }
            $now = $this->current([$identifier], array_keys($record->diff))[strtolower($identifier)] ?? [];
            foreach ($record->diff as $field => [$before, $after]) {
                if (!array_key_exists($field, $now)) {
                    continue;
                }
                self::assertSame($after, $now[$field], $identifier . ' ' . $field);
            }
        }
    }

    public function testDeltaSkipsUnchangedRows(): void
    {
        $prefix = 'OI-DL-' . bin2hex(random_bytes(3));
        $profile = $this->profile('add_update', ['delta' => true]);

        $first = $this->runFile($profile, $this->csv($this->supplierRows($prefix, 6)), RunInterface::MODE_LIVE);
        self::assertSame(6, $first->getCount('created'), $first->getMessage());

        $second = $this->runFile($profile, $this->csv($this->supplierRows($prefix, 6)), RunInterface::MODE_LIVE);
        self::assertSame(RunInterface::STATUS_SUCCESS, $second->getStatus());
        self::assertSame(6, $second->getCount('unchanged'));
        self::assertStringStartsWith('Nothing to import', $second->getMessage());

        $third = $this->runFile(
            $profile,
            $this->csv($this->supplierRows($prefix, 6, ['3' => ['Bestand' => '1']])),
            RunInterface::MODE_LIVE
        );
        self::assertSame(5, $third->getCount('unchanged'));
        self::assertSame(1, $third->getCount('updated'), $third->getMessage());
        self::assertSame('1', $this->current([$prefix . '-3'], ['qty'])[strtolower($prefix . '-3')]['qty']);

        $full = $this->runFile($profile, $this->csv($this->supplierRows($prefix, 6)), RunInterface::MODE_LIVE, ['full' => true]);
        self::assertSame(0, $full->getCount('unchanged'));
    }

    public function testOnlyAddAndOnlyUpdate(): void
    {
        $prefix = 'OI-BH-' . bin2hex(random_bytes(3));
        $this->runFile($this->profile(), $this->csv($this->supplierRows($prefix, 2)), RunInterface::MODE_LIVE);
        $file = $this->csv($this->supplierRows($prefix, 4, ['1' => ['Bezeichnung' => 'Changed ' . $prefix]]));

        $add = $this->runFile($this->profile('only_add'), $file, RunInterface::MODE_LIVE);
        self::assertSame(2, $add->getCount('skipped'), $add->getMessage());
        self::assertSame(2, $add->getCount('created'));
        self::assertSame('Artikel 1 ' . $prefix, $this->current([$prefix . '-1'], ['name'])[strtolower($prefix . '-1')]['name']);

        $update = $this->runFile(
            $this->profile('only_update'),
            $this->csv($this->supplierRows($prefix, 5, ['1' => ['Bezeichnung' => 'Changed ' . $prefix]])),
            RunInterface::MODE_LIVE
        );
        self::assertSame(1, $update->getCount('skipped'), $update->getMessage());
        self::assertSame(0, $update->getCount('created'));
        self::assertSame('Changed ' . $prefix, $this->current([$prefix . '-1'], ['name'])[strtolower($prefix . '-1')]['name']);
    }

    public function testErrorsPointAtTheSupplierRows(): void
    {
        $prefix = 'OI-ER-' . bin2hex(random_bytes(3));
        $rows = $this->supplierRows($prefix, 5, ['2' => ['Preis' => 'teuer'], '4' => ['Aktiv' => 'vielleicht']]);
        $profile = $this->profile('add_update', ['on_error' => 'skip', 'allowed_errors' => 10]);
        $mapping = $profile->getMapping();
        $mapping[6]['value_map_strict'] = true;
        $profile = Bootstrap::getObjectManager()->get(ProfileRepositoryInterface::class)->save($profile->setMapping($mapping));

        $run = $this->runFile($profile, $this->csv($rows), RunInterface::MODE_LIVE);

        self::assertSame(RunInterface::STATUS_PARTIAL, $run->getStatus(), $run->getMessage());
        self::assertSame(3, $run->getCount('created'));
        $errors = Bootstrap::getObjectManager()->get(ErrorLog::class)->fetch((int) $run->getRunId());
        self::assertCount(2, $errors);
        self::assertSame([3, 5], [$errors[0]->sourceRow, $errors[1]->sourceRow]);
        self::assertSame('price', $errors[0]->field);
        self::assertSame('decimal_comma: "teuer" is not a number.', $errors[0]->message);
        self::assertSame($prefix . '-2', $errors[0]->identifier);
        self::assertSame('teuer', $errors[0]->sourceData['Preis'] ?? null);
        self::assertSame('The value "vielleicht" is not in the value map.', $errors[1]->message);

        $stop = $this->runFile($this->profile(), $this->csv($rows), RunInterface::MODE_LIVE);
        self::assertSame(RunInterface::STATUS_FAILED, $stop->getStatus());
        self::assertStringContainsString('Nothing was imported', $stop->getMessage());
    }

    /**
     * A run stores English; a German admin reads the summary and the errors in German (Notion review 5, M3).
     */
    public function testRunMessagesAreShownInTheAdminsLanguage(): void
    {
        $prefix = 'OI-DE-' . bin2hex(random_bytes(3));
        $rows = $this->supplierRows($prefix, 3, ['2' => ['Preis' => 'teuer'], '3' => ['Aktiv' => 'vielleicht']]);
        $profile = $this->profile('add_update', ['on_error' => 'skip', 'allowed_errors' => 10]);
        $mapping = $profile->getMapping();
        $mapping[6]['value_map_strict'] = true;
        $profile = Bootstrap::getObjectManager()->get(ProfileRepositoryInterface::class)->save($profile->setMapping($mapping));
        $run = $this->runFile($profile, $this->csv($rows), RunInterface::MODE_DRY_RUN);
        $errors = Bootstrap::getObjectManager()->get(ErrorLog::class)->fetch((int) $run->getRunId());
        self::assertStringStartsWith('Dry run: ', $run->getMessage());

        $translate = Bootstrap::getObjectManager()->get(\Magento\Framework\TranslateInterface::class);
        $locale = (string) $translate->getLocale();
        try {
            $translate->setLocale('de_DE')->loadData('adminhtml', true);
            $text = Bootstrap::getObjectManager()->create(\Iranimij\OpenImport\Model\I18n\StoredText::class);
            $german = $text->translate($run->getMessage());
            self::assertStringStartsWith('Probelauf: ', $german, $german);
            self::assertStringContainsString('Zeile(n) haben Fehler und werden ausgelassen', $german, $german);
            self::assertSame('decimal_comma: „teuer“ ist keine Zahl.', $text->translate($errors[0]->message));
            self::assertSame('Der Wert „vielleicht“ steht nicht in der Werte-Zuordnung.', $text->translate($errors[1]->message));
        } finally {
            $translate->setLocale($locale)->loadData('adminhtml', true);
        }
        // English admins read what was stored.
        $english = Bootstrap::getObjectManager()->create(\Iranimij\OpenImport\Model\I18n\StoredText::class);
        self::assertSame($run->getMessage(), $english->translate($run->getMessage()));
    }

    public function testCoreErrorsAreMappedBackToSourceRows(): void
    {
        $prefix = 'OI-CE-' . bin2hex(random_bytes(3));
        $profile = $this->profile('add_update', ['on_error' => 'skip', 'allowed_errors' => 10]);
        $mapping = $profile->getMapping();
        $mapping[7] = ['target' => 'attribute_set_code', 'source' => 'Set'];
        $profile = Bootstrap::getObjectManager()->get(ProfileRepositoryInterface::class)->save($profile->setMapping($mapping));
        $rows = $this->supplierRows($prefix, 4);
        foreach ($rows as $index => $row) {
            $rows[$index]['Set'] = $index === 2 ? 'NoSuchSet' : 'Default';
        }

        $run = $this->runFile($profile, $this->csv($rows), RunInterface::MODE_LIVE);

        self::assertSame(RunInterface::STATUS_PARTIAL, $run->getStatus(), $run->getMessage());
        $errors = Bootstrap::getObjectManager()->get(ErrorLog::class)->fetch((int) $run->getRunId());
        self::assertNotEmpty($errors);
        self::assertSame(4, $errors[0]->sourceRow);
        self::assertSame($prefix . '-3', $errors[0]->identifier);
        self::assertSame(3, $run->getCount('created'));
    }

    public function testDeleteAboveTheThresholdNeedsConfirmation(): void
    {
        $prefix = 'OI-DE-' . bin2hex(random_bytes(3));
        $this->runFile($this->profile(), $this->csv($this->supplierRows($prefix, 3)), RunInterface::MODE_LIVE);
        $profile = $this->profile('delete', ['safety_threshold' => 1]);
        $file = $this->csv($this->supplierRows($prefix, 3));

        $dry = $this->runFile($profile, $file, RunInterface::MODE_DRY_RUN);
        self::assertSame(3, $dry->getCount('deleted'), $dry->getMessage());
        self::assertStringContainsString('A live run will ask for confirmation', $dry->getMessage());

        $blocked = $this->runFile($profile, $file, RunInterface::MODE_LIVE);
        self::assertSame(RunInterface::STATUS_NEEDS_CONFIRMATION, $blocked->getStatus());
        self::assertStringContainsString('Type DELETE to confirm', $blocked->getMessage());
        self::assertCount(1, $this->current([$prefix . '-1'], ['name']));

        $confirmed = $this->runFile($profile, $file, RunInterface::MODE_LIVE, ['confirmed' => true]);
        self::assertSame(RunInterface::STATUS_SUCCESS, $confirmed->getStatus(), $confirmed->getMessage());
        self::assertSame(3, $confirmed->getCount('deleted'));
        self::assertSame([], $this->current([$prefix . '-1'], ['name']));
    }

    public function testStockAndPricesUpdateOnlyExistingProducts(): void
    {
        $prefix = 'OI-SP-' . bin2hex(random_bytes(3));
        $this->runFile($this->profile(), $this->csv($this->supplierRows($prefix, 2)), RunInterface::MODE_LIVE);
        $profile = Bootstrap::getObjectManager()->get(ProfileInterfaceFactory::class)->create()
            ->setName('Stock ' . $prefix)
            ->setEntity('openimport_stock_price')
            ->setFormat('csv')
            ->setMapping([
                ['target' => 'sku', 'source' => 'Artikelnummer'],
                ['target' => 'qty', 'source' => 'Bestand'],
                ['target' => 'price', 'source' => 'Preis', 'transforms' => ['decimal_comma']],
                ['target' => 'special_price', 'source' => 'Sonderpreis', 'transforms' => ['decimal_comma']],
            ]);
        $profile = Bootstrap::getObjectManager()->get(ProfileRepositoryInterface::class)->save($profile);
        $file = $this->csv([
            ['Artikelnummer' => $prefix . '-1', 'Bestand' => '0', 'Preis' => '9,99', 'Sonderpreis' => '7,49'],
            ['Artikelnummer' => $prefix . '-2', 'Bestand' => '42', 'Preis' => '', 'Sonderpreis' => ''],
            ['Artikelnummer' => $prefix . '-unknown', 'Bestand' => '5', 'Preis' => '1,00', 'Sonderpreis' => ''],
        ]);

        $dry = $this->runFile($profile, $file, RunInterface::MODE_DRY_RUN);
        self::assertSame(RunInterface::STATUS_SUCCESS, $dry->getStatus(), $dry->getMessage());
        self::assertSame(1, $dry->getCount('skipped'));
        self::assertSame(2, $dry->getCount('updated'));

        $run = $this->runFile($profile, $file, RunInterface::MODE_LIVE);
        self::assertSame(RunInterface::STATUS_SUCCESS, $run->getStatus(), $run->getMessage());
        self::assertSame(1, $run->getCount('skipped'));
        self::assertSame(0, $run->getCount('created'));
        $values = $this->current([$prefix . '-1', $prefix . '-2'], ['qty', 'price', 'special_price', 'name']);
        self::assertSame(
            ['price' => '9.99', 'special_price' => '7.49', 'name' => 'Artikel 1 ' . $prefix, 'qty' => '0'],
            $values[strtolower($prefix . '-1')]
        );
        self::assertSame('42', $values[strtolower($prefix . '-2')]['qty']);
        self::assertSame('12', $values[strtolower($prefix . '-2')]['price']);
        self::assertSame([], $this->current([$prefix . '-unknown'], ['name']));
    }

    public function testFileThatDoesNotFitTheMapping(): void
    {
        $run = $this->runFile($this->profile(), $this->csv([['SKU' => 'x', 'Name' => 'y']]), RunInterface::MODE_DRY_RUN);

        self::assertSame(RunInterface::STATUS_FAILED, $run->getStatus());
        self::assertStringContainsString('the file has no column "Artikelnummer"', $run->getMessage());
    }

    /**
     * Replace deletes each product and creates it again; Magento builds URL keys from names only for unknown SKUs,
     * so existing products of a file without url_key used to collide ("Url key: '' was already generated").
     */
    public function testReplaceKeepsTheUrlKeysOfExistingProducts(): void
    {
        $prefix = 'OI-RPL-' . bin2hex(random_bytes(3));
        $rows = $this->supplierRows($prefix, 2);
        $created = $this->runFile($this->profile(), $this->csv($rows), RunInterface::MODE_LIVE);
        self::assertSame(RunInterface::STATUS_SUCCESS, $created->getStatus(), (string) $created->getMessage());
        $before = $this->current([$prefix . '-1', $prefix . '-2'], ['url_key']);

        $replace = $this->profile('replace', ['safety_threshold' => 100]);
        $dry = $this->runFile($replace, $this->csv($rows), RunInterface::MODE_DRY_RUN);
        self::assertSame(RunInterface::STATUS_SUCCESS, $dry->getStatus(), (string) $dry->getMessage());
        self::assertStringNotContainsString('Url key', (string) $dry->getMessage());

        $live = $this->runFile($replace, $this->csv($rows), RunInterface::MODE_LIVE);
        self::assertSame(RunInterface::STATUS_SUCCESS, $live->getStatus(), (string) $live->getMessage());
        self::assertSame($before, $this->current([$prefix . '-1', $prefix . '-2'], ['url_key']));
    }
}
