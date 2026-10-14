<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Writer;

/**
 * JSON: an array of objects (one per row, keys = column names), or, with option "root", an object with the rows
 * under that key ({"products": [ … ]}). Numbers stay strings (as exported by Magento); "pretty" adds line breaks.
 */
class JsonWriter extends AbstractWriter
{
    private bool $first = true;

    /**
     * @inheritDoc
     */
    public function extension(): string
    {
        return 'json';
    }

    /**
     * @inheritDoc
     */
    public function contentType(): string
    {
        return 'application/json';
    }

    /**
     * @inheritDoc
     */
    protected function start(): void
    {
        $this->first = true;
        $root = $this->option('root', '');
        $this->append($root === '' ? '[' : '{' . json_encode($root) . ': [');
    }

    /**
     * @inheritDoc
     */
    protected function row(array $row): void
    {
        $object = [];
        foreach ($this->columns as $column) {
            $object[$column] = $this->cells->text($row[$column] ?? null, $this->guardFormulas());
        }
        $pretty = !empty($this->options['pretty']);
        $json = json_encode($object, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | ($pretty ? JSON_PRETTY_PRINT : 0));
        if ($json === false) {
            throw new WriterException('A row could not be written as JSON.');
        }
        $this->append(($this->first ? '' : ',') . ($pretty ? "\n" : '') . $json);
        $this->first = false;
    }

    /**
     * @inheritDoc
     */
    protected function finish(): void
    {
        $pretty = !empty($this->options['pretty']);
        $this->append(($pretty ? "\n" : '') . ']' . ($this->option('root', '') === '' ? '' : '}') . "\n");
    }
}
