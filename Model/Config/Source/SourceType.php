<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Where a profile reads its file.
 */
class SourceType implements OptionSourceInterface
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach ([
            'upload' => 'Upload a file',
            'folder' => 'Server folder',
            'url' => 'Web address (URL)',
            'sftp' => 'SFTP',
            'ftp' => 'FTP',
            's3' => 'Amazon S3 / S3-compatible',
            'google_drive' => 'Google Drive / Sheets (login)',
            'dropbox' => 'Dropbox',
            'onedrive' => 'OneDrive / SharePoint',
            'rest' => 'REST API',
        ] as $value => $label) {
            $options[] = ['value' => (string) $value, 'label' => __($label)];
        }

        return $options;
    }
}
