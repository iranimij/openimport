<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Transform;

use Iranimij\OpenImport\Model\Expression\EvaluationException;
use Iranimij\OpenImport\Model\Expression\ExpressionException;
use Iranimij\OpenImport\Model\Expression\Parser;

/**
 * All transforms by name. Compiles a field's transform list into one function.
 *
 * A transform definition is a name ("trim") or ["name" => "replace", "args" => ["a", "b"]], as stored in a mapping
 * row's JSON. A definition may have "when", an expression: the transform then only runs for rows where it is true
 * ("{Brand} = 'Acme'", "value > 100"); other rows keep the value as it is.
 */
class TransformPool
{
    /**
     * @var array<string, TransformProviderInterface>
     */
    private array $byName = [];

    private Parser $parser;

    /**
     * @param TransformProviderInterface[] $providers extra transforms; a provider may replace a built-in name
     */
    public function __construct(array $providers = [])
    {
        $this->parser = new Parser();
        $builtIn = [new TextTransforms(), new NumberTransforms(), new DateTransform(), new LookupTransforms()];
        foreach (array_merge($builtIn, array_values($providers)) as $provider) {
            foreach ($provider->names() as $name) {
                $this->byName[strtolower($name)] = $provider;
            }
        }
    }

    /**
     * @return string[]
     */
    public function names(): array
    {
        return array_keys($this->byName);
    }

    /**
     * @param array<int, mixed> $definitions
     * @param TransformScope $scope
     * @return \Closure fn (string $value, array $row): string
     * @throws TransformException naming the step ("Step 2 (round): …")
     */
    public function compile(array $definitions, TransformScope $scope): \Closure
    {
        $steps = [];
        foreach (array_values($definitions) as $position => $definition) {
            [$name, $arguments] = $this->definition($definition, $position + 1);
            $provider = $this->byName[$name] ?? null;
            if ($provider === null) {
                throw new TransformException(sprintf(
                    'Step %d: unknown transform "%s". Transforms: %s.',
                    $position + 1,
                    $name,
                    implode(', ', $this->names())
                ));
            }
            try {
                $step = $provider->compile($name, new Arguments($name, $arguments), $scope);
            } catch (TransformException $exception) {
                throw new TransformException(sprintf('Step %d: %s', $position + 1, $exception->getMessage()));
            }
            $when = is_array($definition) && is_string($definition['when'] ?? null) ? trim($definition['when']) : '';
            $steps[] = $when === '' ? $step : $this->conditional($step, $when, $position + 1, $name);
        }

        return static function (string $value, array $row) use ($steps): string {
            foreach ($steps as $step) {
                $value = $step($value, $row);
            }
            return $value;
        };
    }

    /**
     * A step that only runs where its "when" expression is true.
     *
     * @param \Closure $step
     * @param string $when
     * @param int $position
     * @param string $name
     * @return \Closure
     * @throws TransformException
     */
    private function conditional(\Closure $step, string $when, int $position, string $name): \Closure
    {
        try {
            $condition = $this->parser->parse($when);
        } catch (ExpressionException $exception) {
            throw new TransformException(sprintf(
                'Step %d (%s): "only when" %s',
                $position,
                $name,
                $exception->getMessage()
            ));
        }

        return static function (string $value, array $row) use ($step, $condition, $name): string {
            try {
                $applies = $condition->test($row, $value);
            } catch (EvaluationException $exception) {
                throw new TransformException(sprintf(
                    '%s, only when: %s (in %s)',
                    $name,
                    $exception->getMessage(),
                    $condition->source()
                ));
            }

            return $applies ? $step($value, $row) : $value;
        };
    }

    /**
     * @param mixed $definition
     * @param int $step
     * @return array{0: string, 1: array<int|string, mixed>}
     */
    private function definition(mixed $definition, int $step): array
    {
        if (is_string($definition)) {
            return [strtolower(trim($definition)), []];
        }
        if (is_array($definition) && isset($definition['name']) && is_string($definition['name'])) {
            $arguments = $definition['args'] ?? [];
            if (!is_array($arguments)) {
                $arguments = [$arguments];
            }
            return [strtolower(trim($definition['name'])), $arguments];
        }
        throw new TransformException(sprintf('Step %d: a transform needs a name.', $step));
    }
}
