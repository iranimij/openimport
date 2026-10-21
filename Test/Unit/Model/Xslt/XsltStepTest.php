<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Xslt;

use Iranimij\OpenImport\Model\Reader\ReaderException;
use Iranimij\OpenImport\Model\Xslt\XsltStep;
use Magento\Framework\Filesystem\Driver\File;
use PHPUnit\Framework\TestCase;

/**
 * The XSLT step: a real transformation, and every way a stylesheet could reach outside the document refused.
 */
class XsltStepTest extends TestCase
{
    private const XSL_HEAD = '<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform">';

    /**
     * @var string[]
     */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    private function file(string $content): string
    {
        $file = sys_get_temp_dir() . '/openimport-xslt-' . bin2hex(random_bytes(5)) . '.xml';
        file_put_contents($file, $content);
        $this->files[] = $file;

        return $file;
    }

    private function step(int $maxBytes = 67108864): XsltStep
    {
        return new XsltStep(new File(), $maxBytes);
    }

    public function testTransformsAFileAndInPlace(): void
    {
        $source = $this->file('<shop><item id="A1"><n>Eins</n><p>1,5</p></item><item id="B2"><n>Zwei</n><p>2</p></item></shop>');
        $xsl = self::XSL_HEAD . '<xsl:output indent="no"/><xsl:template match="/"><products>'
            . '<xsl:for-each select="shop/item"><product><sku><xsl:value-of select="@id"/></sku>'
            . '<name><xsl:value-of select="n"/></name></product></xsl:for-each></products></xsl:template></xsl:stylesheet>';
        $target = $source . '.out';
        $this->files[] = $target;

        $this->step()->transform($source, $xsl, $target);
        self::assertSame(
            '<?xml version="1.0"?>' . "\n" . '<products><product><sku>A1</sku><name>Eins</name></product>'
            . '<product><sku>B2</sku><name>Zwei</name></product></products>',
            trim((string) file_get_contents($target))
        );

        $this->step()->transformInPlace($source, $xsl);
        self::assertStringContainsString('<sku>B2</sku>', (string) file_get_contents($source));
        self::assertFileDoesNotExist($source . '.xslt');
        self::assertSame('', $this->step()->check($xsl));
    }

    public function testApplies(): void
    {
        $step = $this->step();
        self::assertTrue($step->applies('xml', 'feed.csv', ['xslt' => '<x/>']));
        self::assertTrue($step->applies('auto', 'feed.XML', ['xslt' => '<x/>']));
        self::assertFalse($step->applies('auto', 'feed.csv', ['xslt' => '<x/>']));
        self::assertFalse($step->applies('csv', 'feed.xml', ['xslt' => '<x/>']));
        self::assertFalse($step->applies('xml', 'feed.xml', ['xslt' => '  ']));
        self::assertFalse($step->applies('xml', 'feed.xml', []));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function hostile(): array
    {
        return [
            'document()' => [self::XSL_HEAD . '<xsl:template match="/"><r><xsl:copy-of select="document(\'/etc/hostname\')"/></r></xsl:template></xsl:stylesheet>', 'failed on this file'],
            'document() over http' => [self::XSL_HEAD . '<xsl:template match="/"><r><xsl:copy-of select="document(\'http://127.0.0.1:1/x\')"/></r></xsl:template></xsl:stylesheet>', 'failed on this file'],
            'include' => [self::XSL_HEAD . '<xsl:include href="other.xsl"/></xsl:stylesheet>', 'may not include or import'],
            'import' => [self::XSL_HEAD . '<xsl:import href="http://example.com/x.xsl"/></xsl:stylesheet>', 'may not include or import'],
            'entities in the stylesheet' => ['<!DOCTYPE x [<!ENTITY e SYSTEM "/etc/passwd">]>' . self::XSL_HEAD . '<xsl:template match="/"><r>&e;</r></xsl:template></xsl:stylesheet>', 'DOCTYPE'],
            'php functions' => [str_replace('>', ' xmlns:php="http://php.net/xsl">', self::XSL_HEAD) . '<xsl:template match="/"><r><xsl:value-of select="php:function(\'shell_exec\', \'id\')"/></r></xsl:template></xsl:stylesheet>', 'failed on this file'],
            'not xml' => ['select * from', 'not well-formed XML'],
            'not a stylesheet' => ['<html><body/></html>', 'cannot be used'],
        ];
    }

    /**
     * @param string $xsl
     * @param string $message
     * @dataProvider hostile
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('hostile')]
    public function testHostileStylesheetsAreRefused(string $xsl, string $message): void
    {
        $source = $this->file('<shop/>');
        $target = $source . '.out';
        $this->files[] = $target;
        try {
            $this->step()->transform($source, $xsl, $target);
            self::fail('no exception');
        } catch (ReaderException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
        self::assertFileDoesNotExist($target);
        self::assertStringNotContainsString('root:', is_file($target) ? (string) file_get_contents($target) : '');
    }

    public function testDocumentEntitiesAndSizeLimit(): void
    {
        $xsl = self::XSL_HEAD . '<xsl:template match="/"><r><xsl:value-of select="."/></r></xsl:template></xsl:stylesheet>';
        $entities = $this->file('<!DOCTYPE x [<!ENTITY e SYSTEM "/etc/passwd">]><x>&e;</x>');
        try {
            $this->step()->transform($entities, $xsl, $entities . '.out');
            self::fail('no exception');
        } catch (ReaderException $exception) {
            self::assertSame('The XML file contains a DOCTYPE and is refused.', $exception->getMessage());
        }
        $big = $this->file('<x>' . str_repeat('a', 5000) . '</x>');
        $this->expectException(ReaderException::class);
        $this->expectExceptionMessage('XSLT runs on files up to 0 MB');
        $this->step(1024)->transform($big, $xsl, $big . '.out');
    }
}
