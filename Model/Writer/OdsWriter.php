<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Writer;

use Iranimij\OpenImport\Model\Value\NumberParser;

/**
 * Writes an OpenDocument spreadsheet (.ods): one sheet, a bold frozen header row, numbers as numbers, everything
 * else as text. content.xml is streamed to a file next to the output and zipped at the end (mimetype first and
 * uncompressed, as the format requires).
 */
class OdsWriter extends AbstractWriter
{
    public const MIME = 'application/vnd.oasis.opendocument.spreadsheet';

    private const NAMESPACES = 'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
        . ' xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0"'
        . ' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"'
        . ' xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"'
        . ' xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0"';

    private ?\SplFileObject $content = null;

    private string $contentPath = '';

    /**
     * @param Cells $cells
     * @param NumberParser $numbers
     */
    public function __construct(
        Cells $cells,
        private readonly NumberParser $numbers
    ) {
        parent::__construct($cells);
    }

    /**
     * @inheritDoc
     */
    public function extension(): string
    {
        return 'ods';
    }

    /**
     * @inheritDoc
     */
    public function contentType(): string
    {
        return self::MIME;
    }

    /**
     * @inheritDoc
     */
    protected function start(): void
    {
        $this->contentPath = $this->path() . '.content.xml';
        try {
            $this->content = new \SplFileObject($this->contentPath, 'wb');
        } catch (\RuntimeException $exception) {
            throw new WriterException(
                sprintf('The file %s cannot be written: %s', $this->contentPath, $exception->getMessage())
            );
        }
        $this->contentAppend(
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<office:document-content ' . self::NAMESPACES . ' office:version="1.2">'
            . '<office:automatic-styles>'
            . '<style:style style:name="head" style:family="table-cell">'
            . '<style:text-properties fo:font-weight="bold"/></style:style>'
            . '</office:automatic-styles>'
            . '<office:body><office:spreadsheet>'
            . '<table:table table:name="' . $this->escape($this->sheetName()) . '">'
            . '<table:table-column table:number-columns-repeated="' . max(1, count($this->columns)) . '"/>'
            . '<table:table-header-rows>'
        );
        $this->contentRow(array_map(static fn (string $column): array => ['s', $column], $this->columns), 'head');
        $this->contentAppend('</table:table-header-rows>');
    }

    /**
     * @inheritDoc
     */
    protected function row(array $row): void
    {
        $cells = [];
        foreach ($this->cells->list($this->columns, $row, $this->guardFormulas()) as $text) {
            $cells[] = $text !== '' && $this->numbers->isPlain($text) && strlen(trim($text)) <= 15
                && preg_match('/^\s*[+-]?0\d/', $text) !== 1
                ? ['n', trim($text)]
                : ['s', $text];
        }
        $this->contentRow($cells);
    }

    /**
     * @inheritDoc
     */
    protected function finish(): void
    {
        $this->contentAppend('</table:table></office:spreadsheet></office:body></office:document-content>');
        $this->content = null;
        $zip = new \ZipArchive();
        $path = $this->path();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new WriterException(sprintf('The file %s cannot be written.', $path));
        }
        $zip->addFromString('mimetype', self::MIME);
        $zip->setCompressionName('mimetype', \ZipArchive::CM_STORE);
        $zip->addFromString('META-INF/manifest.xml', $this->manifest());
        $zip->addFromString('styles.xml', $this->styles());
        $zip->addFile($this->contentPath, 'content.xml');
        if (!$zip->close()) {
            throw new WriterException(sprintf('The file %s cannot be written.', $path));
        }
        $info = new \SplFileInfo($this->contentPath);
        if ($info->isFile()) {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction -- own temporary file next to the output
            unlink($this->contentPath);
        }
    }

    /**
     * @param array<int, array{0: string, 1: string}> $cells type (s|n) and text
     * @param string $style cell style name, '' for none
     * @return void
     */
    private function contentRow(array $cells, string $style = ''): void
    {
        $xml = '<table:table-row>';
        $styleAttribute = $style === '' ? '' : ' table:style-name="' . $style . '"';
        $empty = 0;
        foreach ($cells as [$type, $text]) {
            if ($text === '') {
                $empty++;
                continue;
            }
            if ($empty > 0) {
                $repeated = $empty > 1 ? ' table:number-columns-repeated="' . $empty . '"' : '';
                $xml .= '<table:table-cell' . $repeated . '/>';
                $empty = 0;
            }
            $xml .= $type === 'n'
                ? '<table:table-cell' . $styleAttribute . ' office:value-type="float" office:value="' . $text . '">'
                    . '<text:p>' . $text . '</text:p></table:table-cell>'
                : '<table:table-cell' . $styleAttribute . ' office:value-type="string">'
                    . $this->paragraphs($this->cleanText($text)) . '</table:table-cell>';
        }
        $this->contentAppend($xml . '</table:table-row>');
    }

    /**
     * One text:p per line; runs of spaces and tabs as text:s and text:tab so they survive.
     */
    private function paragraphs(string $text): string
    {
        $xml = '';
        foreach (explode("\n", str_replace("\r\n", "\n", $text)) as $line) {
            $escaped = $this->escape($line);
            $escaped = (string) preg_replace_callback(
                '/ {2,}/',
                static fn (array $m): string => ' <text:s text:c="' . (strlen($m[0]) - 1) . '"/>',
                $escaped
            );
            $escaped = str_replace("\t", '<text:tab/>', $escaped);
            if (str_starts_with($escaped, ' ')) {
                $escaped = '<text:s/>' . substr($escaped, 1);
            }
            $xml .= '<text:p>' . $escaped . '</text:p>';
        }

        return $xml;
    }

    /**
     * The five XML escapes, by hand: the framework's escaper is for HTML.
     */
    private function escape(string $text): string
    {
        return str_replace(['&', '<', '>', '"', "'"], ['&amp;', '&lt;', '&gt;', '&quot;', '&apos;'], $text);
    }

    /**
     * Characters XML 1.0 cannot carry are dropped.
     */
    private function cleanText(string $text): string
    {
        $allowed = '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u';

        return (string) preg_replace($allowed, '', $text);
    }

    private function contentAppend(string $xml): void
    {
        if ($this->content === null || $this->content->fwrite($xml) === false) {
            throw new WriterException(sprintf('The file %s cannot be written (disk full?).', $this->contentPath));
        }
    }

    private function sheetName(): string
    {
        $name = trim((string) preg_replace('/[\[\]:*?\/\\\\]+/', ' ', $this->option('sheet', 'Export')));

        return mb_substr($name === '' ? 'Export' : $name, 0, 31);
    }

    private function manifest(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0"'
            . ' manifest:version="1.2">'
            . '<manifest:file-entry manifest:full-path="/" manifest:version="1.2"'
            . ' manifest:media-type="' . self::MIME . '"/>'
            . '<manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/>'
            . '<manifest:file-entry manifest:full-path="styles.xml" manifest:media-type="text/xml"/>'
            . '</manifest:manifest>';
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<office:document-styles ' . self::NAMESPACES . ' office:version="1.2">'
            . '<office:styles><style:default-style style:family="table-cell">'
            . '<style:text-properties fo:font-size="10pt"/></style:default-style></office:styles>'
            . '</office:document-styles>';
    }
}
