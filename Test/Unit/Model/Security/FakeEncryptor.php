<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Security;

use Magento\Framework\Encryption\EncryptorInterface;

/**
 * Reversible "encryption" for unit tests: enc(<text>).
 */
class FakeEncryptor implements EncryptorInterface
{
    /**
     * @inheritDoc
     */
    public function encrypt($data)
    {
        return 'enc(' . $data . ')';
    }

    /**
     * @inheritDoc
     */
    public function decrypt($data)
    {
        return preg_replace('/^enc\((.*)\)$/s', '$1', (string) $data);
    }

    /**
     * @inheritDoc
     */
    public function getHash($password, $salt = false)
    {
        return 'hash:' . $password;
    }

    /**
     * @inheritDoc
     */
    public function hash($data)
    {
        return 'hash:' . $data;
    }

    /**
     * @inheritDoc
     */
    public function validateHash($password, $hash)
    {
        return $hash === 'hash:' . $password;
    }

    /**
     * @inheritDoc
     */
    public function isValidHash($password, $hash)
    {
        return $this->validateHash($password, $hash);
    }

    /**
     * @inheritDoc
     */
    public function validateHashVersion($hash, $validateCount = false)
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function validateKey($key)
    {
        throw new \LogicException('Not needed in unit tests.');
    }
}
