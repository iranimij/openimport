<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Microsoft;

/**
 * Microsoft's addresses (di.xml; tests point them to a local mock server).
 */
class MicrosoftEndpoints
{
    /**
     * @param string $login identity platform, + /<tenant>/oauth2/v2.0/authorize and /token
     * @param string $graph Microsoft Graph v1.0
     */
    public function __construct(
        public readonly string $login = 'https://login.microsoftonline.com',
        public readonly string $graph = 'https://graph.microsoft.com/v1.0'
    ) {
    }

    /**
     * @param string $tenant
     * @param string $endpoint authorize or token
     * @return string
     */
    public function oauth(string $tenant, string $endpoint): string
    {
        $tenant = trim($tenant) !== '' ? trim($tenant) : 'common';

        return $this->login . '/' . rawurlencode($tenant) . '/oauth2/v2.0/' . $endpoint;
    }

    /**
     * @param string $site SharePoint site ("contoso.sharepoint.com/sites/Shop"), '' for the signed-in user's OneDrive
     * @return string the scopes to ask for
     */
    public function scope(string $site): string
    {
        return 'offline_access User.Read Files.ReadWrite.All' . (trim($site) !== '' ? ' Sites.ReadWrite.All' : '');
    }
}
