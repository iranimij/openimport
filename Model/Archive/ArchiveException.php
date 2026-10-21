<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Archive;

use Iranimij\OpenImport\Model\Reader\ReaderException;

/**
 * An archive cannot be unpacked or packed; the message is shown to the admin as it is.
 */
class ArchiveException extends ReaderException
{
}
