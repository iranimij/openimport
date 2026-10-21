<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Archive;

use Iranimij\OpenImport\Model\Writer\WriterInterface;

/**
 * The writer as destinations see it once the export file is packed: its extension and content type are the
 * archive's (e-mail attachments, uploads with a Content-Type).
 */
class PackedWriter implements WriterInterface
{
    /**
     * @param WriterInterface $writer
     * @param string $kind one of Packer::KINDS
     * @param string $contentType
     */
    public function __construct(
        private readonly WriterInterface $writer,
        private readonly string $kind,
        private readonly string $contentType
    ) {
    }

    /**
     * @inheritDoc
     */
    public function open(string $path, array $columns, array $options = []): void
    {
        $this->writer->open($path, $columns, $options);
    }

    /**
     * @inheritDoc
     */
    public function write(array $row): void
    {
        $this->writer->write($row);
    }

    /**
     * @inheritDoc
     */
    public function close(): void
    {
        $this->writer->close();
    }

    /**
     * @inheritDoc
     */
    public function extension(): string
    {
        return $this->writer->extension() . '.' . $this->kind;
    }

    /**
     * @inheritDoc
     */
    public function contentType(): string
    {
        return $this->contentType;
    }
}
