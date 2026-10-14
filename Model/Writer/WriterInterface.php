<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Writer;

/**
 * Writes export rows to a file, one row at a time (streaming: memory does not grow with the number of rows).
 * Own writers can be registered in di.xml (WriterFactory argument "writers", keyed by format).
 *
 * @api
 */
interface WriterInterface
{
    /**
     * Opens the file and writes the header.
     *
     * @param string $path absolute path; overwritten
     * @param string[] $columns column names, in order
     * @param array<string, mixed> $options format options (delimiter, enclosure, bom, sheet, root, record, pretty …)
     * @return void
     * @throws WriterException
     */
    public function open(string $path, array $columns, array $options = []): void;

    /**
     * @param array<string, scalar|null> $row column => value; missing columns are written empty
     * @return void
     * @throws WriterException
     */
    public function write(array $row): void;

    /**
     * Finishes the file. Must be called, also after an error.
     *
     * @return void
     * @throws WriterException
     */
    public function close(): void;

    /**
     * @return string file extension without dot
     */
    public function extension(): string;

    /**
     * @return string MIME type for downloads and e-mail attachments
     */
    public function contentType(): string;
}
