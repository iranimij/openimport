<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Security;

use Magento\Framework\Encryption\EncryptorInterface;

/**
 * Passwords, private keys and tokens of a profile's source or destination, kept in `source_config.secrets`
 * encrypted with Magento's key (app/etc/env.php). They are never sent back to the admin form, never exported and
 * never logged; the form only shows whether one is stored.
 */
class Secrets
{
    public const KEY = 'secrets';
    public const NAMES = ['password', 'private_key', 'passphrase', 'token', 'secret_key', 'service_account_json',
        'client_secret', 'refresh_token'];

    /**
     * @param EncryptorInterface $encryptor
     */
    public function __construct(
        private readonly EncryptorInterface $encryptor
    ) {
    }

    /**
     * Moves the secret fields of posted form data into the encrypted `secrets` entry. An empty field keeps the
     * stored value; the field `<name>_clear` = 1 removes it.
     *
     * @param array<string, mixed> $posted the posted source_config
     * @param array<string, mixed> $stored the profile's current source_config
     * @return array<string, mixed>
     */
    public function merge(array $posted, array $stored): array
    {
        $secrets = is_array($stored[self::KEY] ?? null) ? $stored[self::KEY] : [];
        foreach (self::NAMES as $name) {
            $value = $posted[$name] ?? '';
            if (!empty($posted[$name . '_clear'])) {
                unset($secrets[$name]);
            } elseif (is_string($value) && $value !== '') {
                $secrets[$name] = $this->encryptor->encrypt($value);
            }
            unset($posted[$name], $posted[$name . '_clear']);
        }
        unset($posted[self::KEY]);
        if ($secrets !== []) {
            $posted[self::KEY] = $secrets;
        }

        return $posted;
    }

    /**
     * The plain value of one secret, or '' when none is stored.
     *
     * @param array<string, mixed> $config a source_config
     * @param string $name
     * @return string
     */
    public function get(array $config, string $name): string
    {
        $value = $config[self::KEY][$name] ?? '';

        return is_string($value) && $value !== '' ? (string) $this->encryptor->decrypt($value) : '';
    }

    /**
     * Which secrets are stored, for the form ("stored, leave empty to keep").
     *
     * @param array<string, mixed> $config
     * @return array<string, bool>
     */
    public function stored(array $config): array
    {
        $stored = [];
        foreach (self::NAMES as $name) {
            $stored[$name] = isset($config[self::KEY][$name]) && $config[self::KEY][$name] !== '';
        }

        return $stored;
    }

    /**
     * A config without its secrets (form data, profile export, logs).
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function strip(array $config): array
    {
        unset($config[self::KEY]);

        return $config;
    }

    /**
     * Encrypts every stored secret again with the current key (after `bin/magento encryption:key:change`).
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function reencrypt(array $config): array
    {
        if (!is_array($config[self::KEY] ?? null)) {
            return $config;
        }
        foreach ($config[self::KEY] as $name => $value) {
            if (is_string($value) && $value !== '') {
                $config[self::KEY][$name] = $this->encryptor->encrypt((string) $this->encryptor->decrypt($value));
            }
        }

        return $config;
    }
}
