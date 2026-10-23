<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Controller\Adminhtml\Profile;

use Iranimij\OpenImport\Model\Admin\FileAccess;
use Iranimij\OpenImport\Model\Admin\FileSample;
use Iranimij\OpenImport\Model\Admin\UploadLimit;
use Iranimij\OpenImport\Model\Admin\UploadStore;
use Iranimij\OpenImport\Model\Reader\ReaderException;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;

/**
 * Receives a file for an upload profile and answers with its token and a sample of its rows.
 */
class Upload extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Iranimij_OpenImport::profiles_edit';
    private const MAX_BYTES = 268435456;

    /**
     * @param Context $context
     * @param UploadStore $uploads
     * @param FileSample $sample
     * @param FileAccess $fileAccess
     * @param UploadLimit $uploadLimit
     */
    public function __construct(
        Context $context,
        private readonly UploadStore $uploads,
        private readonly FileSample $sample,
        private readonly FileAccess $fileAccess,
        private readonly UploadLimit $uploadLimit
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function execute(): Json
    {
        /** @var Json $json */
        $json = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $request = $this->getRequest();
        $file = $request instanceof HttpRequest ? $request->getFiles('file') : null;
        $name = is_array($file) ? (string) ($file['name'] ?? '') : '';
        if (is_array($file) && in_array((int) ($file['error'] ?? 0), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            return $json->setData(['error' => (string) __(
                // phpcs:ignore Generic.Files.LineLength.TooLong
                'The file is larger than this server accepts (%1 per upload). Zip it (OpenImport reads ZIP and gzip), or use a server folder, web address, SFTP or cloud storage for big files.',
                $this->uploadLimit->text()
            )]);
        }
        try {
            $stored = $this->uploads->store('file', $name, self::MAX_BYTES);
            $this->fileAccess->grant($stored['token']);
            $path = (string) $this->uploads->path($stored['token']);
            $sample = $this->sample->read(
                $path,
                $stored['name'],
                (string) $this->getRequest()->getParam('format', 'auto'),
                []
            );
        } catch (LocalizedException | ReaderException $exception) {
            return $json->setData(['error' => $exception->getMessage()]);
        }

        return $json->setData(['file' => $stored] + $sample);
    }
}
