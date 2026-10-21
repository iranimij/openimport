<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Reader;

use Iranimij\OpenImport\Model\Reader\OdsReader;
use Iranimij\OpenImport\Model\Reader\ReaderException;
use Iranimij\OpenImport\Model\Reader\ReaderFactory;
use Iranimij\OpenImport\Model\Reader\ReaderOptions;
use PHPUnit\Framework\TestCase;

/**
 * ODS files as LibreOffice Calc writes them: repeated columns and rows for the unused area, typed values,
 * several sheets, annotations, and the safety checks.
 */
class OdsReaderTest extends TestCase
{
    use TempFiles;

    private const NS = 'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
        . ' xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"'
        . ' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"';

    /**
     * @param array<string, string> $tables sheet name => table-row XML
     */
    private function ods(array $tables, string $mimetype = 'application/vnd.oasis.opendocument.spreadsheet'): string
    {
        $file = $this->tempFile('', 'ods');
        $zip = new \ZipArchive();
        $zip->open($file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($mimetype !== '') {
            $zip->addFromString('mimetype', $mimetype);
            $zip->setCompressionName('mimetype', \ZipArchive::CM_STORE);
        }
        $body = '';
        foreach ($tables as $name => $rows) {
            $body .= '<table:table table:name="' . htmlspecialchars($name) . '">'
                . '<table:table-column table:number-columns-repeated="1024"/>' . $rows . '</table:table>';
        }
        $zip->addFromString(
            'content.xml',
            '<?xml version="1.0" encoding="UTF-8"?><office:document-content ' . self::NS . ' office:version="1.2">'
            . '<office:body><office:spreadsheet>' . $body . '</office:spreadsheet></office:body></office:document-content>'
        );
        $zip->close();

        return $file;
    }

    private function cell(string $text, string $type = 'string', string $extra = ''): string
    {
        return '<table:table-cell office:value-type="' . $type . '"' . $extra . '><text:p>' . $text . '</text:p></table:table-cell>';
    }

    public function testCalcLayoutWithRepeatsTypesAndAnnotations(): void
    {
        $file = $this->ods(['Sheet1' =>
            '<table:table-row>' . $this->cell('sku') . $this->cell('price') . $this->cell('date') . $this->cell('note')
                . $this->cell('ok') . '<table:table-cell table:number-columns-repeated="1019"/></table:table-row>'
            . '<table:table-row>' . $this->cell('A-1') . $this->cell('12,50 €', 'float', ' office:value="12.5"')
                . $this->cell('21.10.26', 'date', ' office:date-value="2026-10-21"')
                . '<table:table-cell office:value-type="string"><office:annotation><text:p>ignored</text:p></office:annotation>'
                . '<text:p>two<text:s text:c="3"/>words</text:p><text:p>second line</text:p></table:table-cell>'
                . $this->cell('TRUE', 'boolean', ' office:boolean-value="true"') . '</table:table-row>'
            . '<table:table-row table:number-rows-repeated="2">' . $this->cell('B-2')
                . '<table:table-cell table:number-columns-repeated="2"/>' . $this->cell('x') . '</table:table-row>'
            . '<table:table-row table:number-rows-repeated="1048000"><table:table-cell table:number-columns-repeated="1024"/></table:table-row>'
            . '<table:table-row>' . $this->cell('C-3') . $this->cell('3', 'float', ' office:value="3"') . '</table:table-row>'
        ]);
        $reader = new OdsReader($file);

        self::assertSame(['sku', 'price', 'date', 'note', 'ok'], $reader->columns());
        $rows = iterator_to_array($reader->rows());
        self::assertSame([2, 3, 4, 1048005], array_keys($rows), 'repeated rows expanded, empty rows counted');
        self::assertSame(['sku' => 'A-1', 'price' => '12.5', 'date' => '2026-10-21', 'note' => "two   words\nsecond line", 'ok' => '1'], $rows[2]);
        self::assertSame(['sku' => 'B-2', 'price' => '', 'date' => '', 'note' => 'x', 'ok' => ''], $rows[3]);
        self::assertSame($rows[3], $rows[4]);
        self::assertSame('C-3', $rows[1048005]['sku']);
        self::assertSame(['sheet' => 'Sheet1'], $reader->detected());
    }

    public function testSheetsByNameAndPosition(): void
    {
        $file = $this->ods([
            'Prices' => '<table:table-row>' . $this->cell('a') . '</table:table-row><table:table-row>' . $this->cell('1') . '</table:table-row>',
            'Stock' => '<table:table-row>' . $this->cell('b') . '</table:table-row><table:table-row>' . $this->cell('2') . '</table:table-row>',
        ]);
        self::assertSame(['Prices', 'Stock'], (new OdsReader($file))->sheetNames());
        self::assertSame(['b'], (new OdsReader($file, new ReaderOptions(sheet: 'stock')))->columns());
        self::assertSame(['b'], (new OdsReader($file, new ReaderOptions(sheet: '2')))->columns());
        self::assertSame(['a'], (new OdsReader($file))->columns());
        $this->expectException(ReaderException::class);
        $this->expectExceptionMessage('The sheet "Other" does not exist. Sheets: Prices, Stock.');
        (new OdsReader($file, new ReaderOptions(sheet: 'Other')))->columns();
    }

    public function testNoHeaderAndTimeValues(): void
    {
        $file = $this->ods(['S' => '<table:table-row>' . $this->cell('x')
            . $this->cell('14:30', 'time', ' office:time-value="PT14H30M00S"')
            . $this->cell('now', 'date', ' office:date-value="2026-10-21T08:05:00"') . '</table:table-row>']);
        $rows = iterator_to_array((new OdsReader($file, new ReaderOptions(hasHeader: false)))->rows());
        self::assertSame(['column_1' => 'x', 'column_2' => '14:30:00', 'column_3' => '2026-10-21 08:05:00'], $rows[1]);
    }

    public function testFactoryDetectsOdsAndRefusesOtherDocuments(): void
    {
        $factory = new ReaderFactory();
        $file = $this->ods(['S' => '<table:table-row>' . $this->cell('a') . '</table:table-row>']);
        self::assertSame('ods', $factory->detect($file));
        self::assertSame('ods', $factory->detect($file, 'blob'));
        self::assertInstanceOf(OdsReader::class, $factory->create($file));
        $text = $this->ods(['S' => ''], 'application/vnd.oasis.opendocument.text');
        try {
            (new OdsReader($text))->columns();
            self::fail('a text document is no spreadsheet');
        } catch (ReaderException $exception) {
            self::assertSame('The file is an OpenDocument application/vnd.oasis.opendocument.text, not a spreadsheet.', $exception->getMessage());
        }
        $this->expectException(ReaderException::class);
        $this->expectExceptionMessage('The file is not an OpenDocument spreadsheet (.ods).');
        (new OdsReader($this->tempFile('not a zip', 'ods')))->columns();
    }

    public function testDoctypeIsRefused(): void
    {
        $file = $this->tempFile('', 'ods');
        $zip = new \ZipArchive();
        $zip->open($file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('mimetype', 'application/vnd.oasis.opendocument.spreadsheet');
        $zip->addFromString('content.xml', '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]>'
            . '<office:document-content ' . self::NS . '><office:body><office:spreadsheet><table:table table:name="S">'
            . '<table:table-row><table:table-cell><text:p>&e;</text:p></table:table-cell></table:table-row>'
            . '</table:table></office:spreadsheet></office:body></office:document-content>');
        $zip->close();
        $this->expectException(ReaderException::class);
        $this->expectExceptionMessage('DOCTYPE');
        (new OdsReader($file))->columns();
    }
}
