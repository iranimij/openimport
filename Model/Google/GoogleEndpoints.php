<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Google;

/**
 * Google's addresses (di.xml; tests point them to a local mock server).
 */
class GoogleEndpoints
{
    /**
     * @param string $authorize OAuth consent page
     * @param string $token OAuth token endpoint
     * @param string $drive Drive API v3
     * @param string $upload Drive upload API v3
     */
    public function __construct(
        public readonly string $authorize = 'https://accounts.google.com/o/oauth2/v2/auth',
        public readonly string $token = 'https://oauth2.googleapis.com/token',
        public readonly string $drive = 'https://www.googleapis.com/drive/v3',
        public readonly string $upload = 'https://www.googleapis.com/upload/drive/v3'
    ) {
    }
}
