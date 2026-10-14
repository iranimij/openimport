<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Writer;

use Magento\Framework\ObjectManagerInterface;

/**
 * A fresh writer per export file, by format (csv, xlsx, json, xml; more through di.xml).
 */
class WriterFactory
{
    /**
     * @param ObjectManagerInterface $objectManager
     * @param array<string, string> $writers format => class implementing WriterInterface
     */
    public function __construct(
        private readonly ObjectManagerInterface $objectManager,
        private readonly array $writers = []
    ) {
    }

    /**
     * @param string $format
     * @return WriterInterface
     * @throws WriterException
     */
    public function create(string $format): WriterInterface
    {
        $format = strtolower(trim($format));
        $class = $this->writers[$format === 'auto' || $format === '' ? 'csv' : $format] ?? null;
        if ($class === null) {
            throw new WriterException(sprintf('OpenImport cannot write %s files (it writes %s).', $format, implode(', ', array_keys($this->writers))));
        }
        $writer = $this->objectManager->create($class);
        if (!$writer instanceof WriterInterface) {
            throw new WriterException(sprintf('%s is not a writer.', $class));
        }

        return $writer;
    }

    /**
     * @return string[] formats this factory can write
     */
    public function formats(): array
    {
        return array_keys($this->writers);
    }
}
