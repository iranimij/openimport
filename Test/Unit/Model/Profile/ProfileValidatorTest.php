<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Profile;

use Iranimij\OpenImport\Model\Connection\ConnectionConfig;
use Iranimij\OpenImport\Model\Connection\ConnectionTypes;
use Iranimij\OpenImport\Model\Entity\EntityRegistry;
use Iranimij\OpenImport\Model\Filter\RowFilterCompiler;
use Iranimij\OpenImport\Model\Mapping\MappingCompiler;
use Iranimij\OpenImport\Model\Profile\EntityCodes;
use Iranimij\OpenImport\Model\Chain\ChainLinks;
use Iranimij\OpenImport\Model\Profile\ProfileValidator;
use Iranimij\OpenImport\Model\Transform\TransformPool;
use Iranimij\OpenImport\Test\Unit\Model\ProfileStub;
use Iranimij\OpenImport\Model\Export\Destination\DownloadDestination;
use Iranimij\OpenImport\Model\Export\DestinationPool;
use Iranimij\OpenImport\Model\Writer\CsvWriter;
use Iranimij\OpenImport\Model\Writer\WriterFactory;
use Iranimij\OpenImport\Model\Writer\XlsxWriter;
use Iranimij\OpenImport\Model\Xslt\XsltStep;
use Magento\Framework\Filesystem\Driver\File;
use Iranimij\OpenImport\Test\Unit\Model\Connection\ConnectionStub;
use Iranimij\OpenImport\Test\Unit\Model\Connection\FakeConnectionRepository;
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
                    : ['catalog_product', 'customer', 'openimport_stock_price', 'openimport_category', 'openimport_shipment'];
            }
        };

        return new ProfileValidator(
            new MappingCompiler(new TransformPool()),
            new RowFilterCompiler(),
            new EntityRegistry(),
            $codes,
            new DestinationPool(['download' => new DownloadDestination(), 'folder' => new DownloadDestination(), 'sftp' => new DownloadDestination(), 'email' => new DownloadDestination()]),
            new WriterFactory(new FakeObjectManager(), ['csv' => CsvWriter::class, 'xlsx' => XlsxWriter::class]),
            new ConnectionConfig(
                new FakeConnectionRepository([
                    new ConnectionStub(4, 'Supplier SFTP', 'sftp', ['host' => 'sftp.example.com', 'username' => 'shop']),
                    new ConnectionStub(5, 'Old FTP', 'ftp', ['host' => 'ftp.example.com', 'username' => 'shop']),
                    new ConnectionStub(6, 'Feeds bucket', 's3', ['bucket' => 'feeds']),
                ]),
                new ConnectionTypes(['sftp' => 'SFTP', 'ftp' => 'FTP', 's3' => 'S3'])
            ),
            new XsltStep(new File()),
            new class extends ChainLinks {
                // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
                public function __construct()
                {
                }

                public function profile(int $profileId): ?array
                {
                    return null;
                }
            }
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

    public function testConfigurableProductsNeedTheirColumnsAndAttributes(): void
    {
        $profile = $this->valid();
        $profile['import_options'] = ['configurable' => '1', 'configurable_attributes' => 'color,size'];
        self::assertSame([
            'Map a column to "Parent SKU (configurable products)" in step 4 to build configurable products.',
            'Map the variation attribute "color" in step 4.',
            'Map the variation attribute "size" in step 4.',
        ], $this->validator()->validate(new ProfileStub($profile)));

        $profile['import_options'] = ['configurable' => '1'];
        $profile['mapping'][] = ['target' => 'configurable:parent_sku', 'source' => 'Modell'];
        self::assertSame(
            ['Choose the variation attributes to build configurable products.'],
            $this->validator()->validate(new ProfileStub($profile))
        );

        $profile['import_options'] = ['configurable' => '1', 'configurable_attributes' => 'color'];
        $profile['mapping'][] = ['target' => 'color', 'source' => 'Farbe'];
        self::assertSame([], $this->validator()->validate(new ProfileStub($profile)));

        $profile['import_options'] = ['configurable_attributes' => 'size'];
        self::assertSame([], $this->validator()->validate(new ProfileStub($profile)), 'switched off: not checked');
    }

    public function testQuantityChangesCannotSkipUnchangedRows(): void
    {
        $profile = ['name' => 'Deliveries', 'entity' => 'openimport_stock_price', 'import_options' => ['qty_mode' => 'add']];
        self::assertSame([], $this->validator()->validate(new ProfileStub($profile)));
        self::assertSame(
            ['"Skip unchanged rows" cannot be used with quantities as changes: switch one of them off.'],
            $this->validator()->validate(new ProfileStub($profile + ['delta' => true]))
        );
    }

    public function testUrlKeyPatterns(): void
    {
        $profile = $this->valid();
        $profile['import_options'] = ['url_key_pattern' => 'name-sku'];
        self::assertSame(
            ['Write the fields of the URL key pattern in braces, for example {name}-{sku}.'],
            $this->validator()->validate(new ProfileStub($profile))
        );
        $profile['import_options'] = ['url_key_pattern' => '{name}-{sku}'];
        self::assertSame(
            ['The URL key pattern uses name; map it in step 4.'],
            $this->validator()->validate(new ProfileStub($profile))
        );
        $profile['mapping'][] = ['target' => 'name', 'source' => 'Bezeichnung'];
        self::assertSame([], $this->validator()->validate(new ProfileStub($profile)));
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
            'source_type' => 'box',
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
            'The source type "box" is not one of upload, folder, url, sftp, ftp, s3, google_drive, dropbox, onedrive, rest.',
            'The format "pdf" is not one of auto, csv, xlsx, ods, json, xml.',
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

    public function testCategoriesCannotBeReplaced(): void
    {
        $profile = new ProfileStub([
            'name' => 'Categories',
            'entity' => 'openimport_category',
            'behavior' => 'replace',
            'mapping' => [['target' => 'path', 'source' => 'Pfad']],
        ]);

        self::assertContains(
            'openimport_category cannot be imported with "Replace": use Add/Update.',
            $this->validator()->validate($profile)
        );
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

    public function testASavedConnectionReplacesTheLoginFields(): void
    {
        $validator = $this->validator();
        $base = $this->valid();

        self::assertSame([], $validator->validate(new ProfileStub(['source_config' => ['connection_id' => '4']] + $base)));
        self::assertSame(
            ['The saved connection "Old FTP" is for FTP, not for SFTP.'],
            $validator->validate(new ProfileStub(['source_config' => ['connection_id' => '5']] + $base))
        );
        self::assertSame(
            ['The saved connection 9 no longer exists. Choose another one, or enter the login in the profile.'],
            $validator->validate(new ProfileStub(['source_config' => ['connection_id' => '9']] + $base))
        );
        self::assertSame([], $validator->validate(new ProfileStub(
            ['source_type' => 's3', 'source_config' => ['connection_id' => '6', 'path' => 'feeds']] + $base
        )));
        self::assertSame(
            ['Choose a saved connection for this source.'],
            $validator->validate(new ProfileStub(['source_type' => 's3', 'source_config' => ['path' => 'feeds']] + $base))
        );
        self::assertSame(
            ['The saved connection "Supplier SFTP" is for SFTP, not for S3.'],
            $validator->validate(new ProfileStub(['source_type' => 's3', 'source_config' => ['connection_id' => '4']] + $base))
        );
        $export = ['direction' => 'export', 'entity' => 'catalog_product', 'source_type' => 'upload', 'source_config' => [],
            'format' => 'csv'] + $base;
        self::assertSame([], $validator->validate(new ProfileStub(
            ['export_options' => ['destination' => 'sftp', 'connection_id' => '4']] + $export
        )));
        self::assertSame(
            ['"ftp" is not an export destination.', 'The saved connection "Supplier SFTP" is for SFTP, not for FTP.'],
            $validator->validate(new ProfileStub(['export_options' => ['destination' => 'ftp', 'connection_id' => '4']] + $export))
        );
    }

    public function testExportProfilesNeedAWritableFormatAndAKnownDestination(): void
    {
        $validator = $this->validator();
        $base = ['direction' => 'export', 'entity' => 'catalog_product', 'source_type' => 'upload', 'source_config' => []] + $this->valid();

        self::assertSame([], $validator->validate(new ProfileStub(['format' => 'xlsx', 'export_options' => ['destination' => 'download']] + $base)));
        self::assertSame(
            ['"box" is not an export destination.', 'OpenImport cannot write json files.'],
            $validator->validate(new ProfileStub(['format' => 'json', 'export_options' => ['destination' => 'box']] + $base))
        );
        self::assertSame(
            ['Enter the server folder to write to.'],
            $validator->validate(new ProfileStub(['format' => 'csv', 'export_options' => ['destination' => 'folder']] + $base))
        );
        self::assertSame(
            ['Enter the server (host name) of the destination.', 'Enter the user name for the destination server.'],
            $validator->validate(new ProfileStub(['format' => 'csv', 'export_options' => ['destination' => 'sftp']] + $base))
        );
        self::assertSame(
            ['Enter at least one e-mail address to send the file to.'],
            $validator->validate(new ProfileStub(['format' => 'csv', 'export_options' => ['destination' => 'email']] + $base))
        );
        self::assertSame(
            ['"nope" is not an e-mail address.'],
            $validator->validate(new ProfileStub(['format' => 'csv', 'export_options' => ['destination' => 'email', 'emails' => 'a@example.com, nope']] + $base))
        );
    }

    public function testScheduleNeedsAChangingSourceAndADestination(): void
    {
        $upload = $this->validator()->validate(new ProfileStub(['source_type' => 'upload', 'is_active' => true] + $this->valid()));
        self::assertNotEmpty(array_filter($upload, static fn (string $p): bool => str_contains($p, 'uploaded file cannot run on a schedule')));

        $off = $this->validator()->validate(new ProfileStub(['source_type' => 'upload', 'is_active' => false] + $this->valid()));
        self::assertSame([], array_values(array_filter($off, static fn (string $p): bool => str_contains($p, 'schedule'))));

        $export = $this->validator()->validate(new ProfileStub([
            'direction' => 'export',
            'entity' => 'catalog_product',
            'is_active' => true,
            'export_options' => ['destination' => 'download'],
            'mapping' => [],
            'row_filters' => [],
        ] + $this->valid()));
        self::assertNotEmpty(array_filter($export, static fn (string $p): bool => str_contains($p, 'scheduled export needs a destination')));
    }

    public function testArchiveCompressionAndXsltSettings(): void
    {
        $xsl = '<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform">'
            . '<xsl:template match="/"><r/></xsl:template></xsl:stylesheet>';
        self::assertSame([], $this->validator()->validate(new ProfileStub(
            ['format' => 'xml', 'format_options' => ['archive' => 'none', 'archive_entry' => '*.xml', 'xslt' => $xsl]]
            + $this->valid()
        )));
        $problems = $this->validator()->validate(new ProfileStub(
            ['format_options' => ['archive' => 'maybe', 'xslt' => '<xsl:stylesheet><xsl:include href="a.xsl"/></xsl:stylesheet>']]
            + $this->valid()
        ));
        self::assertContains('The archive setting "maybe" is not one of auto, none.', $problems);
        self::assertContains('The XSLT stylesheet may not include or import other files.', $problems);
        $problems = $this->validator()->validate(new ProfileStub(
            ['format_options' => ['xslt' => 'not xml']] + $this->valid()
        ));
        self::assertStringContainsString('The XSLT stylesheet is not well-formed XML', $problems[0] ?? '');

        $export = $this->validator()->validate(new ProfileStub(
            ['direction' => 'export', 'export_options' => ['compress' => 'rar', 'xslt' => $xsl]] + $this->valid()
        ));
        self::assertSame(['"rar" is not a compression OpenImport writes (zip, gz, tar.gz).'], $export);
    }

    public function testShipmentsCanOnlyBeAdded(): void
    {
        $base = ['entity' => 'openimport_shipment', 'mapping' => [['target' => 'increment_id', 'source' => 'order']]] + $this->valid();
        self::assertSame([], $this->validator()->validate(new ProfileStub(['behavior' => 'add_update'] + $base)));
        self::assertSame([], $this->validator()->validate(new ProfileStub(['behavior' => 'only_add'] + $base)));
        foreach (['only_update', 'delete'] as $behavior) {
            self::assertContains(
                'openimport_shipment can only be added: use Add/Update.',
                $this->validator()->validate(new ProfileStub(['behavior' => $behavior] + $base))
            );
        }
        $replace = $this->validator()->validate(new ProfileStub(['behavior' => 'replace'] + $base));
        self::assertContains('openimport_shipment cannot be imported with "Replace": use Add/Update.', $replace);
    }
}
