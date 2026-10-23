<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Integration\Notification;

use Iranimij\OpenImport\Model\Notification\AttachmentTransportBuilder;
use Magento\Framework\App\Area;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The "E-mail" destination's message carries the rendered template and the file as an attachment, on Magento's
 * Laminas mail (2.4.7) and on its Symfony mail (2.4.8+), whose MimeMessage keeps only the first text part.
 */
#[AppArea('adminhtml')]
class AttachmentTransportBuilderTest extends TestCase
{
    public function testTheExportFileIsAttached(): void
    {
        $content = "sku,name\nOI-MAIL-1,Mail test\n";
        $transport = Bootstrap::getObjectManager()->create(AttachmentTransportBuilder::class)
            ->setTemplateIdentifier('openimport_export_file')
            ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => 1])
            ->setTemplateVars([
                'profile_name' => 'Mail test',
                'file_name' => 'products.csv',
                'rows' => '1',
                'subject' => 'OpenImport export: Mail test',
            ])
            ->setFromByScope('general')
            ->addTo(['shop@example.com'])
            ->addAttachment($content, 'products.csv', 'text/csv')
            ->getTransport();

        $message = $transport->getMessage();
        $raw = match (true) {
            method_exists($message, 'getSymfonyMessage') => $message->getSymfonyMessage()->toString(),
            method_exists($message, 'getRawMessage') => $message->getRawMessage(),
            default => $message->toString(),
        };

        self::assertStringContainsString('products.csv', $raw);
        self::assertMatchesRegularExpression('/Content-Disposition: attachment/i', $raw);
        self::assertStringContainsString(substr(base64_encode($content), 0, 40), str_replace(["\r", "\n"], '', $raw));
        self::assertStringContainsString('Mail test', $raw, 'the rendered template is still there');
    }
}
