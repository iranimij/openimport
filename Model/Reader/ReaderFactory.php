<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Reader;

/**
 * Picks the reader for a file: the profile's format when set, else the extension, else the first bytes.
 */
class ReaderFactory
{
    public const FORMAT_AUTO = 'auto';
    public const FORMAT_CSV = 'csv';
    public const FORMAT_XLSX = 'xlsx';
    public const FORMAT_ODS = 'ods';
    public const FORMAT_JSON = 'json';
    public const FORMAT_XML = 'xml';

    public const FORMATS = [self::FORMAT_CSV, self::FORMAT_XLSX, self::FORMAT_ODS, self::FORMAT_JSON, self::FORMAT_XML];

    private const EXTENSIONS = [
        'csv' => self::FORMAT_CSV,
        'tsv' => self::FORMAT_CSV,
        'txt' => self::FORMAT_CSV,
        'xlsx' => self::FORMAT_XLSX,
        'ods' => self::FORMAT_ODS,
        'json' => self::FORMAT_JSON,
        'xml' => self::FORMAT_XML,
    ];

    /**
     * @param string $file absolute path
     * @param string $format auto or one of self::FORMATS
     * @param ReaderOptions|null $options
     * @return ReaderInterface
     */
    public function create(string $file, string $format = self::FORMAT_AUTO, ?ReaderOptions $options = null): ReaderInterface
    {
        $options ??= new ReaderOptions();
        $format = $format === self::FORMAT_AUTO || $format === '' ? $this->detect($file) : strtolower($format);

        return match ($format) {
            self::FORMAT_CSV => new CsvReader($file, $options),
            self::FORMAT_XLSX => new XlsxReader($file, $options),
            self::FORMAT_ODS => new OdsReader($file, $options),
            self::FORMAT_JSON => new JsonReader($file, $options),
            self::FORMAT_XML => new XmlReader($file, $options),
            default => throw new ReaderException(sprintf('The file format "%s" is not supported.', $format)),
        };
    }

    /**
     * Options from a profile's stored format options; unknown keys are ignored, wrong types fall back to defaults.
     *
     * @param array<string, mixed> $data
     * @return ReaderOptions
     */
    public function options(array $data): ReaderOptions
    {
        $string = static fn (string $key, string $default): string
            => isset($data[$key]) && is_scalar($data[$key]) ? (string) $data[$key] : $default;

        return new ReaderOptions(
            $string('delimiter', ReaderOptions::AUTO) === '\t' ? "\t" : $string('delimiter', ReaderOptions::AUTO),
            $string('enclosure', '"') === '' ? '"' : $string('enclosure', '"'),
            $string('encoding', ReaderOptions::AUTO),
            $string('sheet', ''),
            $string('record_path', ''),
            !isset($data['has_header']) || (bool) $data['has_header'],
            isset($data['max_bytes']) && is_numeric($data['max_bytes']) ? max(1024, (int) $data['max_bytes']) : 52428800,
            isset($data['sample_size']) && is_numeric($data['sample_size']) ? max(1, (int) $data['sample_size']) : 1000
        );
    }

    /**
     * The format of a file from its name, or from its first bytes when the name says nothing.
     *
     * @param string $file
     * @param string|null $name original file name (uploads and downloads are stored under temporary names)
     * @return string
     */
    public function detect(string $file, ?string $name = null): string
    {
        $extension = strtolower((new \SplFileInfo($name ?? $file))->getExtension());
        if (isset(self::EXTENSIONS[$extension])) {
            return self::EXTENSIONS[$extension];
        }
        if ($extension === 'xls') {
            throw new ReaderException('Old Excel files (.xls) are not supported. Save the file as .xlsx or .csv.');
        }
        $info = new \SplFileInfo($file);
        if (!$info->isFile() || !$info->isReadable()) {
            throw new ReaderException(sprintf('The file "%s" cannot be read.', $info->getFilename()));
        }
        $head = $info->getSize() > 0 ? $info->openFile('r')->fread(512) : '';

        return $this->sniff((string) $head);
    }

    /**
     * @param string $head the first bytes of a file
     * @return string
     */
    public function sniff(string $head): string
    {
        if (str_starts_with($head, "PK\x03\x04")) {
            // An ODS file starts with its uncompressed "mimetype" entry, so the type is readable in the first bytes.
            return str_contains($head, 'application/vnd.oasis.opendocument.spreadsheet')
                ? self::FORMAT_ODS
                : self::FORMAT_XLSX;
        }
        if (str_starts_with($head, "\xD0\xCF\x11\xE0")) {
            throw new ReaderException('Old Excel files (.xls) are not supported. Save the file as .xlsx or .csv.');
        }
        $text = ltrim(str_starts_with($head, "\xEF\xBB\xBF") ? substr($head, 3) : $head);

        return match (true) {
            str_starts_with($text, '{'), str_starts_with($text, '[') => self::FORMAT_JSON,
            str_starts_with($text, '<') => self::FORMAT_XML,
            default => self::FORMAT_CSV,
        };
    }
}
