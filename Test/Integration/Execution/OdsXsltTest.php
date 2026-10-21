<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Integration\Execution;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Api\Data\ProfileInterfaceFactory;
use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Api\ProfileRepositoryInterface;
use Iranimij\OpenImport\Model\Execution\RunFiles;
use Iranimij\OpenImport\Model\Execution\RunProcessor;
use Iranimij\OpenImport\Model\Execution\RunQueue;
use Iranimij\OpenImport\Model\Reader\ReaderFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * ODS and XSLT through whole runs: an ODS export read back by an ODS import (round trip), an XML file in a
 * supplier's layout turned into records by a stylesheet, an export reshaped by one, and a hostile stylesheet
 * refused at save.
 */
#[AppArea('adminhtml')]
#[DbIsolation(false)]
#[AppIsolation(true)]
class OdsXsltTest extends TestCase
{
    private const XSL_HEAD = '<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform">';

    private function var(): Filesystem\Directory\WriteInterface
    {
        return Bootstrap::getObjectManager()->get(Filesystem::class)->getDirectoryWrite(DirectoryList::VAR_DIR);
    }

    /**
     * @param array<string, mixed> $data
     * @return ProfileInterface
     */
    private function importProfile(array $data): ProfileInterface
    {
        $profile = Bootstrap::getObjectManager()->get(ProfileInterfaceFactory::class)->create()
            ->setName('ODS/XSLT import ' . uniqid())
            ->setEntity('catalog_product')
            ->setFormat($data['format'] ?? 'auto')
            ->setFormatOptions($data['format_options'] ?? [])
            ->setSourceType('folder')
            ->setSourceConfig($data['source_config'])
            ->setMapping([
                ['target' => 'sku', 'source' => 'sku'],
                ['target' => 'name', 'source' => 'name'],
                ['target' => 'price', 'source' => 'price'],
                ['target' => 'attribute_set_code', 'source' => null, 'static' => 'Default'],
                ['target' => 'product_type', 'source' => null, 'static' => 'simple'],
                ['target' => 'product_websites', 'source' => null, 'static' => 'base'],
            ]);

        return Bootstrap::getObjectManager()->get(ProfileRepositoryInterface::class)->save($profile);
    }

    /**
     * @param array<string, mixed> $data
     * @return ProfileInterface
     */
    private function exportProfile(array $data): ProfileInterface
    {
        $profile = Bootstrap::getObjectManager()->get(ProfileInterfaceFactory::class)->create()
            ->setName('ODS/XSLT export ' . uniqid())
            ->setDirection(ProfileInterface::DIRECTION_EXPORT)
            ->setEntity('catalog_product')
            ->setFormat($data['format'])
            ->setFormatOptions($data['format_options'] ?? [])
            ->setMapping($data['mapping'] ?? [])
            ->setExportOptions($data['export_options'] ?? []);

        return Bootstrap::getObjectManager()->get(ProfileRepositoryInterface::class)->save($profile);
    }

    private function process(ProfileInterface $profile, string $mode = RunInterface::MODE_LIVE): RunInterface
    {
        $run = Bootstrap::getObjectManager()->get(RunQueue::class)->queue($profile, $mode, RunInterface::TRIGGER_CLI);

        return Bootstrap::getObjectManager()->get(RunProcessor::class)->process((int) $run->getRunId());
    }

    private function outputOf(RunInterface $run): string
    {
        return Bootstrap::getObjectManager()->get(RunFiles::class)->absolute((string) $run->getOutputFile());
    }

    public function testOdsExportReadBackByAnOdsImport(): void
    {
        $tag = bin2hex(random_bytes(3));
        $folder = 'openimport/it-ods-' . $tag;
        $export = $this->process($this->exportProfile([
            'format' => 'ods',
            'format_options' => ['sheet' => 'Products'],
            'mapping' => [['target' => 'sku', 'source' => 'sku'], ['target' => 'name', 'source' => 'name'], ['target' => 'price', 'source' => 'price']],
            'export_options' => ['destination' => 'folder', 'path' => 'it-ods-' . $tag, 'file_name' => 'catalog.{ext}'],
        ]));
        self::assertSame(RunInterface::STATUS_SUCCESS, $export->getStatus(), $export->getMessage());
        self::assertGreaterThan(0, $export->getCount('exported'));
        $file = $this->var()->getAbsolutePath($folder . '/catalog.ods');
        self::assertFileExists($file);
        $reader = Bootstrap::getObjectManager()->get(ReaderFactory::class)->create($file, 'auto');
        self::assertSame(['sku', 'name', 'price'], $reader->columns());
        self::assertSame(['sheet' => 'Products'], $reader->detected());

        // The same file, with one product renamed, imported again from the folder.
        $zip = new \ZipArchive();
        $zip->open($file);
        $content = (string) $zip->getFromName('content.xml');
        $firstSku = iterator_to_array($reader->rows(), false)[0]['sku'];
        $content = (string) preg_replace('#(<text:p>' . preg_quote($firstSku, '#') . '</text:p></table:table-cell><table:table-cell office:value-type="string"><text:p>)[^<]*#', '$1Renamed ' . $tag, $content, 1);
        $zip->addFromString('content.xml', $content);
        $zip->close();

        $import = $this->process($this->importProfile(['source_config' => ['path' => 'it-ods-' . $tag, 'pattern' => '*.ods']]));
        self::assertSame(RunInterface::STATUS_SUCCESS, $import->getStatus(), $import->getMessage());
        self::assertSame(1, $import->getCount('updated'));
        $product = Bootstrap::getObjectManager()->get(ProductRepositoryInterface::class)->get($firstSku, false, null, true);
        self::assertSame('Renamed ' . $tag, $product->getName());
    }

    public function testXsltBeforeReadingAndAfterWriting(): void
    {
        $tag = bin2hex(random_bytes(3));
        $folder = 'openimport/it-xslt-' . $tag;
        $this->var()->writeFile(
            $folder . '/supplier.xml',
            '<lieferung><artikel nr="OI-XSLT-' . $tag . '"><bezeichnung>Lampe ' . $tag . '</bezeichnung><vk>19,90</vk></artikel></lieferung>'
        );
        $xsl = self::XSL_HEAD . '<xsl:template match="/"><products><xsl:for-each select="lieferung/artikel"><product>'
            . '<sku><xsl:value-of select="@nr"/></sku><name><xsl:value-of select="bezeichnung"/></name>'
            . '<price><xsl:value-of select="translate(vk, \',\', \'.\')"/></price></product></xsl:for-each></products>'
            . '</xsl:template></xsl:stylesheet>';
        $profile = $this->importProfile([
            'source_config' => ['path' => 'it-xslt-' . $tag, 'pattern' => '*.xml'],
            'format_options' => ['xslt' => $xsl],
        ]);
        $run = $this->process($profile);
        self::assertSame(RunInterface::STATUS_SUCCESS, $run->getStatus(), $run->getMessage());
        self::assertSame(1, $run->getCount('created'));
        $product = Bootstrap::getObjectManager()->get(ProductRepositoryInterface::class)->get('OI-XSLT-' . $tag);
        self::assertSame('Lampe ' . $tag, $product->getName());
        self::assertSame(19.9, (float) $product->getPrice());

        // Export: the written XML reshaped into the partner's layout.
        $out = self::XSL_HEAD . '<xsl:template match="/"><katalog><xsl:for-each select="//record"><artikel nr="{sku}">'
            . '<xsl:value-of select="name"/></artikel></xsl:for-each></katalog></xsl:template></xsl:stylesheet>';
        $export = $this->process($this->exportProfile([
            'format' => 'xml',
            'format_options' => ['root' => 'records', 'record' => 'record'],
            'mapping' => [['target' => 'sku', 'source' => 'sku'], ['target' => 'name', 'source' => 'name']],
            'export_options' => ['xslt' => $out, 'core_filters' => ['sku' => 'OI-XSLT-' . $tag]],
        ]));
        self::assertSame(RunInterface::STATUS_SUCCESS, $export->getStatus(), $export->getMessage());
        $xml = (string) file_get_contents($this->outputOf($export));
        self::assertStringContainsString('<katalog><artikel nr="OI-XSLT-' . $tag . '">Lampe ' . $tag . '</artikel></katalog>', $xml);
    }

    public function testHostileStylesheetIsRefusedAtSave(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('may not include or import');
        $this->importProfile([
            'source_config' => ['path' => 'x', 'pattern' => '*.xml'],
            'format_options' => ['xslt' => self::XSL_HEAD . '<xsl:include href="/etc/passwd"/></xsl:stylesheet>'],
        ]);
    }
}
