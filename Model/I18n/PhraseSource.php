<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\I18n;

use Magento\Framework\Module\Dir;
use Magento\Framework\Module\Dir\Reader;

/**
 * OpenImport's own phrases, the first column of i18n/en_US.csv: the English texts runs store.
 */
class PhraseSource
{
    /**
     * @var string[]|null
     */
    private ?array $phrases = null;

    /**
     * @param Reader $modules
     */
    public function __construct(private readonly Reader $modules)
    {
    }

    /**
     * @return string[]
     */
    public function all(): array
    {
        if ($this->phrases !== null) {
            return $this->phrases;
        }
        $this->phrases = [];
        $path = $this->modules->getModuleDir(Dir::MODULE_I18N_DIR, 'Iranimij_OpenImport') . '/en_US.csv';
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- the module's own file, read once per request
        $handle = is_readable($path) ? fopen($path, 'rb') : false;
        if ($handle === false) {
            return $this->phrases;
        }
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if (isset($row[0]) && is_string($row[0]) && $row[0] !== '') {
                $this->phrases[] = $row[0];
            }
        }
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        fclose($handle);

        return $this->phrases;
    }
}
