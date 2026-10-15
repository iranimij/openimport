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
            $plain = self::PLAIN[$error->code] ?? null;
            if ($plain !== null) {
                $error = new RowError($error->row, $error->code, (string) __($plain[1]), $error->column ?? $plain[0], $error->critical);
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
}
