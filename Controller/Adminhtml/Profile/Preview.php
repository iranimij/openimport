<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Controller\Adminhtml\Profile;

use Iranimij\OpenImport\Api\Data\ProfileInterfaceFactory;
use Iranimij\OpenImport\Model\Admin\FileAccess;
use Iranimij\OpenImport\Model\Admin\MappingPreview;
use Iranimij\OpenImport\Model\Admin\ProfileFormMapper;
use Iranimij\OpenImport\Model\Admin\UploadStore;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;

/**
 * The first rows as Magento will receive them, for the form as it is now (not saved).
 */
class Preview extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Iranimij_OpenImport::profiles';

    /**
     * @param Context $context
     * @param ProfileInterfaceFactory $profileFactory
     * @param ProfileFormMapper $mapper
     * @param UploadStore $uploads
     * @param MappingPreview $preview
     * @param FileAccess $fileAccess
     */
    public function __construct(
        Context $context,
        private readonly ProfileInterfaceFactory $profileFactory,
        private readonly ProfileFormMapper $mapper,
        private readonly UploadStore $uploads,
        private readonly MappingPreview $preview,
        private readonly FileAccess $fileAccess
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
        $data = $request instanceof HttpRequest ? (array) $request->getPostValue() : [];
        $source = is_array($data['source_config'] ?? null) ? $data['source_config'] : [];
        $path = $this->uploads->path((string) ($source['file'] ?? ''));
        if ($path === null) {
            return $json->setData(['problems' => [(string) __('Upload a file to see the preview.')]]);
        }
        if (!$this->fileAccess->canSee((string) ($source['file'] ?? ''))) {
            return $json->setData(['problems' => [(string) __(
                'The preview is not shown: your role may not see file contents ("Download run files").'
            )]]);
        }
        $profile = $this->mapper->fromForm($data, $this->profileFactory->create());

        return $json->setData($this->preview->preview($profile, $path, (string) ($source['file_name'] ?? '')));
    }
}
