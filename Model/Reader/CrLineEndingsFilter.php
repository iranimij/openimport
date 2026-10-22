<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Reader;

// phpcs:disable Magento2.Functions.DiscouragedFunction,Magento2.Functions.StaticFunction,Magento2.Exceptions.TryProcessSystemResources

/**
 * Stream filter that turns CR line breaks into LF, for CSV files from old Mac software and some ERPs (PHP's CSV
 * parser only knows LF and CRLF). Used only for files whose sample has CRs and no LF at all.
 */
class CrLineEndingsFilter extends \php_user_filter
{
    public const NAME = 'openimport.cr-line-endings';

    /**
     * Registers the filter once per process.
     */
    public static function register(): void
    {
        if (!in_array(self::NAME, stream_get_filters(), true)) {
            stream_filter_register(self::NAME, self::class);
        }
    }

    /**
     * @param resource $in
     * @param resource $out
     * @param int $consumed
     * @param bool $closing
     * @return int
     */
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $bucket->data = str_replace("\r", "\n", $bucket->data);
            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }
}
