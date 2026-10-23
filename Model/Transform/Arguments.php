<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Transform;

use Iranimij\OpenImport\Model\Value\NumberParser;

/**
 * A transform's arguments with checked access; errors name the transform.
 */
class Arguments
{
    /**
     * @var array<int, mixed>
     */
    private array $values;

    /**
     * @param string $transform
     * @param array<int|string, mixed> $values
     */
    public function __construct(
        private readonly string $transform,
        array $values
    ) {
        $this->values = array_values($values);
    }

    public function count(): int
    {
        return count($this->values);
    }

    /**
     * @param int $minimum
     * @param int $maximum -1 for no limit
     * @return void
     * @throws TransformException
     */
    public function expectCount(int $minimum, int $maximum): void
    {
        $count = $this->count();
        if ($count < $minimum || ($maximum >= 0 && $count > $maximum)) {
            throw $this->error(match (true) {
                $minimum === $maximum && $minimum === 1 => sprintf('takes 1 argument, %d given', $count),
                $minimum === $maximum => sprintf('takes %d arguments, %d given', $minimum, $count),
                $maximum < 0 && $minimum === 1 => sprintf('takes at least 1 argument, %d given', $count),
                $maximum < 0 => sprintf('takes at least %d arguments, %d given', $minimum, $count),
                default => sprintf('takes %d to %d arguments, %d given', $minimum, $maximum, $count),
            });
        }
    }

    /**
     * @param int $index 0-based
     * @param string|null $default null = required
     * @return string
     * @throws TransformException
     */
    public function string(int $index, ?string $default = null): string
    {
        if (!array_key_exists($index, $this->values) || $this->values[$index] === null) {
            if ($default === null) {
                throw $this->error(sprintf('needs argument %d', $index + 1));
            }
            return $default;
        }
        $value = $this->values[$index];
        if (!is_scalar($value)) {
            throw $this->error(sprintf('argument %d must be text', $index + 1));
        }

        return is_bool($value) ? ($value ? '1' : '0') : (string) $value;
    }

    /**
     * @param int $index
     * @param float|null $default null = required
     * @return float
     * @throws TransformException
     */
    public function number(int $index, ?float $default = null): float
    {
        $text = $this->string($index, $default === null ? null : '');
        if ($text === '' && $default !== null) {
            return $default;
        }
        $number = (new NumberParser())->parse($text);
        if ($number === null) {
            throw $this->error(sprintf('argument %d must be a number, "%s" given', $index + 1, $text));
        }

        return $number;
    }

    /**
     * @param int $index
     * @param int $default
     * @param int $minimum
     * @param int $maximum
     * @return int
     * @throws TransformException
     */
    public function integer(int $index, int $default, int $minimum, int $maximum): int
    {
        $number = $this->number($index, (float) $default);
        if ($number !== floor($number) || $number < $minimum || $number > $maximum) {
            throw $this->error(sprintf(
                'argument %d must be a whole number from %d to %d',
                $index + 1,
                $minimum,
                $maximum
            ));
        }

        return (int) $number;
    }

    /**
     * @param int $index
     * @param string[] $allowed lower-case choices
     * @param string $default
     * @return string
     * @throws TransformException
     */
    public function choice(int $index, array $allowed, string $default): string
    {
        $value = strtolower(trim($this->string($index, $default)));
        if ($value === '') {
            return $default;
        }
        if (!in_array($value, $allowed, true)) {
            throw $this->error(sprintf(
                'argument %d must be one of %s, "%s" given',
                $index + 1,
                implode(', ', $allowed),
                $value
            ));
        }

        return $value;
    }

    /**
     * Arguments from $index on, as text.
     *
     * @param int $index
     * @return string[]
     */
    public function rest(int $index): array
    {
        $rest = [];
        for ($i = $index; $i < $this->count(); $i++) {
            $rest[] = $this->string($i);
        }

        return $rest;
    }

    /**
     * @param string $problem
     * @return TransformException
     */
    public function error(string $problem): TransformException
    {
        return new TransformException(sprintf('%s: %s.', $this->transform, $problem));
    }
}
