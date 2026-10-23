<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Expression;

/**
 * Parses an expression once and compiles it into a PHP closure. There is no eval: the closure only calls the
 * operators and functions listed here.
 *
 * Grammar, loosest binding first:
 *
 *     expression     := or
 *     or             := and ("or" and)*
 *     and            := not ("and" not)*
 *     not            := "not" not | comparison
 *     comparison     := concat (("=" | "!=" | "<>" | "<" | "<=" | ">" | ">=") concat)?
 *     concat         := additive ("." additive)*
 *     additive       := multiplicative (("+" | "-") multiplicative)*
 *     multiplicative := unary (("*" | "/" | "%") unary)*
 *     unary          := ("-" | "+") unary | primary
 *     primary        := number | 'text' | {Column} | true | false | value | NAME "(" arguments ")" | "(" expression ")"
 *
 * Arithmetic with an empty value gives an empty value (no price of 0 from an empty cell). Keywords and function names
 * ignore case. `value` is the current value of the column being transformed.
 */
class Parser
{
    private const MAX_LENGTH = 2000;
    private const MAX_DEPTH = 40;

    /**
     * Functions: name => [minimum arguments, maximum arguments (-1 = any)].
     */
    private const FUNCTIONS = [
        'IF' => [2, 3],
        'ROUND' => [1, 2],
        'CONCAT' => [1, -1],
        'LOWER' => [1, 1],
        'UPPER' => [1, 1],
        'TRIM' => [1, 1],
        'COALESCE' => [1, -1],
        'LEN' => [1, 1],
        'NUMBER' => [1, 1],
        'REPLACE' => [3, 3],
        'CONTAINS' => [2, 2],
        'MIN' => [1, -1],
        'MAX' => [1, -1],
        'ABS' => [1, 1],
    ];

    /**
     * @var Token[]
     */
    private array $tokens = [];

    private int $index = 0;

    private int $depth = 0;

    /**
     * @var array<string, true>
     */
    private array $columns = [];

    private Lexer $lexer;

    private Values $values;

    public function __construct()
    {
        $this->lexer = new Lexer();
        $this->values = new Values();
    }

    /**
     * @param string $source
     * @return Expression
     * @throws ExpressionException
     */
    public function parse(string $source): Expression
    {
        if (trim($source) === '') {
            throw new ExpressionException('The expression is empty.');
        }
        if (strlen($source) > self::MAX_LENGTH) {
            throw new ExpressionException(sprintf('Expressions are limited to %d characters.', self::MAX_LENGTH));
        }
        $this->tokens = $this->lexer->tokenize($source);
        $this->index = 0;
        $this->depth = 0;
        $this->columns = [];
        $node = $this->orExpression();
        $token = $this->current();
        if (!$token->is(Token::END)) {
            throw $this->unexpected($token);
        }

        return new Expression($source, $node, array_map('strval', array_keys($this->columns)), $this->values);
    }

    /**
     * Supported function names (for the expression help in the admin).
     *
     * @return string[]
     */
    public function functionNames(): array
    {
        return array_keys(self::FUNCTIONS);
    }

    private function orExpression(): \Closure
    {
        $left = $this->andExpression();
        while ($this->current()->is(Token::NAME, 'or')) {
            $this->index++;
            $right = $this->andExpression();
            $values = $this->values;
            $left = static fn (array $row, string $value): bool
                => $values->truthy($left($row, $value)) || $values->truthy($right($row, $value));
        }

        return $left;
    }

    private function andExpression(): \Closure
    {
        $left = $this->notExpression();
        while ($this->current()->is(Token::NAME, 'and')) {
            $this->index++;
            $right = $this->notExpression();
            $values = $this->values;
            $left = static fn (array $row, string $value): bool
                => $values->truthy($left($row, $value)) && $values->truthy($right($row, $value));
        }

        return $left;
    }

    private function notExpression(): \Closure
    {
        if ($this->current()->is(Token::NAME, 'not')) {
            $this->index++;
            $this->enter();
            $inner = $this->notExpression();
            $this->depth--;
            $values = $this->values;

            return static fn (array $row, string $value): bool => !$values->truthy($inner($row, $value));
        }

        return $this->comparison();
    }

    private function comparison(): \Closure
    {
        $left = $this->concat();
        $token = $this->current();
        if (!$token->is(Token::OPERATOR) || !in_array($token->text, ['=', '!=', '<', '<=', '>', '>='], true)) {
            return $left;
        }
        $this->index++;
        $right = $this->concat();
        $values = $this->values;
        $operator = $token->text;

        return static function (array $row, string $value) use ($left, $right, $values, $operator): bool {
            $result = $values->compare($left($row, $value), $right($row, $value));

            return match ($operator) {
                '=' => $result === 0,
                '!=' => $result !== 0,
                '<' => $result < 0,
                '<=' => $result <= 0,
                '>' => $result > 0,
                default => $result >= 0,
            };
        };
    }

    private function concat(): \Closure
    {
        $left = $this->additive();
        while ($this->current()->is(Token::OPERATOR, '.')) {
            $this->index++;
            $right = $this->additive();
            $values = $this->values;
            $left = static fn (array $row, string $value): string
                => $values->text($left($row, $value)) . $values->text($right($row, $value));
        }

        return $left;
    }

    private function additive(): \Closure
    {
        $left = $this->multiplicative();
        while ($this->current()->is(Token::OPERATOR, '+') || $this->current()->is(Token::OPERATOR, '-')) {
            $operator = $this->current()->text;
            $this->index++;
            $left = $this->arithmetic($operator, $left, $this->multiplicative());
        }

        return $left;
    }

    private function multiplicative(): \Closure
    {
        $left = $this->unary();
        while (in_array($this->current()->text, ['*', '/', '%'], true) && $this->current()->is(Token::OPERATOR)) {
            $operator = $this->current()->text;
            $this->index++;
            $left = $this->arithmetic($operator, $left, $this->unary());
        }

        return $left;
    }

    private function arithmetic(string $operator, \Closure $left, \Closure $right): \Closure
    {
        $values = $this->values;

        return static function (array $row, string $value) use ($operator, $left, $right, $values): float|string {
            $a = $values->number($left($row, $value));
            $b = $values->number($right($row, $value));
            if ($a === null || $b === null) {
                return '';
            }
            if (($operator === '/' || $operator === '%') && $b == 0.0) {
                throw new EvaluationException('Division by zero.');
            }

            return match ($operator) {
                '+' => $a + $b,
                '-' => $a - $b,
                '*' => $a * $b,
                '/' => $a / $b,
                default => fmod($a, $b),
            };
        };
    }

    private function unary(): \Closure
    {
        $token = $this->current();
        if ($token->is(Token::OPERATOR, '-') || $token->is(Token::OPERATOR, '+')) {
            $this->index++;
            $this->enter();
            $inner = $this->unary();
            $this->depth--;
            $values = $this->values;
            $negate = $token->text === '-';

            return static function (array $row, string $value) use ($inner, $values, $negate): float|string {
                $number = $values->number($inner($row, $value));
                if ($number === null) {
                    return '';
                }

                return $negate ? -$number : $number;
            };
        }

        return $this->primary();
    }

    private function primary(): \Closure
    {
        $token = $this->current();
        $this->index++;
        switch ($token->type) {
            case Token::NUMBER:
                // Whole numbers stay exact (long article numbers); only decimals become floats.
                $whole = preg_match('/^\d+$/', $token->text) === 1;
                $number = match (true) {
                    $whole && strlen($token->text) <= 18 => (int) $token->text,
                    $whole => $token->text,
                    default => (float) $token->text,
                };
                return static fn (): int|float|string => $number;
            case Token::STRING:
                $text = $token->text;
                return static fn (): string => $text;
            case Token::COLUMN:
                $name = $token->text;
                $this->columns[$name] = true;
                return static fn (array $row): string => isset($row[$name]) ? (string) $row[$name] : '';
            case Token::NAME:
                return $this->name($token);
            case Token::OPERATOR:
                if ($token->text === '(') {
                    $this->enter();
                    $inner = $this->orExpression();
                    $this->depth--;
                    $this->expect(')');
                    return $inner;
                }
        }

        throw $this->unexpected($token);
    }

    private function name(Token $token): \Closure
    {
        $name = strtoupper($token->text);
        if (!$this->current()->is(Token::OPERATOR, '(')) {
            return match ($name) {
                'TRUE' => static fn (): bool => true,
                'FALSE' => static fn (): bool => false,
                'VALUE' => static fn (array $row, string $value): string => $value,
                default => throw new ExpressionException(sprintf(
                    'Unknown name "%s" at position %d. Write column names in braces: {%s}.',
                    $token->text,
                    $token->position,
                    $token->text
                )),
            };
        }
        if (!isset(self::FUNCTIONS[$name])) {
            throw new ExpressionException(sprintf(
                'Unknown function %s at position %d. Functions: %s.',
                $token->text,
                $token->position,
                implode(', ', array_keys(self::FUNCTIONS))
            ));
        }
        $this->index++;
        $this->enter();
        $arguments = [];
        if (!$this->current()->is(Token::OPERATOR, ')')) {
            $arguments[] = $this->orExpression();
            while ($this->current()->is(Token::OPERATOR, ',')) {
                $this->index++;
                $arguments[] = $this->orExpression();
            }
        }
        $this->depth--;
        $this->expect(')');
        [$minimum, $maximum] = self::FUNCTIONS[$name];
        $count = count($arguments);
        if ($count < $minimum || ($maximum >= 0 && $count > $maximum)) {
            throw new ExpressionException(match (true) {
                $minimum === $maximum && $minimum === 1
                    => sprintf('%s at position %d takes 1 argument, not %d.', $name, $token->position, $count),
                $minimum === $maximum => sprintf(
                    '%s at position %d takes %d arguments, not %d.',
                    $name,
                    $token->position,
                    $minimum,
                    $count
                ),
                $maximum < 0 && $minimum === 1
                    => sprintf('%s at position %d takes at least 1 argument, not %d.', $name, $token->position, $count),
                $maximum < 0 => sprintf(
                    '%s at position %d takes at least %d arguments, not %d.',
                    $name,
                    $token->position,
                    $minimum,
                    $count
                ),
                default => sprintf(
                    '%s at position %d takes %d to %d arguments, not %d.',
                    $name,
                    $token->position,
                    $minimum,
                    $maximum,
                    $count
                ),
            });
        }

        return $this->function($name, $arguments);
    }

    /**
     * @param string $name
     * @param \Closure[] $arguments
     * @return \Closure
     */
    private function function(string $name, array $arguments): \Closure
    {
        $values = $this->values;
        $all = static fn (array $row, string $value): array
            => array_map(static fn (\Closure $argument): mixed => $argument($row, $value), $arguments);

        return match ($name) {
            'IF' => static fn (array $row, string $value): mixed => $values->truthy($arguments[0]($row, $value))
                ? $arguments[1]($row, $value)
                : (isset($arguments[2]) ? $arguments[2]($row, $value) : ''),
            'ROUND' => static function (array $row, string $value) use ($arguments, $values): float|string {
                $number = $values->number($arguments[0]($row, $value));
                $decimals = isset($arguments[1]) ? (int) $values->number($arguments[1]($row, $value)) : 0;

                return $number === null ? '' : round($number, max(-10, min(10, $decimals)));
            },
            'CONCAT' => static fn (array $row, string $value): string
                => implode('', array_map([$values, 'text'], $all($row, $value))),
            'LOWER' => static fn (array $row, string $value): string
                => mb_strtolower($values->text($arguments[0]($row, $value))),
            'UPPER' => static fn (array $row, string $value): string
                => mb_strtoupper($values->text($arguments[0]($row, $value))),
            'TRIM' => static fn (array $row, string $value): string => trim($values->text($arguments[0]($row, $value))),
            'COALESCE' => static function (array $row, string $value) use ($arguments, $values): mixed {
                foreach ($arguments as $argument) {
                    $result = $argument($row, $value);
                    if (trim($values->text($result)) !== '') {
                        return $result;
                    }
                }
                return '';
            },
            'LEN' => static fn (array $row, string $value): int => mb_strlen($values->text($arguments[0]($row, $value))),
            'NUMBER' => static fn (array $row, string $value): float|string
                => $values->number($arguments[0]($row, $value)) ?? '',
            'REPLACE' => static fn (array $row, string $value): string => str_replace(
                $values->text($arguments[1]($row, $value)),
                $values->text($arguments[2]($row, $value)),
                $values->text($arguments[0]($row, $value))
            ),
            'CONTAINS' => static fn (array $row, string $value): bool => str_contains(
                mb_strtolower($values->text($arguments[0]($row, $value))),
                mb_strtolower($values->text($arguments[1]($row, $value)))
            ),
            'MIN', 'MAX' => static function (array $row, string $value) use ($all, $values, $name): float|string {
                $numbers = array_filter(
                    array_map([$values, 'number'], $all($row, $value)),
                    static fn (?float $number): bool => $number !== null
                );
                if ($numbers === []) {
                    return '';
                }
                return $name === 'MIN' ? min($numbers) : max($numbers);
            },
            default => static function (array $row, string $value) use ($arguments, $values): float|string {
                $number = $values->number($arguments[0]($row, $value));

                return $number === null ? '' : abs($number);
            },
        };
    }

    private function current(): Token
    {
        return $this->tokens[$this->index] ?? $this->tokens[count($this->tokens) - 1];
    }

    private function expect(string $operator): void
    {
        $token = $this->current();
        if (!$token->is(Token::OPERATOR, $operator)) {
            throw $token->is(Token::END)
                ? new ExpressionException(sprintf('Missing "%s" at the end of the expression.', $operator))
                : $this->unexpected($token);
        }
        $this->index++;
    }

    private function enter(): void
    {
        if (++$this->depth > self::MAX_DEPTH) {
            throw new ExpressionException(sprintf('The expression is nested more than %d levels deep.', self::MAX_DEPTH));
        }
    }

    private function unexpected(Token $token): ExpressionException
    {
        if ($token->is(Token::END)) {
            return new ExpressionException('The expression ends too early.');
        }
        $shown = match ($token->type) {
            Token::STRING => "'" . $token->text . "'",
            Token::COLUMN => '{' . $token->text . '}',
            default => $token->text,
        };

        return new ExpressionException(sprintf('Unexpected "%s" at position %d.', $shown, $token->position));
    }
}
