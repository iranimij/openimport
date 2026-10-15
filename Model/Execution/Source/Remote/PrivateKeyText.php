<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Execution\Source\Remote;

/**
 * Repairs a PEM/OpenSSH private key whose line breaks were lost (pasted into a one-line field, sent by a tool that
 * joins lines): the key loaders need the BEGIN and END lines on their own lines.
 */
class PrivateKeyText
{
    /**
     * @param string $key
     * @return string the key with its BEGIN line, base64 body (64 characters a line) and END line; other text as is
     */
    public function normalize(string $key): string
    {
        $key = trim(str_replace("\r\n", "\n", $key));
        if (!preg_match('/^(-----BEGIN [A-Z0-9 ]+-----)(.*?)(-----END [A-Z0-9 ]+-----)$/s', $key, $parts)) {
            return $key;
        }
        $body = trim($parts[2]);
        $headers = [];
        // Old-style encrypted PEM keys carry "Proc-Type: 4,ENCRYPTED" and "DEK-Info: <cipher>,<hex IV>" before the
        // base64 body; the IV is 16 bytes for AES and 8 for DES, so its end is known even when the lines were joined.
        if (preg_match('/^Proc-Type:\s*(\d,[A-Z]+?)(?=\s|DEK-Info:|$)\s*/', $body, $match)) {
            $headers[] = 'Proc-Type: ' . $match[1];
            $body = (string) substr($body, strlen($match[0]));
        }
        if (preg_match('/^DEK-Info:\s*([A-Z0-9-]+),\s*/', $body, $match)) {
            $length = str_starts_with($match[1], 'AES') ? 32 : 16;
            $headers[] = 'DEK-Info: ' . $match[1] . ',' . substr($body, strlen($match[0]), $length);
            $body = (string) substr($body, strlen($match[0]) + $length);
        }
        $body = (string) preg_replace('/\s+/', '', $body);
        $lines = array_merge([$parts[1]], $headers, $headers === [] ? [] : [''], str_split($body, 64), [$parts[3]]);

        return implode("\n", $lines) . "\n";
    }
}
