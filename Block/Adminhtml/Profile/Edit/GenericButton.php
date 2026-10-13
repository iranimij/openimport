<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Block\Adminhtml\Profile\Edit;

use Magento\Backend\Block\Widget\Context;
use Magento\Framework\AuthorizationInterface;

/**
 * Shared bits of the profile form buttons.
 */
class GenericButton
{
    /**
     * @param Context $context
     */
    public function __construct(
        protected readonly Context $context
    ) {
    }

    protected function profileId(): int
    {
        return (int) $this->context->getRequest()->getParam('id');
    }

    /**
     * @param string $route
     * @param array<string, mixed> $params
     * @return string
     */
    protected function url(string $route, array $params = []): string
    {
        return $this->context->getUrlBuilder()->getUrl($route, $params);
    }

    protected function allowed(string $resource): bool
    {
        /** @var AuthorizationInterface $authorization */
        $authorization = $this->context->getAuthorization();

        return $authorization->isAllowed($resource);
    }
}
