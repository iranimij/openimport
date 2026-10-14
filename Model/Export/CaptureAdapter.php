<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Export;

use Magento\ImportExport\Model\Export\Adapter\AbstractAdapter;

/**
 * The "writer" handed to Magento's export entities: instead of building a file in memory it passes the header and
 * every row to callbacks. getContents() stays empty; nothing is written to var/importexport.
 */
class CaptureAdapter extends AbstractAdapter
{
    /**
     * @var callable|null
     */
    private $onHeader = null;

    /**
     * @var callable|null
     */
    private $onRow = null;

    private bool $headerSent = false;

    /**
     * @param callable $onHeader
     * @param callable $onRow
     * @return void
     */
    public function listen(callable $onHeader, callable $onRow): void
    {
        $this->onHeader = $onHeader;
        $this->onRow = $onRow;
        $this->headerSent = false;
    }

    /**
     * @param string[] $headerColumns
     * @return $this
     */
    public function setHeaderCols(array $headerColumns)
    {
        $this->_headerCols = array_flip(array_map('strval', $headerColumns));
        if (!$this->headerSent && $this->onHeader !== null) {
            $this->headerSent = true;
            ($this->onHeader)(array_values(array_map('strval', $headerColumns)));
        }

        return $this;
    }

    /**
     * @param array<string, mixed> $rowData
     * @return $this
     */
    public function writeRow(array $rowData)
    {
        if ($this->onRow === null) {
            return $this;
        }
        if ($this->_headerCols === null) {
            $this->setHeaderCols(array_keys($rowData));
        }
        ($this->onRow)($rowData);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getContents()
    {
        return '';
    }

    /**
     * @inheritDoc
     */
    public function getContentType()
    {
        return 'text/plain';
    }

    /**
     * @inheritDoc
     */
    public function getFileExtension()
    {
        return 'txt';
    }
}
