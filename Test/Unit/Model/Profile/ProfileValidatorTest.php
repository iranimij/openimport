<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Profile;

use Iranimij\OpenImport\Model\Entity\EntityRegistry;
use Iranimij\OpenImport\Model\Filter\RowFilterCompiler;
use Iranimij\OpenImport\Model\Mapping\MappingCompiler;
use Iranimij\OpenImport\Model\Profile\EntityCodes;
use Iranimij\OpenImport\Model\Profile\ProfileValidator;
use Iranimij\OpenImport\Model\Transform\TransformPool;
use Iranimij\OpenImport\Test\Unit\Model\ProfileStub;
use Iranimij\OpenImport\Model\Export\Destination\DownloadDestination;
use Iranimij\OpenImport\Model\Export\DestinationPool;
use Iranimij\OpenImport\Model\Writer\CsvWriter;
use Iranimij\OpenImport\Model\Writer\WriterFactory;
use Iranimij\OpenImport\Model\Writer\XlsxWriter;
use Iranimij\OpenImport\Test\Unit\Model\FakeObjectManager;
use PHPUnit\Framework\TestCase;

class ProfileValidatorTest extends TestCase
{
    private function validator(): ProfileValidator
    {
        $codes = new class extends EntityCodes {
            // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
            public function __construct()
            {
            }

            public function codes(string $direction): array
            {
                return $direction === 'export'
                    ? ['catalog_product', 'openimport_order']
                    : ['catalog_product', 'customer', 'openimport_stock_price'];
            }
        };

        return new ProfileValidator(
            new MappingCompiler(new TransformPool()),
            new RowFilterCompiler(),
            new EntityRegistry(),
            $codes,
            new DestinationPool(['download' => new DownloadDestination()]),
            new WriterFactory(new FakeObjectManager(), ['csv' => CsvWriter::class, 'xlsx' => XlsxWriter::class])
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function valid(): array
    {
        return [
            'name' => 'Nightly stock',
            'direction' => 'import',
            'entity' => 'catalog_product',
            'source_type' => 'sftp',
            'source_config' => ['host' => 'files.example.com', 'username' => 'shop', 'port' => '2222', 'pick' => 'newest'],
            'format' => 'csv',
            'behavior' => 'only_update',
            'on_error' => 'skip',
            'safety_threshold' => 20,
            'schedule' => '0 2 * * *',
            'notify' => 'always',
            'notify_emails' => 'shop@example.com, ops@example.com',
            'mapping' => [
                ['target' => 'sku', 'source' => 'Artikelnummer', 'transforms' => ['trim']],
                ['target' => 'status', 'source' => 'Aktiv', 'transforms' => ['map'], 'value_map_strict' => true],
            ],
            'value_maps' => ['status' => ['ja' => '1', 'nein' => '2']],
            'row_filters' => ['conditions' => [['column' => 'Bestand', 'operator' => 'not_empty']]],
        ];
    }

    public function testValidProfile(): void
    {
        self::assertSame([], $this->validator()->validate(new ProfileStub($this->valid())));
    }

    public function testDefaultsAreValid(): void
    {
        self::assertSame(
            [],
            $this->validator()->validate(new ProfileStub(['name' => 'x', 'entity' => 'customer']))
        );
    }

    public function testEveryProblemIsListed(): void
    {
        $problems = $this->validator()->validate(new ProfileStub([
            'name' => '  ',
            'direction' => 'import',
            'entity' => 'catalog_category',
            'source_type' => 'dropbox',
            'format' => 'pdf',
            'after_success' => 'archive',
            'notify' => 'sometimes',
            'behavior' => 'merge',
            'on_error' => 'ignore',
            'safety_threshold' => 0,
            'schedule' => 'every night',
            'notify_emails' => 'shop@example.com, not-an-address',
            'mapping' => [['target' => 'sku', 'source' => 'SKU', 'transforms' => ['shout']]],
            'row_filters' => ['mode' => 'maybe'],
        ]));

        self::assertSame([
            'Give the profile a name.',
            'Magento has no import entity "catalog_category".',
            'The source type "dropbox" is not one of upload, folder, url, sftp, ftp.',
            'The format "pdf" is not one of auto, csv, xlsx, json, xml.',
            'The after-success action "archive" is not one of keep, move, delete.',
            'The notification setting "sometimes" is not one of never, failure, always.',
            'The behaviour "merge" is not one of add_update, only_add, only_update, replace, delete.',
            'The error handling "ignore" is not one of stop, skip.',
            'The safety threshold must be between 1 and 100 percent.',
            'A schedule needs 5 fields (minute hour day month weekday), "every night" has 2.',
            '"not-an-address" is not an e-mail address.',
            'sku: Step 1: unknown transform "shout". Transforms: ' . implode(', ', (new TransformPool())->names()) . '.',
            'The filter mode must be "include" or "exclude".',
        ], $problems);
    }

    public function testStockAndPricesOnlyUpdates(): void
    {
        $profile = new ProfileStub([
            'name' => 'Stock',
            'entity' => 'openimport_stock_price',
            'behavior' => 'delete',
            'mapping' => [['target' => 'sku', 'source' => 'SKU'], ['target' => 'name', 'source' => 'Name']],
        ]);

        self::assertSame([
            'openimport_stock_price only updates existing records: use Add/Update or Only update.',
            'name: openimport_stock_price can only set ' . implode(', ', EntityRegistry::STOCK_PRICE_COLUMNS),
        ], $this->validator()->validate($profile));
    }

    public function testExportProfilesSkipImportRules(): void
    {
        $problems = $this->validator()->validate(new ProfileStub([
            'name' => 'Orders',
            'direction' => 'export',
            'entity' => 'openimport_order',
            'behavior' => 'nonsense',
            'mapping' => [['target' => 'x']],
        ]));

        self::assertSame([], $problems);
    }

    public function testOtherProblems(): void
    {
        $problems = $this->validator()->validate(new ProfileStub([
            'name' => str_repeat('n', 256),
            'direction' => 'sideways',
            'entity' => '',
        ]));

        self::assertSame([
            'The name is longer than 255 characters.',
            'The direction "sideways" is not import or export.',
            'Choose what to import or export.',
        ], $problems);
    }

    public function testSourceSettingsAreChecked(): void
    {
        $validator = $this->validator();
        $base = $this->valid();

        self::assertSame(
            ['Enter the server (host name).', 'Enter the user name for the server.', 'The port must be a number between 1 and 65535.'],
            $validator->validate(new ProfileStub(['source_config' => ['port' => '99999']] + $base))
        );
        self::assertSame(
            ['The web address must start with https:// or http://.', 'Enter the user name for the login.'],
            $validator->validate(new ProfileStub(['source_type' => 'url', 'source_config' => ['url' => 'ftp://x', 'auth' => 'basic']] + $base))
        );
        self::assertSame(
            ['The folder may not contain "..".', '"best" is not a way to choose the file.'],
            $validator->validate(new ProfileStub(['source_type' => 'folder', 'source_config' => ['path' => 'a/../b', 'pick' => 'best']] + $base))
        );
        self::assertSame([], $validator->validate(new ProfileStub(['source_type' => 'upload', 'source_config' => []] + $base)));
    }

    public function testExportProfilesNeedAWritableFormatAndAKnownDestination(): void
    {
        $validator = $this->validator();
        $base = ['direction' => 'export', 'entity' => 'catalog_product', 'source_type' => 'upload', 'source_config' => []] + $this->valid();

        self::assertSame([], $validator->validate(new ProfileStub(['format' => 'xlsx', 'export_options' => ['destination' => 'download']] + $base)));
        self::assertSame(
            ['"dropbox" is not an export destination.', 'OpenImport cannot write json files.'],
            $validator->validate(new ProfileStub(['format' => 'json', 'export_options' => ['destination' => 'dropbox']] + $base))
        );
    }
}
