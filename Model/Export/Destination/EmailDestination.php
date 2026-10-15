<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Export\Destination;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Model\Export\DestinationInterface;
use Iranimij\OpenImport\Model\Notification\AttachmentTransportBuilder;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Iranimij\OpenImport\Model\Writer\WriterInterface;
use Iranimij\OpenImport\Model\Config;
use Magento\Framework\App\Area;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The file as an e-mail attachment (export_options.emails, comma-separated; subject optional), through the store's
 * mail settings. Files above max_mb (default 10) are not sent: the run says so and keeps the download.
 */
class EmailDestination implements DestinationInterface
{
    private const DEFAULT_MAX_MB = 10;

    /**
     * @param AttachmentTransportBuilder $transportBuilder
     * @param Filesystem $filesystem
     * @param StoreManagerInterface $storeManager
     * @param Config $config
     */
    public function __construct(
        private readonly AttachmentTransportBuilder $transportBuilder,
        private readonly Filesystem $filesystem,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config
    ) {
    }

    /**
     * @inheritDoc
     */
    public function deliver(ProfileInterface $profile, RunInterface $run, string $path, WriterInterface $writer): string
    {
        $recipients = $this->recipients($profile);
        $options = $profile->getExportOptions();
        $driver = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR)->getDriver();
        $stat = $driver->stat($path);
        $bytes = (int) ($stat['size'] ?? 0);
        $maxBytes = max(1, (int) ($options['max_mb'] ?? self::DEFAULT_MAX_MB)) * 1048576;
        if ($bytes > $maxBytes) {
            throw new PipelineException(sprintf(
                'The file is %.1f MB, larger than the %d MB an e-mail may carry; download it from the run page.',
                $bytes / 1048576,
                (int) ($maxBytes / 1048576)
            ));
        }
        $name = (new \SplFileInfo($path))->getFilename();
        $subject = trim((string) ($options['subject'] ?? ''));
        try {
            $this->transportBuilder
                ->setTemplateIdentifier('openimport_export_file')
                ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $this->storeId()])
                ->setTemplateVars([
                    'profile_name' => $profile->getName(),
                    'file_name' => $name,
                    'rows' => number_format($run->getCount('exported')),
                    'subject' => $subject !== '' ? $subject : (string) __('OpenImport export: %1', $profile->getName()),
                ])
                ->setFromByScope($this->config->getEmailIdentity())
                ->addTo($recipients)
                ->addAttachment((string) $driver->fileGetContents($path), $name, $writer->contentType())
                ->getTransport()
                ->sendMessage();
        } catch (\Exception $exception) {
            throw new PipelineException('The e-mail could not be sent: ' . $exception->getMessage());
        }

        return sprintf('Sent to %s.', implode(', ', $recipients));
    }

    /**
     * @inheritDoc
     */
    public function test(ProfileInterface $profile): string
    {
        return sprintf('The file will be e-mailed to %s.', implode(', ', $this->recipients($profile)));
    }

    /**
     * @param ProfileInterface $profile
     * @return string[]
     * @throws PipelineException
     */
    private function recipients(ProfileInterface $profile): array
    {
        $emails = array_values(array_filter(array_map('trim', explode(',', (string) ($profile->getExportOptions()['emails'] ?? '')))));
        if ($emails === []) {
            throw new PipelineException('Enter at least one e-mail address to send the file to.');
        }
        foreach ($emails as $email) {
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new PipelineException(sprintf('"%s" is not an e-mail address.', $email));
            }
        }

        return $emails;
    }

    /**
     * The default store view, which e-mails are rendered for (header and footer templates are frontend ones).
     *
     * @return int
     */
    private function storeId(): int
    {
        try {
            $store = $this->storeManager->getDefaultStoreView();

            return $store === null ? Store::DEFAULT_STORE_ID : (int) $store->getId();
        } catch (\Throwable $exception) {
            return Store::DEFAULT_STORE_ID;
        }
    }
}
