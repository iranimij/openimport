<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Writer;

/**
 * XML: <root><record><column>value</column>…</record>…</root>. Options "root" (default "export") and "record"
 * (default "item"); column names that are not valid element names are cleaned (spaces → "_", leading digits
 * prefixed). Written with XMLWriter, so every value is escaped.
 */
class XmlWriter extends AbstractWriter
{
    private ?\XMLWriter $xml = null;

    /**
     * @var string[] element name per column
     */
    private array $elements = [];

    /**
     * @inheritDoc
     */
    public function extension(): string
    {
        return 'xml';
    }

    /**
     * @inheritDoc
     */
    public function contentType(): string
    {
        return 'application/xml';
    }

    /**
     * @inheritDoc
     */
    protected function start(): void
    {
        $this->elements = array_map(fn (string $column): string => $this->element($column), $this->columns);
        $this->xml = new \XMLWriter();
        $this->xml->openMemory();
        $this->xml->setIndent(true);
        $this->xml->setIndentString('  ');
        $this->xml->startDocument('1.0', 'UTF-8');
        $this->xml->startElement($this->element($this->option('root', 'export')));
        $this->flush();
    }

    /**
     * @inheritDoc
     */
    protected function row(array $row): void
    {
        $xml = $this->xml();
        $xml->startElement($this->element($this->option('record', 'item')));
        foreach ($this->columns as $index => $column) {
            $xml->startElement($this->elements[$index]);
            $xml->text($this->cells->text($row[$column] ?? null, $this->guardFormulas()));
            $xml->endElement();
        }
        $xml->endElement();
        $this->flush();
    }

    /**
     * @inheritDoc
     */
    protected function finish(): void
    {
        $xml = $this->xml();
        $xml->endElement();
        $xml->endDocument();
        $this->flush();
        $this->xml = null;
    }

    /**
     * @param string $name
     * @return string a valid XML element name
     */
    private function element(string $name): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_.-]+/u', '_', trim($name)) ?? 'column';
        $clean = trim($clean, '_-.');
        if ($clean === '' || preg_match('/^[A-Za-z_]/', $clean) !== 1 || stripos($clean, 'xml') === 0) {
            $clean = 'c_' . $clean;
        }

        return $clean;
    }

    /**
     * @return void
     */
    private function flush(): void
    {
        $this->append((string) $this->xml()->flush(true));
    }

    /**
     * @return \XMLWriter
     */
    private function xml(): \XMLWriter
    {
        if ($this->xml === null) {
            throw new WriterException('The writer is not open.');
        }

        return $this->xml;
    }
}
