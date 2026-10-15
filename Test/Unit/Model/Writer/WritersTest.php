<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Writer;

use Iranimij\OpenImport\Model\Reader\ReaderFactory;
use Iranimij\OpenImport\Model\Value\FormulaGuard;
use Iranimij\OpenImport\Model\Value\NumberParser;
use Iranimij\OpenImport\Model\Value\NumberText;
use Iranimij\OpenImport\Model\Writer\Cells;
use Iranimij\OpenImport\Model\Writer\CsvWriter;
use Iranimij\OpenImport\Model\Writer\JsonWriter;
use Iranimij\OpenImport\Model\Writer\WriterException;
use Iranimij\OpenImport\Model\Writer\WriterInterface;
use Iranimij\OpenImport\Model\Writer\XlsxWriter;
use Iranimij\OpenImport\Model\Writer\XmlWriter;
use PHPUnit\Framework\TestCase;

class WritersTest extends TestCase
{
    private string $folder = '';

    /** @var array<int, array<string, mixed>> */
    private const ROWS = [
        ['sku' => 'A-001', 'name' => 'Jacke "Regen"', 'price' => '12.50', 'qty' => 3, 'note' => "line1\nline2", 'formula' => '=SUM(A1)'],
        ['sku' => '00123', 'name' => 'Ümlaut, Straße', 'price' => 9.99, 'qty' => null, 'note' => '', 'formula' => '-5'],
    ];

    private const COLUMNS = ['sku', 'name', 'price', 'qty', 'note', 'formula'];

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir() . '/openimport-writers-' . bin2hex(random_bytes(4));
        mkdir($this->folder);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->folder . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->folder);
    }

    private function cells(): Cells
    {
        return new Cells(new FormulaGuard(), new NumberText());
    }

    /**
     * @param WriterInterface $writer
     * @param array<string, mixed> $options
     * @return string path
     */
    private function writeAll(WriterInterface $writer, array $options = []): string
    {
        $path = $this->folder . '/out.' . $writer->extension();
        $writer->open($path, self::COLUMNS, $options);
        foreach (self::ROWS as $row) {
            $writer->write($row);
        }
        $writer->close();

        return $path;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function readBack(string $path, string $format): array
    {
        $reader = (new ReaderFactory())->create($path, $format);
        $rows = [];
        foreach ($reader->rows() as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    public function testCsvRoundTripWithFormulaGuard(): void
    {
        $path = $this->writeAll(new CsvWriter($this->cells()), ['delimiter' => ';', 'bom' => '1']);
        $text = file_get_contents($path);

        self::assertStringStartsWith("\xEF\xBB\xBFsku;name;price;qty;note;formula\n", $text);
        self::assertStringContainsString('"Jacke ""Regen"""', $text);
        self::assertStringContainsString("\"line1\nline2\"", $text);
        self::assertStringContainsString("'=SUM(A1)", $text, 'formula neutralised');
        self::assertStringContainsString(';-5', $text, 'a negative number is not a formula');
        $rows = $this->readBack($path, 'csv');
        self::assertSame('Ümlaut, Straße', $rows[1]['name']);
        self::assertSame('9.99', $rows[1]['price']);
        self::assertSame('00123', $rows[1]['sku']);
    }

    public function testTsvAndWindows1252(): void
    {
        $writer = new CsvWriter($this->cells());
        $path = $this->writeAll($writer, ['delimiter' => 'tab', 'encoding' => 'windows-1252', 'formula_guard' => '0', 'line_ending' => 'crlf']);

        self::assertSame('tsv', $writer->extension());
        $text = file_get_contents($path);
        self::assertStringContainsString("sku\tname\tprice", $text);
        self::assertStringContainsString("\r\n", $text);
        self::assertStringContainsString("\t=SUM(A1)", $text, 'guard off');
        self::assertStringContainsString(mb_convert_encoding('Straße', 'Windows-1252', 'UTF-8'), $text);
        self::assertSame('Ümlaut, Straße', $this->readBack($path, 'csv')[1]['name'], 'the reader detects the encoding');
    }

    public function testCsvWithoutHeaderInIso885915(): void
    {
        $path = $this->writeAll(new CsvWriter($this->cells()), ['has_header' => '0', 'encoding' => 'ISO-8859-15']);
        $text = (string) file_get_contents($path);

        self::assertFalse(str_starts_with($text, 'sku,'), 'no header row');
        self::assertStringContainsString(mb_convert_encoding('Straße', 'ISO-8859-15', 'UTF-8'), $text);
    }

    public function testXlsxRoundTrip(): void
    {
        $writer = new XlsxWriter($this->cells(), new NumberParser());
        $path = $this->writeAll($writer, ['sheet' => 'Preise [2026]']);

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        $workbook = (string) $zip->getFromName('xl/workbook.xml');
        self::assertStringContainsString('name="Preise  2026"', $workbook, 'brackets are not allowed in sheet names');
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        self::assertStringContainsString('<c r="C2"><v>12.50</v></c>', $sheet, 'plain numbers are numeric cells');
        self::assertStringContainsString('<c r="A3" t="inlineStr"><is><t>00123</t></is></c>', $sheet, 'leading zeros stay text');
        self::assertStringContainsString("<t>&apos;=SUM(A1)</t>", $sheet);
        $zip->close();
        self::assertSame(2, $writer->count());

        $rows = $this->readBack($path, 'xlsx');
        self::assertCount(2, $rows);
        self::assertSame('Jacke "Regen"', $rows[0]['name']);
        self::assertSame("line1\nline2", $rows[0]['note']);
        self::assertSame('9.99', $rows[1]['price']);
        self::assertSame('00123', $rows[1]['sku']);
        self::assertFalse(file_exists($path . '.sheet.xml'), 'temporary sheet removed');
    }

    public function testJsonRoundTrip(): void
    {
        $path = $this->writeAll(new JsonWriter($this->cells()), ['root' => 'products', 'pretty' => '1']);
        $data = json_decode((string) file_get_contents($path), true);

        self::assertSame(['products'], array_keys($data));
        self::assertCount(2, $data['products']);
        self::assertSame('Jacke "Regen"', $data['products'][0]['name']);
        self::assertSame('', $data['products'][1]['qty']);
        $rows = $this->readBack($path, 'json');
        self::assertSame('A-001', $rows[0]['sku']);

        $plain = $this->writeAll(new JsonWriter($this->cells()));
        self::assertStringStartsWith('[{"sku":"A-001"', (string) file_get_contents($plain));
    }

    public function testXmlRoundTrip(): void
    {
        $path = $this->writeAll(new XmlWriter($this->cells()), ['root' => 'catalog', 'record' => 'product']);
        $xml = (string) file_get_contents($path);

        self::assertStringContainsString('<catalog>', $xml);
        self::assertStringContainsString('<product>', $xml);
        self::assertStringContainsString('<name>Jacke &quot;Regen&quot;</name>', $xml);
        self::assertStringContainsString("<formula>'=SUM(A1)</formula>", $xml);
        $rows = $this->readBack($path, 'xml');
        self::assertCount(2, $rows);
        self::assertSame('Ümlaut, Straße', $rows[1]['name']);
    }

    public function testXmlElementNamesAreCleaned(): void
    {
        $writer = new XmlWriter($this->cells());
        $path = $this->folder . '/names.xml';
        $writer->open($path, ['Preis (EUR)', '2nd', 'xmlns', 'ok_name']);
        $writer->write(['Preis (EUR)' => '1', '2nd' => '2', 'xmlns' => '3', 'ok_name' => '4']);
        $writer->close();

        $xml = (string) file_get_contents($path);
        self::assertStringContainsString('<Preis_EUR>1</Preis_EUR>', $xml);
        self::assertStringContainsString('<c_2nd>2</c_2nd>', $xml);
        self::assertStringContainsString('<c_xmlns>3</c_xmlns>', $xml);
        self::assertInstanceOf(\SimpleXMLElement::class, simplexml_load_string($xml));
    }

    public function testNoColumnsAndNotOpen(): void
    {
        $writer = new CsvWriter($this->cells());
        try {
            $writer->open($this->folder . '/x.csv', []);
            self::fail('no columns');
        } catch (WriterException $exception) {
            self::assertStringContainsString('no columns', $exception->getMessage());
        }
        $this->expectException(WriterException::class);
        $writer->write(['a' => 1]);
    }
}
