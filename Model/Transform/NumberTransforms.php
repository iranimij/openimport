<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Transform;

use Iranimij\OpenImport\Model\Value\NumberParser;
use Iranimij\OpenImport\Model\Value\NumberText;

/**
 * Number transforms: decimal_comma, number, math, round, price_adjust. Empty values stay empty; text that is not a
 * number is a row error.
 */
class NumberTransforms implements TransformProviderInterface
{
    private const OPERATORS = [
        '+' => '+', 'add' => '+',
        '-' => '-', '−' => '-', 'subtract' => '-',
        '*' => '*', '×' => '*', 'x' => '*', 'multiply' => '*',
        '/' => '/', '÷' => '/', 'divide' => '/',
    ];

    private NumberParser $parser;

    private NumberText $text;

    public function __construct()
    {
        $this->parser = new NumberParser();
        $this->text = new NumberText();
    }

    /**
     * @inheritDoc
     */
    public function names(): array
    {
        return ['decimal_comma', 'number', 'math', 'round', 'price_adjust'];
    }

    /**
     * @inheritDoc
     */
    public function compile(string $name, Arguments $arguments, TransformScope $scope): \Closure
    {
        return match ($name) {
            'decimal_comma' => $this->decimalComma($arguments),
            'number' => $this->number($arguments),
            'math' => $this->math($arguments),
            'round' => $this->round($arguments),
            'price_adjust' => $this->priceAdjust($arguments),
            default => throw new TransformException(sprintf('Unknown transform "%s".', $name)),
        };
    }

    /**
     * "1.234,56" → "1234.56": the file writes decimals with a comma and may group thousands with dots.
     */
    private function decimalComma(Arguments $arguments): \Closure
    {
        $arguments->expectCount(0, 0);

        return fn (string $value): string => $this->apply(
            $value,
            'decimal_comma',
            fn (float $number): string => $this->text->fromFloat($number),
            true
        );
    }

    /**
     * number(decimals): a clean number; with decimals, always that many ("5" → "5.00").
     */
    private function number(Arguments $arguments): \Closure
    {
        $arguments->expectCount(0, 2);
        $decimals = $arguments->count() > 0 ? $arguments->integer(0, 2, 0, 10) : null;
        $separator = $arguments->count() > 1 ? trim($arguments->string(1)) : '.';
        if (!in_array($separator, ['.', ','], true)) {
            throw $arguments->error('the decimal separator must be "." or ","');
        }

        return fn (string $value): string => $this->apply(
            $value,
            'number',
            fn (float $number): string => $decimals === null
                ? str_replace('.', $separator, $this->text->fromFloat($number))
                : number_format($this->roundHalfUp($number, $decimals), $decimals, $separator, '')
        );
    }

    private function math(Arguments $arguments): \Closure
    {
        $arguments->expectCount(2, 2);
        $operator = self::OPERATORS[strtolower(trim($arguments->string(0)))] ?? null;
        if ($operator === null) {
            throw $arguments->error('the operator must be +, -, * or /');
        }
        $operand = $arguments->number(1);
        if ($operator === '/' && $operand == 0.0) {
            throw $arguments->error('division by zero');
        }

        return fn (string $value): string => $this->apply(
            $value,
            'math',
            fn (float $number): string => $this->text->fromFloat(match ($operator) {
                '+' => $number + $operand,
                '-' => $number - $operand,
                '*' => $number * $operand,
                default => $number / $operand,
            })
        );
    }

    /**
     * round(decimals, mode, ending): mode half_up (default), up, down, or ending: the next price ending in the given
     * cents, e.g. round(2, ending, .99) makes 12.30 → 12.99 and 12.99 → 12.99.
     */
    private function round(Arguments $arguments): \Closure
    {
        $arguments->expectCount(0, 3);
        $decimals = $arguments->integer(0, 2, 0, 10);
        $mode = $arguments->choice(1, ['half_up', 'up', 'down', 'ending'], 'half_up');
        $ending = null;
        if ($mode === 'ending') {
            $ending = $arguments->number(2);
            if ($ending < 0 || $ending >= 1) {
                throw $arguments->error('the ending must be between 0 and 1, e.g. .99');
            }
        } elseif ($arguments->count() > 2) {
            throw $arguments->error('an ending is only used with mode "ending"');
        }

        return fn (string $value): string => $this->apply(
            $value,
            'round',
            fn (float $number): string => number_format(
                $this->roundBy($number, $decimals, $mode, $ending),
                $decimals,
                '.',
                ''
            )
        );
    }

    /**
     * price_adjust(percent, decimals): +10 raises by 10 %, -5 lowers by 5 %, rounded half up.
     */
    private function priceAdjust(Arguments $arguments): \Closure
    {
        $arguments->expectCount(1, 2);
        $percent = $arguments->number(0);
        $decimals = $arguments->integer(1, 2, 0, 10);
        if ($percent <= -100) {
            throw $arguments->error('the percentage must be above -100');
        }

        return fn (string $value): string => $this->apply(
            $value,
            'price_adjust',
            fn (float $number): string => number_format(
                $this->roundHalfUp($number * (1 + $percent / 100), $decimals),
                $decimals,
                '.',
                ''
            )
        );
    }

    private function roundBy(float $number, int $decimals, string $mode, ?float $ending): float
    {
        $factor = 10 ** $decimals;
        // Clean binary noise first, so 1.1 * 100 is 110 and not 110.00000000000001.
        $scaled = round($number * $factor, 6);

        return match ($mode) {
            'up' => ceil($scaled) / $factor,
            'down' => floor($scaled) / $factor,
            'ending' => $this->ending($number, (float) $ending),
            default => $this->roundHalfUp($number, $decimals),
        };
    }

    private function ending(float $number, float $ending): float
    {
        $candidate = floor($number) + $ending;
        if (round($candidate, 6) < round($number, 6)) {
            $candidate += 1;
        }

        return round($candidate, 6);
    }

    private function roundHalfUp(float $number, int $decimals): float
    {
        return round(round($number, 9), $decimals, PHP_ROUND_HALF_UP);
    }

    /**
     * @param string $value
     * @param string $transform
     * @param \Closure $format fn (float $number): string
     * @param bool $decimalComma
     * @return string
     * @throws TransformException
     */
    private function apply(string $value, string $transform, \Closure $format, bool $decimalComma = false): string
    {
        if (trim($value) === '') {
            return '';
        }
        $number = $this->parser->parse($value, $decimalComma);
        if ($number === null) {
            throw new TransformException(sprintf(
                '%s: "%s" is not a number.',
                $transform,
                mb_strimwidth($value, 0, 40, '…')
            ));
        }

        return $format($number);
    }
}
