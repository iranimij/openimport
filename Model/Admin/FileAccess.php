<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Admin;

use Magento\Framework\Session\Storage;
use Magento\Framework\AuthorizationInterface;

/**
 * Who may see the contents of a stored file (first rows, the mapping preview): users allowed to download run files,
 * and everyone for the files they uploaded themselves in this admin session. Files can hold customer and order
 * data, so "View profiles" alone shows the column names only.
 */
class FileAccess
{
    public const RESOURCE = 'Iranimij_OpenImport::run_files';

    private const SESSION_KEY = 'openimport_own_uploads';

    private const KEEP = 50;

    /**
     * @param AuthorizationInterface $authorization
     * @param Storage $session the admin session's storage (di.xml: Magento\Backend\Model\Session\Storage)
     */
    public function __construct(
        private readonly AuthorizationInterface $authorization,
        private readonly Storage $session
    ) {
    }

    /**
     * Remembers a file this admin just uploaded.
     *
     * @param string $token the upload token
     * @return void
     */
    public function grant(string $token): void
    {
        $tokens = $this->tokens();
        $tokens[] = $token;
        $this->session->setData(self::SESSION_KEY, array_slice(array_values(array_unique($tokens)), -self::KEEP));
    }

    /**
     * @param string $token the upload token of the file
     * @return bool
     */
    public function canSee(string $token): bool
    {
        return $this->authorization->isAllowed(self::RESOURCE) || in_array($token, $this->tokens(), true);
    }

    /**
     * @return string[]
     */
    private function tokens(): array
    {
        $tokens = $this->session->getData(self::SESSION_KEY);

        return is_array($tokens) ? array_values(array_filter($tokens, 'is_string')) : [];
    }
}
