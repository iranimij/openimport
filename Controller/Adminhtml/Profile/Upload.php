<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Controller\Adminhtml\Profile;

use Iranimij\OpenImport\Model\Admin\FileSample;
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
     */
    public function __construct(
        Context $context,
        private readonly UploadStore $uploads,
        private readonly FileSample $sample
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
        try {
            $stored = $this->uploads->store('file', $name, self::MAX_BYTES);
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
