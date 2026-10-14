<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Execution\Source\Remote;

use Iranimij\OpenImport\Model\Pipeline\PipelineException;

/**
 * One connection to a file server (SFTP, FTP). Every method throws PipelineException with a plain-words reason.
 */
interface RemoteClientInterface
{
    /**
     * @param RemoteSettings $settings
     * @return void
     * @throws PipelineException
     */
    public function connect(RemoteSettings $settings): void;

    /**
     * Files (not folders) of a remote folder.
     *
     * @param string $path
     * @return array<string, int|null> name => modification time (null when the server does not tell)
     * @throws PipelineException
     */
    public function listFiles(string $path): array;

    /**
     * @param string $remote
     * @param string $local
     * @return void
     * @throws PipelineException
     */
    public function download(string $remote, string $local): void;

    /**
     * Uploads a local file (export destinations).
     *
     * @param string $local
     * @param string $remote
     * @return void
     * @throws PipelineException
     */
    public function upload(string $local, string $remote): void;

    /**
     * Moves a remote file; creates the target folder when it does not exist.
     *
     * @param string $from
     * @param string $to
     * @return void
     * @throws PipelineException
     */
    public function move(string $from, string $to): void;

    /**
     * @param string $remote
     * @return void
     * @throws PipelineException
     */
    public function delete(string $remote): void;

    /**
     * @return void
     */
    public function close(): void;
}
