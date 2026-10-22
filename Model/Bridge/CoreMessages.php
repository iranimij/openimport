<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Bridge;

/**
 * Core's import messages that leave merchants guessing, said plainly with the column they are about; and every
 * message once per row (core reports some of them twice).
 */
class CoreMessages
{
    /**
     * Core error code => [column, message].
     */
    private const PLAIN = [
        'invalidType' => [
            'product_type',
            'A new product needs a product type: map product_type or give it a fixed value such as "simple" ("Add required fields" in step 4).',
        ],
        'invalidAttrSet' => [
            'attribute_set_code',
            'A new product needs an attribute set that exists, e.g. "Default": map attribute_set_code or give it a fixed value ("Add required fields" in step 4).',
        ],
        'invalidWebsite' => [
            'product_websites',
            'There is no website with this code; use website codes such as "base", separated by commas.',
        ],
        // "Value for 'name' attribute contains incorrect value"
        'invalidAttributeType' => [
            '',
            'Magento does not accept this value for %1: it is empty where a value is required, too long, of the wrong type, or not one of the attribute\'s options.',
        ],
        // "Wrong URL/path used for attribute image"
        'invalidMediaUrlPath' => [
            '',
            'The picture in %1 is neither a web address (http/https) nor a file name in var/import/images (no "../", no paths from the root).',
        ],
        'mediaPathNotAvailable' => [
            '',
            'The picture in %1 is not in var/import/images (the import images folder).',
        ],
    ];

    /**
     * The product validator passes its formatted message as the error code ("Value for 'name' attribute contains
     * incorrect value"): such codes are matched by their template.
     */
    private const FORMATTED = [
        "/^Value for '[a-z0-9_]+' attribute contains incorrect value/" => 'invalidAttributeType',
        '/^Wrong URL\/path used for attribute [a-z0-9_]+/' => 'invalidMediaUrlPath',
    ];

    /**
     * @param RowError[] $errors
     * @return RowError[]
     */
    public function explain(array $errors): array
    {
        $seen = [];
        $explained = [];
        foreach ($errors as $error) {
            $plain = self::PLAIN[$error->code] ?? self::PLAIN[$this->formattedCode($error->code)] ?? null;
            if ($plain !== null) {
                $column = $error->column ?? ($plain[0] !== '' ? $plain[0] : $this->quotedName($error->message));
                $error = new RowError(
                    $error->row,
                    $error->code,
                    (string) __($plain[1], (string) $column),
                    $column === '' ? null : $column,
                    $error->critical
                );
            }
            $key = $error->row . '|' . $error->code . '|' . $error->column . '|' . $error->message;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $explained[] = $error;
        }

        return $explained;
    }

    /**
     * @param string $code
     * @return string the core code of a formatted message, "" for none
     */
    private function formattedCode(string $code): string
    {
        foreach (self::FORMATTED as $pattern => $known) {
            if (preg_match($pattern, $code) === 1) {
                return $known;
            }
        }

        return '';
    }

    /**
     * The attribute core names in its message ("Value for 'name' attribute …", "… used for attribute image").
     */
    private function quotedName(string $message): string
    {
        if (preg_match("/'([a-z0-9_]+)'/", $message, $match) === 1
            || preg_match('/attribute ([a-z0-9_]+)\s*$/', $message, $match) === 1) {
            return $match[1];
        }

        return '';
    }
}
