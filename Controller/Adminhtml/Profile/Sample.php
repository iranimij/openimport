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
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;

/**
 * The first rows of an uploaded file with the format options chosen in the form (re-read when options change).
 */
class Sample extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Iranimij_OpenImport::profiles';

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
        $path = $this->uploads->path((string) $this->getRequest()->getParam('file'));
        if ($path === null) {
            return $json->setData(['error' => (string) __('Upload a file first.')]);
        }
        $options = $this->getRequest()->getParam('format_options');
        try {
            $data = $this->sample->read(
                $path,
                (string) $this->getRequest()->getParam('file_name', ''),
                (string) $this->getRequest()->getParam('format', 'auto'),
                is_array($options) ? $options : []
            );
        } catch (ReaderException $exception) {
            return $json->setData(['error' => $exception->getMessage()]);
        }

        return $json->setData($data);
    }
}
