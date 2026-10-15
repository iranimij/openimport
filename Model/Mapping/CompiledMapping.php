<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Mapping;

use Iranimij\OpenImport\Model\Transform\TransformException;

/**
 * A profile's mapping, ready to turn source rows into core rows. Built once per run by MappingCompiler.
 *
 * Unmapped core columns are not sent, so core leaves them unchanged. Store-view values become extra rows with the
 * identifier, store_view_code, the carry columns and the store-view columns; a store view gets a row only when at
 * least one of its values is not empty.
 */
class CompiledMapping
{
    /**
     * @param array<int, array{field: string, column: string, store: string|null, read: \Closure, required: bool}> $fields
     * @param string[] $columns core columns in output order
     * @param string[] $identifier identifier columns
     * @param string[] $carry default-row columns repeated on store-view rows
     */
    public function __construct(
        private readonly array $fields,
        private readonly array $columns,
        private readonly array $identifier,
        private readonly array $carry
    ) {
    }

    /**
     * Core columns, in the order rows are sent (identifier first).
     *
     * @return string[]
     */
    public function columns(): array
    {
        return $this->columns;
    }

    /**
     * @return string[]
     */
    public function identifierColumns(): array
    {
        return $this->identifier;
    }

    /**
     * Default-row columns repeated on store-view rows (identifier included).
     *
     * @return string[]
     */
    public function carryColumns(): array
    {
        return $this->carry;
    }

    /**
     * @param array<string, string> $source
     * @return MappedRow
     */
    public function map(array $source): MappedRow
    {
        $main = [];
        $stores = [];
        $errors = [];
        foreach ($this->fields as $field) {
            try {
                $value = ($field['read'])($source);
            } catch (TransformException $exception) {
                $errors[] = new FieldError($field['field'], $exception->getMessage());
                continue;
            }
            if ($field['required'] && trim($value) === '') {
                $errors[] = new FieldError($field['field'], sprintf('"%s" is required but empty.', $field['field']));
                continue;
            }
            if ($field['store'] === null) {
                $main[$field['column']] = $value;
            } else {
                $stores[$field['store']][$field['column']] = $value;
            }
        }
        $identifier = $this->identifierOf($main);
        if ($errors === [] && $identifier === '') {
            $errors[] = new FieldError(
                implode(', ', $this->identifier),
                sprintf('The identifier (%s) is empty.', implode(', ', $this->identifier))
            );
        }
        if ($errors !== []) {
            return new MappedRow([], $errors, $identifier);
        }
        $rows = [$main];
        foreach ($stores as $store => $values) {
            if (implode('', array_map('trim', $values)) === '') {
                continue;
            }
            $row = [];
            foreach ($this->carry as $column) {
                if (isset($main[$column])) {
                    $row[$column] = $main[$column];
                }
            }
            $row['store_view_code'] = (string) $store;
            $rows[] = $row + $values;
        }

        return new MappedRow($rows, [], $identifier);
    }

    /**
     * The identifier of a core row: the identifier columns' values joined with "|" (case kept).
     *
     * @param array<string, string> $row
     * @return string
     */
    public function identifierOf(array $row): string
    {
        $parts = [];
        foreach ($this->identifier as $column) {
            $parts[] = trim($row[$column] ?? '');
        }

        return implode('', $parts) === '' ? '' : implode('|', $parts);
    }
}
