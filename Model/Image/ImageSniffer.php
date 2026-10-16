<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Image;

/**
 * Tells a picture's type from its first bytes, not from the address or the Content-Type a server claims: shops
 * often send pictures as application/octet-stream, and an error page sent with status 200 is no picture.
 */
class ImageSniffer
{
    /**
     * Types Magento's product import accepts (Magento\CatalogImportExport\Model\Import\Uploader).
     */
    public const SUPPORTED = ['jpg', 'png', 'gif'];

    /**
     * Bytes type() needs.
     */
    public const HEAD_BYTES = 32;

    /**
     * @param string $head the first bytes of the file (HEAD_BYTES are enough)
     * @return string jpg, png, gif, webp, avif, svg, html or '' when unknown
     */
    public function type(string $head): string
    {
        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            return 'jpg';
        }
        if (str_starts_with($head, "\x89PNG\r\n\x1A\n")) {
            return 'png';
        }
        if (str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a')) {
            return 'gif';
        }
        if (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') {
            return 'webp';
        }
        if (substr($head, 4, 4) === 'ftyp' && in_array(substr($head, 8, 4), ['avif', 'avis'], true)) {
            return 'avif';
        }
        $text = strtolower(ltrim(preg_replace('/^\xEF\xBB\xBF/', '', $head) ?? $head));
        if (str_starts_with($text, '<svg') || (str_starts_with($text, '<?xml') && str_contains($text, '<svg'))) {
            return 'svg';
        }
        if (str_starts_with($text, '<!doctype html') || str_starts_with($text, '<html')
            || str_starts_with($text, '<head') || str_starts_with($text, '<body')) {
            return 'html';
        }

        return '';
    }

    /**
     * Why a file of this type cannot be a product picture ('' when it can).
     *
     * @param string $type a result of type()
     * @return string
     */
    public function problem(string $type): string
    {
        return match ($type) {
            'jpg', 'png', 'gif' => '',
            'html' => 'The address answered with a web page, not a picture.',
            '' => 'The address did not answer with a picture (JPG, PNG or GIF).',
            default => sprintf(
                'The picture is %s; Magento\'s product import accepts JPG, PNG and GIF.',
                strtoupper($type)
            ),
        };
    }
}
