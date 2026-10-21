<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Xslt;

use Iranimij\OpenImport\Model\Reader\ReaderException;
use Magento\Framework\Filesystem\Driver\File;

/**
 * Runs an XSLT 1.0 stylesheet (ext-xsl, required by Magento) over an XML file: before the XML reader on imports
 * (format option "xslt"), after the XML writer on exports (export option "xslt").
 *
 * Safety: the stylesheet and the document are parsed without network access and without DOCTYPE (no entities);
 * the processor may read nothing from disk or the network (no document(), xsl:include, xsl:import) and write
 * nothing; PHP functions are not registered. Files larger than the limit (64 MB by default) are refused: a DOM
 * holds the whole document.
 */
class XsltStep
{
    public const OPTION = 'xslt';

    /**
     * @param File $files
     * @param int $maxBytes
     */
    public function __construct(
        private readonly File $files,
        private readonly int $maxBytes = 67108864
    ) {
    }

    /**
     * Whether a stylesheet is set and the file is XML (by the profile's format, else by its name).
     *
     * @param string $format the profile's format (auto, xml, …)
     * @param string $name the file's name
     * @param array<string, mixed> $options format or export options
     * @return bool
     */
    public function applies(string $format, string $name, array $options): bool
    {
        if (trim((string) ($options[self::OPTION] ?? '')) === '') {
            return false;
        }

        return $format === 'xml' || ($format === 'auto' && str_ends_with(strtolower($name), '.xml'));
    }

    /**
     * Checks a stylesheet as the profile form saves it.
     *
     * @param string $stylesheet
     * @return string '' when fine, else the problem
     */
    public function check(string $stylesheet): string
    {
        try {
            $this->processor($stylesheet);
        } catch (ReaderException $exception) {
            return $exception->getMessage();
        }

        return '';
    }

    /**
     * Writes the transformed document to $target.
     *
     * @param string $source absolute path of the XML file
     * @param string $stylesheet the XSLT text
     * @param string $target absolute path of the result
     * @return void
     * @throws ReaderException
     */
    public function transform(string $source, string $stylesheet, string $target): void
    {
        $size = (int) ($this->files->stat($source)['size'] ?? 0);
        if ($size > $this->maxBytes) {
            throw new ReaderException(sprintf(
                'The XML file is %d MB; XSLT runs on files up to %d MB.',
                (int) round($size / 1048576),
                (int) round($this->maxBytes / 1048576)
            ));
        }
        $processor = $this->processor($stylesheet);
        $document = $this->load($this->files->fileGetContents($source), 'The XML file');
        $previous = libxml_use_internal_errors(true);
        try {
            $result = $processor->transformToXml($document);
            $error = libxml_get_last_error();
        } catch (\Error $exception) {
            // PHP 8.4 throws for php:function() calls when no functions are registered (none ever are).
            throw new ReaderException('The XSLT stylesheet failed on this file: ' . $exception->getMessage());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if ($result === false || $result === null) {
            throw new ReaderException(
                'The XSLT stylesheet failed on this file' . ($error ? ': ' . trim($error->message) : '.')
            );
        }
        $this->files->filePutContents($target, $result);
    }

    /**
     * Replaces an XML file by its transformation (exports).
     *
     * @param string $path
     * @param string $stylesheet
     * @return void
     * @throws ReaderException
     */
    public function transformInPlace(string $path, string $stylesheet): void
    {
        $this->transform($path, $stylesheet, $path . '.xslt');
        $this->files->rename($path . '.xslt', $path);
    }

    /**
     * @param string $stylesheet
     * @return \XSLTProcessor
     * @throws ReaderException
     */
    private function processor(string $stylesheet): \XSLTProcessor
    {
        if (!class_exists(\XSLTProcessor::class)) {
            throw new ReaderException('XSLT needs the PHP extension ext-xsl, which is not installed.');
        }
        if (preg_match('/<xsl:(include|import)\b/i', $stylesheet) === 1) {
            throw new ReaderException('The XSLT stylesheet may not include or import other files.');
        }
        $xsl = $this->load($stylesheet, 'The XSLT stylesheet');
        $processor = new \XSLTProcessor();
        $processor->setSecurityPrefs(
            XSL_SECPREF_READ_FILE | XSL_SECPREF_WRITE_FILE | XSL_SECPREF_CREATE_DIRECTORY
            | XSL_SECPREF_READ_NETWORK | XSL_SECPREF_WRITE_NETWORK
        );
        $previous = libxml_use_internal_errors(true);
        try {
            $imported = $processor->importStylesheet($xsl);
            $error = libxml_get_last_error();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$imported) {
            throw new ReaderException(
                'The XSLT stylesheet cannot be used' . ($error ? ': ' . trim($error->message) : '.')
            );
        }

        return $processor;
    }

    /**
     * @param string $xml
     * @param string $what for messages
     * @return \DOMDocument
     * @throws ReaderException
     */
    private function load(string $xml, string $what): \DOMDocument
    {
        if (stripos($xml, '<!DOCTYPE') !== false) {
            throw new ReaderException($what . ' contains a DOCTYPE and is refused.');
        }
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
            $error = libxml_get_last_error();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            throw new ReaderException(
                $what . ' is not well-formed XML' . ($error ? ': ' . trim($error->message) : '.')
            );
        }

        return $document;
    }
}
