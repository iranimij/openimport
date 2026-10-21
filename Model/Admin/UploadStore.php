<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Admin;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\File\UploaderFactory;
use Magento\Framework\Filesystem;

/**
 * Files uploaded in the admin: stored under var/openimport/uploads/ with a random name, referred to by that name
 * ("token") only, so a request can never point at another file of the server.
 */
class UploadStore
{
    public const FOLDER = 'openimport/uploads';
    public const EXTENSIONS = ['csv', 'tsv', 'txt', 'xlsx', 'json', 'xml', 'zip', 'gz', 'tgz', 'tar'];

    /**
     * @param Filesystem $filesystem
     * @param UploaderFactory $uploaderFactory
     */
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly UploaderFactory $uploaderFactory
    ) {
    }

    /**
     * Stores the file uploaded under the given form field name.
     *
     * @param string $fileId form field name
     * @param string $originalName the name the browser sent
     * @param int $maxBytes
     * @return array{token: string, name: string, size: int}
     * @throws LocalizedException
     */
    public function store(string $fileId, string $originalName, int $maxBytes): array
    {
        try {
            $uploader = $this->uploaderFactory->create(['fileId' => $fileId]);
        } catch (\Exception $exception) {
            throw new LocalizedException(__('The file was not uploaded. Try again.'), $exception);
        }
        $extension = strtolower((string) $uploader->getFileExtension());
        if (!in_array($extension, self::EXTENSIONS, true)) {
            throw new LocalizedException(__(
                'OpenImport reads %1 files; this file is not one of them.',
                implode(', ', self::EXTENSIONS)
            ));
        }
        $token = bin2hex(random_bytes(16)) . '.' . $extension;
        $directory = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $directory->create(self::FOLDER);
        $uploader->setAllowedExtensions(self::EXTENSIONS);
        $uploader->setAllowRenameFiles(false);
        $uploader->setFilesDispersion(false);
        try {
            $saved = $uploader->save($directory->getAbsolutePath(self::FOLDER), $token);
        } catch (\Exception $exception) {
            throw new LocalizedException(__('The file could not be stored: %1', $exception->getMessage()), $exception);
        }
        $size = (int) ($saved['size'] ?? 0);
        if ($size > $maxBytes) {
            $directory->delete(self::FOLDER . '/' . $token);
            throw new LocalizedException(__('The file is larger than %1 MB.', round($maxBytes / 1048576)));
        }
        $name = (new \SplFileInfo(str_replace('\\', '/', $originalName)))->getFilename();

        return ['token' => $token, 'name' => mb_substr($name !== '' ? $name : $token, 0, 255), 'size' => $size];
    }

    /**
     * Absolute path of an uploaded file, or null when the token is not one.
     *
     * @param string $token
     * @return string|null
     */
    public function path(string $token): ?string
    {
        if (preg_match('/^[a-f0-9]{32}\.(' . implode('|', self::EXTENSIONS) . ')$/', $token) !== 1) {
            return null;
        }
        $directory = $this->filesystem->getDirectoryRead(DirectoryList::VAR_DIR);
        $relative = self::FOLDER . '/' . $token;

        return $directory->isFile($relative) ? $directory->getAbsolutePath($relative) : null;
    }
}
