<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Archive;

use Iranimij\OpenImport\Model\Archive\ArchiveException;
use Iranimij\OpenImport\Model\Archive\Unpacker;
use Iranimij\OpenImport\Model\Storage\TempFolder;
use Magento\Framework\Filesystem\Driver\File;
use PHPUnit\Framework\TestCase;

/**
 * Real archives in a temporary folder (ZipArchive, zlib and hand-built tar files); no mocks.
 */
class UnpackerTest extends TestCase
{
    use ArchiveFiles;

    private function unpacker(int $maxBytes = 1073741824, int $maxEntries = 10000): Unpacker
    {
        return new Unpacker(new File(), $this->tempFolder(), $maxBytes, $maxEntries);
    }

    /**
     * A temp folder helper over the test's own temporary folder (no Magento filesystem here).
     */
    private function tempFolder(): TempFolder
    {
        $base = $this->tempDir();

        return new class ($base) extends TempFolder {
            public function __construct(private readonly string $base)
            {
            }

            public function with(string $prefix, callable $work): mixed
            {
                $folder = $this->base . '/' . $prefix . '-x';
                mkdir($folder);
                try {
                    return $work($folder);
                } finally {
                    array_map('unlink', glob($folder . '/*') ?: []);
                    rmdir($folder);
                }
            }
        };
    }

    public function testKindFromNameAndFromBytes(): void
    {
        $unpacker = $this->unpacker();
        $zip = $this->zip(['a.csv' => "sku\n"]);
        self::assertSame('zip', $unpacker->kind($zip));
        self::assertSame('zip', $unpacker->kind($zip, 'feed.ZIP'));
        self::assertSame('zip', $unpacker->kind($zip, 'download.dat'), 'first bytes');
        self::assertSame('tar.gz', $unpacker->kind($zip, 'feed.tar.gz'));
        self::assertSame('tar.gz', $unpacker->kind($zip, 'feed.tgz'));
        self::assertSame('gz', $unpacker->kind($this->gz('x'), 'noname'));
        self::assertSame('tar', $unpacker->kind($this->tar(['a.csv' => 'x']), 'blob'));
        self::assertSame('', $unpacker->kind($this->temp('sku,name', 'csv')));
        self::assertSame('', $unpacker->kind($zip, 'book.xlsx'), 'an Excel file is a ZIP too, never unpacked');
        $office = $this->zip(['[Content_Types].xml' => '<Types/>', 'xl/workbook.xml' => '<w/>']);
        self::assertSame('', $unpacker->kind($office, 'download.bin'), 'Excel by content');
        self::assertSame('', $unpacker->kindFor($zip, 'feed.zip', ['archive' => 'none']));
        self::assertSame('zip', $unpacker->kindFor($zip, 'feed.zip', []));
    }

    public function testZipTakesTheFirstReadableFileByName(): void
    {
        $zip = $this->zip([
            'readme.md' => 'hi',
            'data/b-products.csv' => "sku\nB\n",
            'data/a-products.csv' => "sku\nA\n",
            '__MACOSX/._a.csv' => 'junk',
            '.hidden.csv' => 'x',
            'folder/' => '',
        ]);
        $unpacked = $this->unpacker()->unpack($zip, 'feed.zip', $this->tempDir());
        self::assertSame('a-products.csv', $unpacked->name);
        self::assertSame("sku\nA\n", file_get_contents($unpacked->path));
        self::assertStringEndsWith('/unpacked.csv', $unpacked->path);
        self::assertSame(5, $unpacked->files);
        self::assertSame('Read a-products.csv from feed.zip.', $unpacked->note());
    }

    public function testPatternPicksTheEntryByNameOrPath(): void
    {
        $zip = $this->zip(['x/prices.xml' => '<p/>', 'x/stock.xml' => '<s/>', 'y/stock.xml' => '<t/>']);
        $dir = $this->tempDir();
        self::assertSame('<s/>', file_get_contents(
            $this->unpacker()->unpack($zip, 'f.zip', $dir, ['archive_entry' => 'STOCK.xml'])->path
        ));
        self::assertSame('<t/>', file_get_contents(
            $this->unpacker()->unpack($zip, 'f.zip', $dir, ['archive_entry' => 'y/*.xml'])->path
        ));
        $this->expectException(ArchiveException::class);
        $this->expectExceptionMessage('No file matches "*.json" in "f.zip". It holds: x/prices.xml, x/stock.xml, y/stock.xml.');
        $this->unpacker()->unpack($zip, 'f.zip', $dir, ['archive_entry' => '*.json']);
    }

    public function testNoReadableFileSaysWhatTheArchiveHolds(): void
    {
        $zip = $this->zip(['a.pdf' => 'x', 'b.doc' => 'y']);
        $this->expectException(ArchiveException::class);
        $this->expectExceptionMessage('No CSV, Excel, ODS, JSON or XML file in "f.zip" (set "File inside the archive"). It holds: a.pdf, b.doc.');
        $this->unpacker()->unpack($zip, 'f.zip', $this->tempDir());
    }

    public function testZipSlipNamesNeverLeaveTheTargetFolder(): void
    {
        $zip = $this->zip(['../../evil.csv' => "sku\nX\n", '/etc/passwd.csv' => 'y']);
        $dir = $this->tempDir();
        $unpacked = $this->unpacker()->unpack($zip, 'f.zip', $dir);
        self::assertSame($dir . '/unpacked.csv', $unpacked->path);
        self::assertSame('evil.csv', $unpacked->name);
        self::assertFalse(is_file(dirname($dir, 2) . '/evil.csv'));
        self::assertCount(1, glob($dir . '/*'));
    }

    public function testZipBombStopsAtTheLimitWhileWriting(): void
    {
        $zip = $this->zip(['big.csv' => str_repeat("sku,name\n", 20000)]);
        $dir = $this->tempDir();
        try {
            $this->unpacker(1024)->unpack($zip, 'f.zip', $dir);
            self::fail('no exception');
        } catch (ArchiveException $exception) {
            self::assertStringContainsString('"big.csv" unpacks to more than 0 MB', $exception->getMessage());
            self::assertStringContainsString('zip bomb', $exception->getMessage());
        }
        self::assertSame([], glob($dir . '/*'), 'the partial file is removed');
    }

    public function testTooManyEntriesAreRefused(): void
    {
        $files = [];
        for ($i = 0; $i < 12; $i++) {
            $files["f$i.csv"] = 'x';
        }
        $this->expectException(ArchiveException::class);
        $this->expectExceptionMessage('"f.zip" holds more than 10 files; it is not unpacked.');
        $this->unpacker(1024, 10)->unpack($this->zip($files), 'f.zip', $this->tempDir());
    }

    public function testArchivesInsideArchivesAreNotUnpacked(): void
    {
        $zip = $this->zip(['inner.zip' => 'PK', 'data.csv' => 'x']);
        self::assertSame('data.csv', $this->unpacker()->unpack($zip, 'f.zip', $this->tempDir())->name);
        $only = $this->zip(['inner.zip' => 'PK']);
        $this->expectException(ArchiveException::class);
        $this->expectExceptionMessage('"inner.zip" in "f.zip" is an archive itself; archives inside archives are not unpacked.');
        $this->unpacker()->unpack($only, 'f.zip', $this->tempDir(), ['archive_entry' => '*.zip']);
    }

    public function testPasswordProtectedEntryIsRefused(): void
    {
        $file = $this->temp('', 'zip');
        $zip = new \ZipArchive();
        $zip->open($file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('secret.csv', "sku\n");
        $zip->setEncryptionName('secret.csv', \ZipArchive::EM_AES_256, 'pw');
        $zip->close();
        $this->expectException(ArchiveException::class);
        $this->expectExceptionMessage('"secret.csv" in "f.zip" is protected by a password.');
        $this->unpacker()->unpack($file, 'f.zip', $this->tempDir());
    }

    public function testDamagedZip(): void
    {
        $this->expectException(ArchiveException::class);
        $this->expectExceptionMessage('The ZIP file "f.zip" cannot be opened (damaged?).');
        $this->unpacker()->unpack($this->temp("PK\x03\x04garbage", 'zip'), 'f.zip', $this->tempDir());
    }

    public function testGzipHoldsOneFileNamedAfterTheArchive(): void
    {
        $gz = $this->gz("sku;qty\nA;1\n");
        $unpacked = $this->unpacker()->unpack($gz, 'stock-2026.csv.gz', $this->tempDir());
        self::assertSame('stock-2026.csv', $unpacked->name);
        self::assertStringEndsWith('/unpacked.csv', $unpacked->path);
        self::assertSame("sku;qty\nA;1\n", file_get_contents($unpacked->path));
        self::assertSame(1, $unpacked->files);
    }

    public function testGzipLimit(): void
    {
        $gz = $this->gz(str_repeat('a', 5000));
        $dir = $this->tempDir();
        $this->expectException(ArchiveException::class);
        $this->expectExceptionMessage('"big.csv" unpacks to more than 0 MB');
        try {
            $this->unpacker(2000)->unpack($gz, 'big.csv.gz', $dir);
        } finally {
            self::assertSame([], glob($dir . '/*'));
        }
    }

    public function testTarAndTarGzWithLongNamesAndFolders(): void
    {
        $long = str_repeat('a-very-long-folder-name/', 6) . 'products.csv';
        $tar = $this->tar(['notes.txt' => 'n', 'dir/' => null, $long => "sku\nL\n", 'other.json' => '{}']);
        $unpacked = $this->unpacker()->unpack($tar, 'feed.tar', $this->tempDir());
        self::assertSame('products.csv', $unpacked->name, 'natural order: a-very… before notes, other');
        self::assertSame("sku\nL\n", file_get_contents($unpacked->path));
        self::assertSame(3, $unpacked->files, 'folders are no files');

        $tgz = $this->temp(gzencode((string) file_get_contents($tar)), 'tgz');
        $unpacked = $this->unpacker()->unpack($tgz, 'feed.tgz', $this->tempDir(), ['archive_entry' => '*.json']);
        self::assertSame('other.json', $unpacked->name);
        self::assertSame('{}', file_get_contents($unpacked->path));
        self::assertSame('tar.gz', $this->unpacker()->kind($tgz, 'feed.tgz'));
    }

    public function testTarLinksAreSkippedAndTruncatedTarIsReported(): void
    {
        $tar = $this->tar(['link.csv' => ['type' => '2', 'data' => ''], 'real.csv' => 'r']);
        self::assertSame('real.csv', $this->unpacker()->unpack($tar, 'f.tar', $this->tempDir())->name);

        $cut = $this->temp(substr((string) file_get_contents($this->tar(['a.csv' => str_repeat('x', 2000)])), 0, 1000), 'tar');
        $this->expectException(ArchiveException::class);
        $this->expectExceptionMessage('"f.tar" ends before "a.csv" does (damaged?).');
        $this->unpacker()->unpack($cut, 'f.tar', $this->tempDir());
    }

    public function testWithFileReadsPlainFilesDirectlyAndRemovesTheUnpackFolder(): void
    {
        $plain = $this->temp("sku\n", 'csv');
        $seen = $this->unpacker()->withFile($plain, 'p.csv', [], static fn ($path, $name) => [$path, $name]);
        self::assertSame([$plain, 'p.csv'], $seen);

        $base = $this->tempDir();
        $unpacker = new Unpacker(new File(), $this->tempFolder());
        $zip = $this->zip(['x.csv' => "sku\nZ\n"]);
        $content = $unpacker->withFile($zip, 'f.zip', [], static fn ($path, $name) => $name . ':' . file_get_contents($path));
        self::assertSame("x.csv:sku\nZ\n", $content);
        self::assertSame([], glob($base . '/*'), 'the unpack folder is removed');
    }
}
