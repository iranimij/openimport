<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Execution;

use Iranimij\OpenImport\Model\Pipeline\PipelineException;

/**
 * A running run was asked to stop (Cancel on the run page or in the runs grid) and stopped before writing anything.
 */
class RunCancelledException extends PipelineException
{
}
