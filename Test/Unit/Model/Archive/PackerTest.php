<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Archive;

use Iranimij\OpenImport\Model\Archive\ArchiveException;
use Iranimij\OpenImport\Model\Archive\PackedWriter;
use Iranimij\OpenImport\Model\Archive\Packer;
use Iranimij\OpenImport\Model\Archive\Unpacker;
use Iranimij\OpenImport\Model\Storage\TempFolder;
use Iranimij\OpenImport\Model\Value\FormulaGuard;
use Iranimij\OpenImport\Model\Value\NumberText;
use Iranimij\OpenImport\Model\Writer\Cells;
use Iranimij\OpenImport\Model\Writer\CsvWriter;
use Magento\Framework\Filesystem\Driver\File;
use PHPUnit\Framework\TestCase;

/**
 * Round trips: what the Packer writes, the Unpacker (and PHP's own zip and zlib) read back.
 */
class PackerTest extends TestCase
{
    use ArchiveFiles;

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function kinds(): array
    {
        return [
            'zip' => ['zip', 'application/zip', 'products.csv.zip'],
            'gzip' => ['gz', 'application/gzip', 'products.csv.gz'],
            'tar.gz' => ['tar.gz', 'application/gzip', 'products.csv.tar.gz'],
        ];
    }

    /**
     * @param string $kind
     * @param string $type
     * @param string $name
     * @dataProvider kinds
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('kinds')]
    public function testPackedFileUnpacksToTheSameContent(string $kind, string $type, string $name): void
    {
        $dir = $this->tempDir();
        $content = "sku,name\nA,\"Zwölf, Übungen\"\n" . str_repeat("B,x\n", 3000);
        file_put_contents($dir . '/products.csv', $content);
        $packer = new Packer(new File());

        $packed = $packer->pack($dir . '/products.csv', $kind);

        self::assertSame($dir . '/' . $name, $packed);
        self::assertFileDoesNotExist($dir . '/products.csv');
        self::assertLessThan(strlen($content) / 4, filesize($packed), 'compressed');
        self::assertSame($type, $packer->contentType($kind));
        $unpacker = new Unpacker(new File(), new class extends TempFolder {
            public function __construct()
            {
            }
        });
        self::assertSame($kind, $unpacker->kind($packed));
        $back = $unpacker->unpack($packed, $name, $this->tempDir());
        self::assertSame('products.csv', $back->name);
        self::assertSame($content, file_get_contents($back->path));
    }

    public function testTarGzIsAStandardTar(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/x.csv', "a\n");
        $packed = (new Packer(new File()))->pack($dir . '/x.csv', 'tar.gz');
        $tar = (string) gzdecode((string) file_get_contents($packed));
        self::assertSame(512 + 512 + 1024, strlen($tar), 'header, one data block, two end blocks');
        self::assertSame('x.csv', rtrim(substr($tar, 0, 100), "\0"));
        self::assertSame('ustar', substr($tar, 257, 5));
        self::assertSame('00000000002', substr($tar, 124, 11), 'size in octal');
        $header = substr_replace(substr($tar, 0, 512), str_repeat(' ', 8), 148, 8);
        self::assertSame(array_sum(array_map('ord', str_split($header))), octdec(substr($tar, 148, 6)), 'checksum');
    }

    public function testUnknownKindAndMissingFile(): void
    {
        $packer = new Packer(new File());
        try {
            $packer->pack($this->temp('x', 'csv'), '7z');
            self::fail('no exception');
        } catch (ArchiveException $exception) {
            self::assertSame('"7z" is no archive kind OpenImport writes.', $exception->getMessage());
        }
        $this->expectException(ArchiveException::class);
        $this->expectExceptionMessage('"missing.csv" could not be packed');
        $packer->pack($this->tempDir() . '/missing.csv', 'gz');
    }

    public function testPackedWriterReportsTheArchive(): void
    {
        $writer = new PackedWriter(new CsvWriter(new Cells(new FormulaGuard(), new NumberText())), 'zip', 'application/zip');
        self::assertSame('csv.zip', $writer->extension());
        self::assertSame('application/zip', $writer->contentType());
    }
}
