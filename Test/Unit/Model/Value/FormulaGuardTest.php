<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Value;

use Iranimij\OpenImport\Model\Value\FormulaGuard;
use PHPUnit\Framework\TestCase;

class FormulaGuardTest extends TestCase
{
    public function testProtectAndUnprotectAreARoundTrip(): void
    {
        $guard = new FormulaGuard();
        foreach (['=SUM(1)', '+49 69 1234', '-2+3', '@cmd', "\tx", '-5', '-1.5', 'plain', "'quoted", '', "'"] as $value) {
            self::assertSame($value, FormulaGuard::unprotect($guard->protect($value)), $value);
        }
        self::assertSame("'+49 69", $guard->protect('+49 69'));
        self::assertSame("It's", FormulaGuard::unprotect("It's"));
        self::assertSame("'plain", FormulaGuard::unprotect("'plain"));
    }
}
