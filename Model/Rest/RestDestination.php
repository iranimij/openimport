<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Rest;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Model\Connection\ConnectionConfig;
use Iranimij\OpenImport\Model\Export\DestinationInterface;
use Iranimij\OpenImport\Model\Http\ApiResponse;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Iranimij\OpenImport\Model\Writer\WriterInterface;
use Magento\Framework\Filesystem\Driver\File;

/**
 * Sends an export to a REST API: the file as it is (POST or PUT, content type of the format), or, for JSON exports,
 * the records in batches of a chosen size (each batch a JSON list).
 */
class RestDestination implements DestinationInterface
{
    public const SEND_FILE = 'file';
    public const SEND_BATCHES = 'batches';

    /**
     * @param RestClient $client
     * @param ConnectionConfig $connections
     * @param File $files
     */
    public function __construct(
        private readonly RestClient $client,
        private readonly ConnectionConfig $connections,
        private readonly File $files
    ) {
    }

    /**
     * @inheritDoc
     */
    public function deliver(ProfileInterface $profile, RunInterface $run, string $path, WriterInterface $writer): string
    {
        $config = $this->connections->resolve($profile->getExportOptions(), 'rest');
        $method = strtoupper((string) ($config['method'] ?? 'POST')) === 'PUT' ? 'PUT' : 'POST';
        $address = trim((string) ($config['path'] ?? ''));
        if (($config['send'] ?? self::SEND_FILE) === self::SEND_BATCHES) {
            $records = json_decode((string) $this->files->fileGetContents($path), true);
            if (!is_array($records) || !array_is_list($records)) {
                throw new PipelineException('Batches need a JSON export (a list of records).');
            }
            $size = max(1, min(10000, (int) ($config['batch_size'] ?? 100)));
            $batches = array_chunk($records, $size);
            foreach ($batches as $number => $batch) {
                $body = (string) json_encode($batch, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $headers = ['Content-Type' => 'application/json'];
                $answer = $this->client->send($config, $method, $address, $headers, $body);
                $this->check($answer, $address, $number + 1);
            }

            return sprintf(
                'Sent %d record(s) in %d batch(es) to %s %s.',
                count($records),
                count($batches),
                $method,
                $this->client->url($config, $address)
            );
        }
        $this->check($this->client->send($config, $method, $address, [
            'Content-Type' => $writer->contentType(),
        ], null, $path), $address, 0);

        return sprintf('Sent the file to %s %s.', $method, $this->client->url($config, $address));
    }

    /**
     * @inheritDoc
     */
    public function test(ProfileInterface $profile): string
    {
        $config = $this->connections->resolve($profile->getExportOptions(), 'rest');
        $url = $this->client->url($config, trim((string) ($config['path'] ?? '')));

        // Sending is what an export does; the test only reaches the base address with the login.
        $answer = $this->client->send($config, 'GET', '');
        if (in_array($answer->status, [401, 403], true)) {
            throw new PipelineException(sprintf('The API refused the login (HTTP %d).', $answer->status));
        }

        return sprintf('Ready: exports go to %s %s.', strtoupper((string) ($config['method'] ?? 'POST')), $url);
    }

    /**
     * @param ApiResponse $answer
     * @param string $address
     * @param int $batch 0 = the file
     * @return void
     * @throws PipelineException
     */
    private function check(ApiResponse $answer, string $address, int $batch): void
    {
        if ($answer->ok()) {
            return;
        }
        $detail = trim(substr(strip_tags($answer->body), 0, 300));
        throw new PipelineException(sprintf(
            'The API refused %s at %s: HTTP %d.%s',
            $batch > 0 ? 'batch ' . $batch : 'the file',
            $address === '' ? '/' : $address,
            $answer->status,
            $detail !== '' ? ' ' . $detail : ''
        ));
    }
}
