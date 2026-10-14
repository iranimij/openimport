<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Controller\Adminhtml\Profile;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Api\Data\ProfileInterfaceFactory;
use Iranimij\OpenImport\Api\ProfileRepositoryInterface;
use Iranimij\OpenImport\Model\Admin\FileSample;
use Iranimij\OpenImport\Model\Admin\ProfileFormMapper;
use Iranimij\OpenImport\Model\Execution\SourceInterface;
use Iranimij\OpenImport\Model\Execution\SourceResolver;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Iranimij\OpenImport\Model\Reader\ReaderException;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filesystem;
use Psr\Log\LoggerInterface;

/**
 * "Test connection" in the profile form: checks the source settings as posted (unsaved changes included; stored
 * secrets are used when the posted ones are empty) without importing anything.
 */
class TestSource extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Iranimij_OpenImport::profiles_edit';

    /**
     * @param Context $context
     * @param ProfileInterfaceFactory $profileFactory
     * @param ProfileRepositoryInterface $profiles
     * @param ProfileFormMapper $mapper
     * @param SourceResolver $sources
     * @param LoggerInterface $logger
     * @param Filesystem $filesystem
     * @param FileSample $fileSample
     */
    public function __construct(
        Context $context,
        private readonly ProfileInterfaceFactory $profileFactory,
        private readonly ProfileRepositoryInterface $profiles,
        private readonly ProfileFormMapper $mapper,
        private readonly SourceResolver $sources,
        private readonly LoggerInterface $logger,
        private readonly Filesystem $filesystem,
        private readonly FileSample $fileSample
    ) {
        parent::__construct($context);
    }

    /**
     * @return Json
     */
    public function execute(): Json
    {
        /** @var Json $json */
        $json = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $request = $this->getRequest();
        $data = $request instanceof HttpRequest ? (array) $request->getPostValue() : [];
        $profile = $this->profileFactory->create();
        $profileId = (int) ($data['profile_id'] ?? 0);
        if ($profileId > 0) {
            try {
                $profile = $this->profiles->getById($profileId);
            } catch (NoSuchEntityException $exception) {
                $profile = $this->profileFactory->create();
            }
        }
        try {
            $profile = $this->mapper->fromForm($data, $profile);
            $source = $this->sources->source($profile);
            $message = $source->test($profile);
            $sample = $this->sample($profile, $source);
        } catch (PipelineException $exception) {
            return $json->setData(['ok' => false, 'message' => $exception->getMessage()]);
        } catch (\Throwable $exception) {
            $this->logger->error('OpenImport test connection: ' . $exception->getMessage(), ['exception' => $exception]);

            return $json->setData(['ok' => false, 'message' => (string) __('The test failed unexpectedly: %1', $exception->getMessage())]);
        }

        return $json->setData(['ok' => true, 'message' => $message, 'sample' => $sample]);
    }

    /**
     * The first rows of the file the source would deliver (so the mapping step has columns to work with).
     *
     * @param ProfileInterface $profile
     * @param SourceInterface $source
     * @return array<string, mixed>|null
     */
    private function sample(ProfileInterface $profile, SourceInterface $source): ?array
    {
        if ($profile->getSourceType() === 'upload') {
            return null;
        }
        $var = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $relative = 'openimport/tmp/sample-' . bin2hex(random_bytes(6));
        $var->create($relative);
        try {
            $fetched = $source->fetch($profile, $var->getAbsolutePath($relative));

            return $this->fileSample->read(
                $fetched->path,
                $fetched->name,
                $profile->getFormat(),
                $profile->getFormatOptions()
            );
        } catch (ReaderException $exception) {
            return ['error' => $exception->getMessage()];
        } finally {
            $var->delete($relative);
        }
    }
}
