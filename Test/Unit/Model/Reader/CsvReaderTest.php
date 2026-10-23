<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Reader;

use Iranimij\OpenImport\Model\Reader\CsvReader;
use Iranimij\OpenImport\Model\Reader\ReaderException;
use Iranimij\OpenImport\Model\Reader\ReaderInterface;
use Iranimij\OpenImport\Model\Reader\ReaderOptions;
use PHPUnit\Framework\TestCase;

class CsvReaderTest extends TestCase
{
    use TempFiles;

    public function testCommaFileWithQuotedDelimitersAndLineBreaks(): void
    {
        $reader = new CsvReader($this->tempFile("sku,name,description\nA,\"Shirt, red\",\"Line 1\nLine 2\"\nB,Hat,\"Say \"\"hi\"\"\"\n"));

        self::assertSame(['sku', 'name', 'description'], $reader->columns());
        self::assertSame(
            [
                2 => ['sku' => 'A', 'name' => 'Shirt, red', 'description' => "Line 1\nLine 2"],
                3 => ['sku' => 'B', 'name' => 'Hat', 'description' => 'Say "hi"'],
            ],
            iterator_to_array($reader->rows())
        );
        self::assertSame(['delimiter' => ',', 'encoding' => 'UTF-8'], $reader->detected());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function delimiters(): array
    {
        return [
            'semicolon' => [';', ';'],
            'tab' => ["\t", '\t'],
            'pipe' => ['|', '|'],
        ];
    }

    /**
     * @dataProvider delimiters
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('delimiters')]
    public function testDelimiterIsDetected(string $delimiter, string $shown): void
    {
        $content = implode($delimiter, ['sku', 'name', 'price']) . "\n" . implode($delimiter, ['A', 'x,y', '1,5']) . "\n";
        $reader = new CsvReader($this->tempFile($content));

        self::assertSame($shown, $reader->detected()['delimiter']);
        self::assertSame([2 => ['sku' => 'A', 'name' => 'x,y', 'price' => '1,5']], iterator_to_array($reader->rows()));
    }

    public function testCommasInsideQuotedHeaderCellsDoNotCount(): void
    {
        $reader = new CsvReader($this->tempFile("\"a,b,c\";d\n1;2\n"));

        self::assertSame(';', $reader->detected()['delimiter']);
        self::assertSame(['a,b,c', 'd'], $reader->columns());
    }

    public function testByteOrderMarkIsRemovedFromTheFirstColumn(): void
    {
        $reader = new CsvReader($this->tempFile("\xEF\xBB\xBFsku,qty\nA,1\n"));

        self::assertSame(['sku', 'qty'], $reader->columns());
        self::assertSame([2 => ['sku' => 'A', 'qty' => '1']], iterator_to_array($reader->rows()));
    }

    public function testUtf16WithByteOrderMarkIsConverted(): void
    {
        $content = "\xFF\xFE" . mb_convert_encoding("sku\tname\nA\tGrüße\n", 'UTF-16LE', 'UTF-8');
        $reader = new CsvReader($this->tempFile($content));

        self::assertSame(['delimiter' => '\t', 'encoding' => 'UTF-16LE'], $reader->detected());
        self::assertSame([2 => ['sku' => 'A', 'name' => 'Grüße']], iterator_to_array($reader->rows()));
    }

    public function testUtf16BigEndianIsConverted(): void
    {
        $content = "\xFE\xFF" . mb_convert_encoding("sku,name\nA,Ä\n", 'UTF-16BE', 'UTF-8');
        $reader = new CsvReader($this->tempFile($content));

        self::assertSame('UTF-16BE', $reader->detected()['encoding']);
        self::assertSame([2 => ['sku' => 'A', 'name' => 'Ä']], iterator_to_array($reader->rows()));
    }

    public function testLegacyEncodingIsDetectedAndConverted(): void
    {
        $content = mb_convert_encoding("sku;name\nA;Größe €\n", 'Windows-1252', 'UTF-8');
        $reader = new CsvReader($this->tempFile($content));

        self::assertSame('Windows-1252', $reader->detected()['encoding']);
        self::assertSame([2 => ['sku' => 'A', 'name' => 'Größe €']], iterator_to_array($reader->rows()));
    }

    public function testEncodingCanBeSet(): void
    {
        $content = mb_convert_encoding("sku,name\nA,é\n", 'ISO-8859-1', 'UTF-8');
        $reader = new CsvReader($this->tempFile($content), new ReaderOptions(encoding: 'iso-8859-1'));

        self::assertSame('ISO-8859-1', $reader->detected()['encoding']);
        self::assertSame([2 => ['sku' => 'A', 'name' => 'é']], iterator_to_array($reader->rows()));
    }

    public function testUnknownEncodingIsRefused(): void
    {
        $reader = new CsvReader($this->tempFile("a\n1\n"), new ReaderOptions(encoding: 'EBCDIC'));

        $this->expectException(ReaderException::class);
        $reader->detected();
    }

    public function testEmptyLinesAreSkippedButCounted(): void
    {
        $reader = new CsvReader($this->tempFile("\nsku,qty\n\nA,1\n , \nB,2"));

        self::assertSame(
            [4 => ['sku' => 'A', 'qty' => '1'], 6 => ['sku' => 'B', 'qty' => '2']],
            iterator_to_array($reader->rows())
        );
    }

    public function testShortAndLongRows(): void
    {
        $reader = new CsvReader($this->tempFile("a,b,c\n1\n1,2,3,4\n"));

        self::assertSame(
            [
                2 => ['a' => '1', 'b' => '', 'c' => ''],
                3 => ['a' => '1', 'b' => '2', 'c' => '3', ReaderInterface::EXTRA_CELLS => '4'],
            ],
            iterator_to_array($reader->rows())
        );
    }

    public function testEmptyCellsBeyondTheHeaderAreNotExtra(): void
    {
        $reader = new CsvReader($this->tempFile("a,b\n1,2,,\n"));

        self::assertSame([2 => ['a' => '1', 'b' => '2']], iterator_to_array($reader->rows()));
    }

    public function testCarriageReturnLineBreaks(): void
    {
        $reader = new CsvReader($this->tempFile("sku,name\rA,\"One\"\rB,Two\r"));

        self::assertSame(['sku', 'name'], $reader->columns());
        self::assertSame(
            [2 => ['sku' => 'A', 'name' => 'One'], 3 => ['sku' => 'B', 'name' => 'Two']],
            iterator_to_array($reader->rows())
        );
    }

    public function testFormulaGuardApostropheIsRemoved(): void
    {
        $reader = new CsvReader($this->tempFile("sku,phone,name\nA,'+49 69 1234,'=SUM(1)\nB,'plain,It's\n"));

        self::assertSame(
            [
                2 => ['sku' => 'A', 'phone' => '+49 69 1234', 'name' => '=SUM(1)'],
                3 => ['sku' => 'B', 'phone' => "'plain", 'name' => "It's"],
            ],
            iterator_to_array($reader->rows())
        );
    }

    public function testOverlongLineIsRefused(): void
    {
        $reader = new CsvReader($this->tempFile("sku,name\nA," . str_repeat('x', CsvReader::MAX_LINE_BYTES + 10) . "\n"));

        $this->expectException(ReaderException::class);
        $this->expectExceptionMessage('Line 2 of the file is longer than 8 MB');
        iterator_to_array($reader->rows());
    }

    public function testDuplicateAndEmptyHeaders(): void
    {
        $reader = new CsvReader($this->tempFile("sku,,sku\n1,2,3\n"));

        self::assertSame(['sku', 'column_2', 'sku (2)'], $reader->columns());
    }

    public function testFileWithoutHeader(): void
    {
        $reader = new CsvReader($this->tempFile("A,1\nB,2\n"), new ReaderOptions(hasHeader: false));

        self::assertSame(['column_1', 'column_2'], $reader->columns());
        self::assertSame(
            [1 => ['column_1' => 'A', 'column_2' => '1'], 2 => ['column_1' => 'B', 'column_2' => '2']],
            iterator_to_array($reader->rows())
        );
    }

    public function testDelimiterAndEnclosureCanBeSet(): void
    {
        $reader = new CsvReader($this->tempFile("a;b\n'x;y';2\n"), new ReaderOptions(delimiter: ';', enclosure: "'"));

        self::assertSame([2 => ['a' => 'x;y', 'b' => '2']], iterator_to_array($reader->rows()));
    }

    public function testLongDelimiterIsRefused(): void
    {
        $reader = new CsvReader($this->tempFile("a\n"), new ReaderOptions(delimiter: '::'));

        $this->expectException(ReaderException::class);
        $reader->columns();
    }

    public function testEmptyFile(): void
    {
        $reader = new CsvReader($this->tempFile(''));

        self::assertSame([], $reader->columns());
        self::assertSame([], iterator_to_array($reader->rows()));
    }

    public function testEveryCallToRowsStartsAgain(): void
    {
        $reader = new CsvReader($this->tempFile("a\n1\n2\n"));

        self::assertCount(2, iterator_to_array($reader->rows()));
        self::assertCount(2, iterator_to_array($reader->rows()));
    }

    public function testMissingFileIsRefused(): void
    {
        $this->expectException(ReaderException::class);
        new CsvReader('/nonexistent/file.csv');
    }
}
