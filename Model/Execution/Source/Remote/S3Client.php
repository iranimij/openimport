<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Execution\Source\Remote;

use Iranimij\OpenImport\Model\Http\SafeDownloader;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Iranimij\OpenImport\Model\Security\UrlGuard;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;

/**
 * A bucket of Amazon S3 or an S3-compatible service (Cloudflare R2, Wasabi, MinIO, Garage, ...), with Signature
 * Version 4 and no SDK. Folders are key prefixes: "incoming/stock.csv" is the file stock.csv in the folder incoming.
 * Every request goes through the URL guard (private endpoints only when allowed in the configuration).
 */
class S3Client implements RemoteClientInterface
{
    private const MAX_PAGES = 100;

    /**
     * @var RemoteSettings|null
     */
    private ?RemoteSettings $settings = null;

    /**
     * @param CurlFactory $curlFactory
     * @param UrlGuard $guard
     * @param S3Signer $signer
     * @param File $files
     */
    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly UrlGuard $guard,
        private readonly S3Signer $signer,
        private readonly File $files
    ) {
    }

    /**
     * @inheritDoc
     */
    public function connect(RemoteSettings $settings): void
    {
        foreach (['endpoint', 'region', 'bucket'] as $key) {
            if (($settings->options[$key] ?? '') === '') {
                throw new PipelineException(sprintf('The S3 connection has no %s.', $key));
            }
        }
        if ($settings->username === '' || $settings->password === '') {
            throw new PipelineException('Enter the access key and the secret key of the S3 connection.');
        }
        $this->settings = $settings;
    }

    /**
     * @inheritDoc
     */
    public function listFiles(string $path): array
    {
        $prefix = trim($path, '/');
        $prefix = $prefix === '' ? '' : $prefix . '/';
        $files = [];
        $token = '';
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $query = ['list-type' => '2', 'delimiter' => '/'];
            if ($prefix !== '') {
                $query['prefix'] = $prefix;
            }
            if ($token !== '') {
                $query['continuation-token'] = $token;
            }
            [$status, $body] = $this->request('GET', '', $query);
            if ($status !== 200) {
                throw new PipelineException($this->problem($status, $body, 'The folder ' . ($prefix ?: '/')));
            }
            $document = $this->xml($body);
            foreach ($document->getElementsByTagName('Contents') as $entry) {
                $key = $this->text($entry, 'Key');
                $name = substr($key, strlen($prefix));
                if ($name === '' || str_contains($name, '/')) {
                    continue;
                }
                $modified = strtotime($this->text($entry, 'LastModified'));
                $files[$name] = $modified === false ? null : $modified;
            }
            $token = $this->text($document->documentElement, 'NextContinuationToken');
            if ($this->text($document->documentElement, 'IsTruncated') !== 'true' || $token === '') {
                return $files;
            }
        }

        return $files;
    }

    /**
     * @inheritDoc
     */
    public function download(string $remote, string $local): void
    {
        $handle = $this->files->fileOpen($local, 'w');
        try {
            [$status] = $this->request('GET', $remote, [], [], null, $handle);
        } finally {
            $this->files->fileClose($handle);
        }
        if ($status !== 200) {
            // The body of the error went into the file.
            $body = (string) $this->files->fileGetContents($local);
            $this->files->deleteFile($local);
            throw new PipelineException($this->problem($status, $body, $remote));
        }
    }

    /**
     * @inheritDoc
     */
    public function upload(string $local, string $remote): void
    {
        [$status, $body] = $this->request('PUT', $remote, [], [], $local);
        if ($status !== 200) {
            throw new PipelineException($this->problem($status, $body, $remote));
        }
    }

    /**
     * @inheritDoc
     */
    public function move(string $from, string $to): void
    {
        $source = '/' . $this->option('bucket') . '/' . ltrim($from, '/');
        [$status, $body] = $this->request('PUT', $to, [], [
            'x-amz-copy-source' => $this->signer->encodePath($source),
        ]);
        // A copy can fail after "200 OK" with an <Error> body.
        if ($status !== 200 || str_contains($body, '<Error>')) {
            throw new PipelineException($this->problem($status === 200 ? 500 : $status, $body, $from));
        }
        $this->delete($from);
    }

    /**
     * @inheritDoc
     */
    public function delete(string $remote): void
    {
        [$status, $body] = $this->request('DELETE', $remote);
        if ($status !== 204 && $status !== 200) {
            throw new PipelineException($this->problem($status, $body, $remote));
        }
    }

    /**
     * @inheritDoc
     */
    public function close(): void
    {
        $this->settings = null;
    }

    /**
     * @param string $method
     * @param string $key object key ('' for the bucket)
     * @param array<string, string> $query
     * @param array<string, string> $headers
     * @param string|null $upload local file to send as the body
     * @param resource|null $saveTo where the body goes (downloads)
     * @return array{0: int, 1: string} status and body
     * @throws PipelineException
     */
    private function request(
        string $method,
        string $key,
        array $query = [],
        array $headers = [],
        ?string $upload = null,
        $saveTo = null
    ): array {
        $settings = $this->settings;
        if ($settings === null) {
            throw new PipelineException('The S3 connection is not open.');
        }
        [$base, $host, $path] = $this->address(ltrim($key, '/'));
        $queryString = $this->signer->encodeQuery($query);
        $url = $base . $this->signer->encodePath($path) . ($queryString === '' ? '' : '?' . $queryString);
        [$parts, $address] = $this->guard->check($url);
        $port = (int) ($parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80));
        $payload = $upload === null ? S3Signer::EMPTY_PAYLOAD : (string) hash_file('sha256', $upload);
        $signed = $this->signer->sign(
            $method,
            $host,
            $path,
            $query,
            $headers,
            $payload,
            $settings->username,
            $settings->password,
            $this->option('region'),
            new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
        );
        /** @var Curl $curl */
        $curl = $this->curlFactory->create();
        $curl->setTimeout($settings->timeout);
        $curl->addHeader('User-Agent', SafeDownloader::USER_AGENT);
        foreach ($signed as $name => $value) {
            $curl->addHeader($name, $value);
        }
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(30, $settings->timeout),
            CURLOPT_RESOLVE => [$parts['host'] . ':' . $port . ':' . $address],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ];
        $body = null;
        if ($upload !== null) {
            $body = $this->files->fileOpen($upload, 'r');
            $options[CURLOPT_UPLOAD] = true;
            $options[CURLOPT_INFILE] = $body;
            $options[CURLOPT_INFILESIZE] = (int) ($this->files->stat($upload)['size'] ?? 0);
        } elseif ($method === 'PUT') {
            $curl->addHeader('Content-Length', '0');
        }
        if ($saveTo !== null) {
            $options[CURLOPT_FILE] = $saveTo;
        }
        $curl->setOptions($options);
        try {
            $curl->get($url);
        } catch (\Exception $exception) {
            throw new PipelineException(sprintf(
                '%s could not be reached: %s',
                $settings->label(),
                $exception->getMessage()
            ));
        } finally {
            if ($body !== null) {
                $this->files->fileClose($body);
            }
        }

        return [(int) $curl->getStatus(), (string) $curl->getBody()];
    }

    /**
     * Where a key lives: path style (endpoint/bucket/key) or virtual-hosted style (bucket.endpoint/key).
     *
     * @param string $key
     * @return array{0: string, 1: string, 2: string} scheme://host[:port], Host header, decoded path
     */
    private function address(string $key): array
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- the framework has no URL parser without a request
        $endpoint = parse_url($this->option('endpoint'));
        $scheme = strtolower((string) ($endpoint['scheme'] ?? 'https'));
        $host = strtolower((string) ($endpoint['host'] ?? ''));
        $port = isset($endpoint['port']) ? ':' . (int) $endpoint['port'] : '';
        $bucket = $this->option('bucket');
        if ($this->option('path_style') === '1') {
            return [$scheme . '://' . $host . $port, $host . $port, '/' . $bucket . '/' . $key];
        }

        return [$scheme . '://' . $bucket . '.' . $host . $port, $bucket . '.' . $host . $port, '/' . $key];
    }

    /**
     * @param string $name
     * @return string
     */
    private function option(string $name): string
    {
        return (string) ($this->settings?->options[$name] ?? '');
    }

    /**
     * @param string $xml
     * @return \DOMDocument
     * @throws PipelineException
     */
    private function xml(string $xml): \DOMDocument
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $xml !== '' && $document->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded || $document->documentElement === null) {
            throw new PipelineException(sprintf('%s did not answer with a file list.', $this->settings?->label()));
        }

        return $document;
    }

    /**
     * @param \DOMNode|null $parent
     * @param string $tag
     * @return string text of the first child element with this name
     */
    private function text(?\DOMNode $parent, string $tag): string
    {
        foreach ($parent === null ? [] : $parent->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === $tag) {
                return trim($child->textContent);
            }
        }

        return '';
    }

    /**
     * @param int $status
     * @param string $body
     * @param string $what
     * @return string
     */
    private function problem(int $status, string $body, string $what): string
    {
        $code = '';
        $message = '';
        if (str_contains($body, '<Error>')) {
            try {
                $error = $this->xml($body)->documentElement;
                $code = $this->text($error, 'Code');
                $message = $this->text($error, 'Message');
            } catch (PipelineException $exception) {
                $code = '';
            }
        }
        $hint = match ($code) {
            'NoSuchKey' => 'the file does not exist',
            'NoSuchBucket' => sprintf('there is no bucket "%s"', $this->option('bucket')),
            'AccessDenied' => 'access denied: the key may not read or write there',
            'InvalidAccessKeyId' => 'the access key is unknown',
            'SignatureDoesNotMatch' => 'the secret key does not fit the access key',
            'AuthorizationHeaderMalformed', 'PermanentRedirect'
                => 'the region or the endpoint does not fit the bucket',
            default => $message !== '' ? $message : 'HTTP ' . $status,
        };

        return sprintf('%s on %s: %s.', $what, $this->settings?->label() ?? 'S3', rtrim($hint, '.'));
    }
}
