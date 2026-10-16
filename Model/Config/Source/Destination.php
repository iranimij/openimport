<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Config\Source;

use Iranimij\OpenImport\Model\Export\DestinationPool;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Where an export file goes.
 */
class Destination implements OptionSourceInterface
{

    /**
     * @param DestinationPool $destinations
     */
    public function __construct(
        private readonly DestinationPool $destinations
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toOptionArray(): array
    {
        $labels = [
            'download' => __('Download from the run page'),
            'folder' => __('Server folder'),
            'sftp' => __('SFTP'),
            'ftp' => __('FTP'),
            's3' => __('Amazon S3 / S3-compatible'),
            'google_drive' => __('Google Drive / Sheets'),
            'email' => __('E-mail attachment'),
        ];
        $options = [];
        foreach ($this->destinations->types() as $type) {
            $options[] = ['value' => $type, 'label' => $labels[$type] ?? ucfirst($type)];
        }

        return $options;
    }
}
