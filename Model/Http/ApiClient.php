<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Http;

use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Iranimij\OpenImport\Model\Security\UrlGuard;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;

/**
 * Requests to web APIs (Google, later Dropbox, Microsoft, REST sources) through the URL guard: the checked address is
 * the one connected to, no redirects are followed, the body can come from a file and the answer can go into one.
 */
class ApiClient
{
    /**
     * @param CurlFactory $curlFactory
     * @param UrlGuard $guard
     * @param File $files
     */
    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly UrlGuard $guard,
        private readonly File $files
    ) {
    }

    /**
     * @param string $method GET, POST, PUT, PATCH, DELETE
     * @param string $url
     * @param array<string, string> $headers
     * @param string|null $body the request body
     * @param string|null $bodyFile a local file as the request body (instead of $body)
     * @param string|null $saveTo a local file for the answer's body (downloads)
     * @param int $timeout seconds
     * @return ApiResponse
     * @throws PipelineException when the address is refused or cannot be reached
     */
    public function send(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        ?string $bodyFile = null,
        ?string $saveTo = null,
        int $timeout = 60
    ): ApiResponse {
        [$parts, $address] = $this->guard->check($url);
        $port = (int) ($parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80));
        /** @var Curl $curl */
        $curl = $this->curlFactory->create();
        $curl->setTimeout(max(1, min(600, $timeout)));
        // Own header lines: "Expect:" turns off curl's 100-continue, which some servers do not answer.
        $lines = ['User-Agent: ' . SafeDownloader::USER_AGENT, 'Expect:'];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $options = [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(30, $timeout),
            CURLOPT_RESOLVE => [$parts['host'] . ':' . $port . ':' . $address],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ];
        $in = null;
        $out = null;
        if ($bodyFile !== null) {
            $in = $this->files->fileOpen($bodyFile, 'r');
            $options[CURLOPT_UPLOAD] = true;
            $options[CURLOPT_INFILE] = $in;
            $options[CURLOPT_INFILESIZE] = (int) ($this->files->stat($bodyFile)['size'] ?? 0);
        } elseif ($body === null && in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'], true)) {
            $lines[] = 'Content-Length: 0';
        }
        $options[CURLOPT_HTTPHEADER] = $lines;
        if ($saveTo !== null) {
            $out = $this->files->fileOpen($saveTo, 'w');
            $options[CURLOPT_FILE] = $out;
        }
        $curl->setOptions($options);
        try {
            if ($body !== null) {
                $curl->post($url, $body);
            } else {
                $curl->get($url);
            }
        } catch (\Exception $exception) {
            throw new PipelineException(
                sprintf('%s could not be reached: %s', $parts['host'], $exception->getMessage())
            );
        } finally {
            if ($in !== null) {
                $this->files->fileClose($in);
            }
            if ($out !== null) {
                $this->files->fileClose($out);
            }
        }
        $answer = [];
        foreach ($curl->getHeaders() as $name => $value) {
            $answer[strtolower((string) $name)] = is_array($value) ? (string) end($value) : (string) $value;
        }
        $text = $saveTo !== null && ($curl->getStatus() < 200 || $curl->getStatus() >= 300)
            ? (string) $this->files->fileGetContents($saveTo)
            : (string) $curl->getBody();

        return new ApiResponse((int) $curl->getStatus(), $text, $answer);
    }
}
