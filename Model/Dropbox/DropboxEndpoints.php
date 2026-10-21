<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Dropbox;

/**
 * Dropbox's addresses (di.xml; tests point them to a local mock server).
 */
class DropboxEndpoints
{
    /**
     * @param string $authorize
     * @param string $token
     * @param string $api RPC endpoints (list, move, delete, account)
     * @param string $content content endpoints (download, upload)
     */
    public function __construct(
        public readonly string $authorize = 'https://www.dropbox.com/oauth2/authorize',
        public readonly string $token = 'https://api.dropboxapi.com/oauth2/token',
        public readonly string $api = 'https://api.dropboxapi.com/2',
        public readonly string $content = 'https://content.dropboxapi.com/2'
    ) {
    }
}
