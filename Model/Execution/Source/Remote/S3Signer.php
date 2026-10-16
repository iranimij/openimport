<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Execution\Source\Remote;

/**
 * AWS Signature Version 4 for S3 requests (header authorization, payload hash in x-amz-content-sha256).
 * See https://docs.aws.amazon.com/AmazonS3/latest/API/sig-v4-header-based-auth.html
 */
class S3Signer
{
    public const ALGORITHM = 'AWS4-HMAC-SHA256';
    public const EMPTY_PAYLOAD = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    /**
     * The URI path as S3 signs it: every segment percent-encoded (RFC 3986), slashes kept.
     *
     * @param string $path decoded path, e.g. /bucket/folder/my file.csv
     * @return string
     */
    public function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    /**
     * The query string as S3 signs it (and as it must be sent): keys sorted, keys and values percent-encoded.
     *
     * @param array<string, string> $query
     * @return string
     */
    public function encodeQuery(array $query): string
    {
        $pairs = [];
        foreach ($query as $key => $value) {
            $pairs[rawurlencode((string) $key)] = rawurlencode((string) $value);
        }
        ksort($pairs, SORT_STRING);
        $parts = [];
        foreach ($pairs as $key => $value) {
            $parts[] = $key . '=' . $value;
        }

        return implode('&', $parts);
    }

    /**
     * The headers to send: the given ones plus x-amz-date, x-amz-content-sha256 and Authorization.
     *
     * @param string $method GET, PUT, DELETE, HEAD
     * @param string $host host header as sent ("bucket.s3.amazonaws.com", "localhost:9000")
     * @param string $path decoded path
     * @param array<string, string> $query
     * @param array<string, string> $headers further headers to sign (range, x-amz-copy-source, ...)
     * @param string $payloadHash hex SHA-256 of the body
     * @param string $accessKey
     * @param string $secretKey
     * @param string $region
     * @param \DateTimeImmutable $now
     * @return array<string, string> header name => value, without Host
     */
    public function sign(
        string $method,
        string $host,
        string $path,
        array $query,
        array $headers,
        string $payloadHash,
        string $accessKey,
        string $secretKey,
        string $region,
        \DateTimeImmutable $now
    ): array {
        $time = $now->setTimezone(new \DateTimeZone('UTC'));
        $amzDate = $time->format('Ymd\THis\Z');
        $day = $time->format('Ymd');
        $headers['x-amz-content-sha256'] = $payloadHash;
        $headers['x-amz-date'] = $amzDate;
        $canonical = ['host' => trim($host)];
        foreach ($headers as $name => $value) {
            $canonical[strtolower(trim((string) $name))] = trim((string) preg_replace('/\s+/', ' ', (string) $value));
        }
        ksort($canonical, SORT_STRING);
        $signedHeaders = implode(';', array_keys($canonical));
        $headerLines = '';
        foreach ($canonical as $name => $value) {
            $headerLines .= $name . ':' . $value . "\n";
        }
        $request = implode("\n", [
            strtoupper($method),
            $this->encodePath($path),
            $this->encodeQuery($query),
            $headerLines,
            $signedHeaders,
            $payloadHash,
        ]);
        $scope = $day . '/' . $region . '/s3/aws4_request';
        $toSign = implode("\n", [self::ALGORITHM, $amzDate, $scope, hash('sha256', $request)]);
        $key = hash_hmac('sha256', $day, 'AWS4' . $secretKey, true);
        $key = hash_hmac('sha256', $region, $key, true);
        $key = hash_hmac('sha256', 's3', $key, true);
        $key = hash_hmac('sha256', 'aws4_request', $key, true);
        $headers['Authorization'] = sprintf(
            '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            self::ALGORITHM,
            $accessKey,
            $scope,
            $signedHeaders,
            hash_hmac('sha256', $toSign, $key)
        );

        return $headers;
    }
}
