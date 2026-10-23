<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Admin;

use Iranimij\OpenImport\Model\Admin\FileSample;
use Iranimij\OpenImport\Model\Archive\Unpacker;
use Iranimij\OpenImport\Model\Storage\TempFolder;
use Iranimij\OpenImport\Model\Xslt\XsltStep;
use Magento\Framework\Filesystem\Driver\File;
use Iranimij\OpenImport\Model\Admin\MappingPreview;
use Iranimij\OpenImport\Model\Entity\EntityRegistry;
use Iranimij\OpenImport\Model\Filter\RowFilterCompiler;
use Iranimij\OpenImport\Model\Mapping\MappingCompiler;
use Iranimij\OpenImport\Model\Pipeline\ProfileCompiler;
use Iranimij\OpenImport\Model\Reader\ReaderException;
use Iranimij\OpenImport\Model\Reader\ReaderFactory;
use Iranimij\OpenImport\Model\Transform\TransformPool;
use Iranimij\OpenImport\Model\Value\FormulaGuard;
use Iranimij\OpenImport\Test\Unit\Model\ProfileStub;
use PHPUnit\Framework\TestCase;

class MappingPreviewTest extends TestCase
{
    private string $file = '';

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/openimport-preview-' . bin2hex(random_bytes(4)) . '.csv';
        $rows = "sku;name;name_de;price;status\n";
        for ($i = 1; $i <= 30; $i++) {
            $rows .= "A-$i;Name $i;" . ($i === 2 ? 'Zwei' : '') . ';' . ($i === 3 ? 'abc' : "$i,5") . ';' . ($i === 4 ? 'off' : 'on') . "\n";
        }
        file_put_contents($this->file, $rows);
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    private function preview(): MappingPreview
    {
        return new MappingPreview(
            new ReaderFactory(),
            new ProfileCompiler(new MappingCompiler(new TransformPool()), new RowFilterCompiler(), new EntityRegistry())
        );
    }

    public function testFirstRowsAsMagentoReceivesThem(): void
    {
        $profile = new ProfileStub([
            'entity' => 'catalog_product',
            'format' => 'auto',
            'row_filters' => ['mode' => 'exclude', 'conditions' => [['column' => 'status', 'operator' => 'equals', 'value' => 'off']]],
            'mapping' => [
                ['target' => 'sku', 'source' => 'sku'],
                ['target' => 'store:de:name', 'source' => 'name_de'],
                ['target' => 'price', 'source' => 'price', 'transforms' => ['decimal_comma']],
            ],
        ]);

        $result = $this->preview()->preview($profile, $this->file, 'feed.csv');

        self::assertSame([], $result['problems']);
        self::assertSame(['sku', 'price', 'store_view_code', 'name'], $result['columns']);
        self::assertSame([2, 3, 3, 4, 6, 7], array_column($result['rows'], 'row'));
        self::assertSame('de', $result['rows'][2]['store']);
        self::assertSame(['sku' => 'A-1', 'price' => '1.5'], $result['rows'][0]['values']);
        self::assertSame([], $result['rows'][3]['values']);
        self::assertStringContainsString('"abc" is not a number', $result['rows'][3]['errors'][0]);
        self::assertSame(['sku' => 'A-5', 'price' => '5.5'], $result['rows'][4]['values']);
    }

    public function testProblemsInsteadOfRows(): void
    {
        $profile = new ProfileStub(['entity' => 'catalog_product', 'mapping' => [['target' => 'sku', 'source' => 'Nope']]]);

        $result = $this->preview()->preview($profile, $this->file, 'feed.csv');

        self::assertSame([], $result['rows']);
        self::assertSame([
            'The mapping does not fit this file:',
            'sku: the file has no column "Nope"',
            'The file\'s columns are: "sku", "name", "name_de", "price", "status"',
        ], $result['problems']);
        self::assertSame(['The profile has no mapping yet.'], $this->preview()->preview(new ProfileStub(), $this->file, 'x.csv')['problems']);
    }

    public function testFileSample(): void
    {
        $tempFolder = new class extends TempFolder {
            public function __construct()
            {
            }

            public function with(string $prefix, callable $work): mixed
            {
                $folder = sys_get_temp_dir() . '/openimport-sample-' . bin2hex(random_bytes(4));
                mkdir($folder);
                try {
                    return $work($folder);
                } finally {
                    array_map('unlink', glob($folder . '/*') ?: []);
                    rmdir($folder);
                }
            }
        };
        $files = new FileSample(new ReaderFactory(), new Unpacker(new File(), $tempFolder), new XsltStep(new File()), $tempFolder);
        $sample = $files->read($this->file, 'feed.csv', 'auto', ['delimiter' => ';'], 3);

        self::assertSame('csv', $sample['format']);
        self::assertSame('', $sample['inner']);
        self::assertSame(['sku', 'name', 'name_de', 'price', 'status'], $sample['columns']);
        self::assertCount(3, $sample['rows']);
        self::assertSame('2', $sample['rows'][0]['__row']);
        self::assertSame([], $sample['sheets']);

        // The same file in a ZIP: read from the archive, the inner file's name reported.
        $zip = sys_get_temp_dir() . '/openimport-sample-' . bin2hex(random_bytes(4)) . '.zip';
        $archive = new \ZipArchive();
        $archive->open($zip, \ZipArchive::CREATE);
        $archive->addFile($this->file, 'in/feed.csv');
        $archive->close();
        try {
            $zipped = $files->read($zip, 'feed.zip', 'auto', ['delimiter' => ';'], 3);
            try {
                $files->read($zip, 'feed.zip', 'auto', ['archive' => 'none', 'delimiter' => ';'], 3);
                self::fail('read as it is');
            } catch (ReaderException $exception) {
                self::assertSame('The file is not an Excel workbook (.xlsx).', $exception->getMessage());
            }
        } finally {
            unlink($zip);
        }
        self::assertSame('feed.csv', $zipped['inner']);
        self::assertSame($sample['rows'], $zipped['rows']);

        // XML through a stylesheet before the reader sees it.
        $xml = sys_get_temp_dir() . '/openimport-sample-' . bin2hex(random_bytes(4)) . '.xml';
        file_put_contents($xml, '<shop><item id="A1"><n>One</n></item><item id="B2"><n>Two</n></item></shop>');
        $xsl = '<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform"><xsl:template match="/">'
            . '<products><xsl:for-each select="shop/item"><product><sku><xsl:value-of select="@id"/></sku>'
            . '<name><xsl:value-of select="n"/></name></product></xsl:for-each></products></xsl:template></xsl:stylesheet>';
        try {
            $transformed = $files->read($xml, 'shop.xml', 'auto', ['xslt' => $xsl], 3);
        } finally {
            unlink($xml);
        }
        self::assertSame('xml', $transformed['format']);
        self::assertSame(['sku', 'name'], $transformed['columns']);
        self::assertSame(['A1', 'B2'], array_column($transformed['rows'], 'sku'));
    }

    public function testFormulaGuard(): void
    {
        $guard = new FormulaGuard();

        self::assertSame("'=SUM(A1)", $guard->protect('=SUM(A1)'));
        self::assertSame("'+49 30 1234", $guard->protect('+49 30 1234'));
        self::assertSame("'@cmd", $guard->protect('@cmd'));
        self::assertSame("'\tx", $guard->protect("\tx"));
        self::assertSame('-5', $guard->protect('-5'));
        self::assertSame('-1.5', $guard->protect('-1.5'));
        self::assertSame("'-1+2", $guard->protect('-1+2'));
        self::assertSame('plain', $guard->protect('plain'));
        self::assertSame('', $guard->protect(''));
    }
}
