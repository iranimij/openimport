<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Controller\Adminhtml\Run;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Backend\Model\View\Result\Page as BackendPage;
use Magento\Framework\View\Result\Page;

/**
 * System › Data Transfer › OpenImport Runs.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Iranimij_OpenImport::runs';

    /**
     * @inheritDoc
     */
    public function execute(): Page
    {
        /** @var BackendPage $page */
        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $page->setActiveMenu('Iranimij_OpenImport::menu_runs');
        $page->getConfig()->getTitle()->prepend((string) __('OpenImport Runs'));

        return $page;
    }
}
