<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Reader;

/**
 * Reads one source file as a stream of rows.
 *
 * Rows are associative arrays of strings keyed by column name, yielded with the row's position in the merchant's
 * file as key: the line or sheet row number for CSV and XLSX (the header is row 1, so the first data row is 2), the
 * 1-based record number for JSON and XML. Error reports point at these numbers.
 */
interface ReaderInterface
{
    /**
     * Key a row carries when the line has filled cells beyond the header (the extra values, joined by " | ").
     */
    public const EXTRA_CELLS = '__openimport_extra_cells';

    /**
     * Column names in file order. For JSON and XML: the keys found in the first records (later records may add
     * more; they still come through in rows()).
     *
     * @return string[]
     */
    public function columns(): array;

    /**
     * A new pass over the file on every call.
     *
     * @return \Generator<int, array<string, string>>
     */
    public function rows(): \Generator;

    /**
     * What was detected or used (delimiter, encoding, sheet, record path …), for the profile form and run log.
     *
     * @return array<string, string>
     */
    public function detected(): array;
}
