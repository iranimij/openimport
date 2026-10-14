<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Transform;

use Iranimij\OpenImport\Model\Transform\Arguments;
use Iranimij\OpenImport\Model\Transform\TransformException;
use Iranimij\OpenImport\Model\Transform\TransformPool;
use Iranimij\OpenImport\Model\Transform\TransformProviderInterface;
use Iranimij\OpenImport\Model\Transform\TransformScope;
use Iranimij\OpenImport\Model\Transform\ValueMap;
use PHPUnit\Framework\TestCase;

class TransformPoolTest extends TestCase
{
    private const ROW = ['Brand' => 'ACME', 'Color' => 'red', 'Empty' => '', 'Price' => '10,00'];

    /**
     * @param array<int, mixed> $definitions
     * @param string $value
     * @param ValueMap|null $map
     * @return string
     */
    private function transform(array $definitions, string $value, ?ValueMap $map = null): string
    {
        $chain = (new TransformPool())->compile($definitions, new TransformScope('field', $map));

        return $chain($value, self::ROW);
    }

    /**
     * @return array<string, array{0: array<int, mixed>, 1: string, 2: string}>
     */
    public static function transforms(): array
    {
        $step = static fn (string $name, mixed ...$args): array => ['name' => $name, 'args' => $args];

        return [
            'trim' => [['trim'], "  a b \n", 'a b'],
            'lower' => [['lower'], 'ÄBC', 'äbc'],
            'upper' => [['UPPER'], 'straße', 'STRASSE'],
            'title' => [['title'], 'red COTTON shirt', 'Red Cotton Shirt'],
            'replace' => [[$step('replace', 'cm', ' cm')], '10cm', '10 cm'],
            'replace with nothing' => [[$step('replace', '-')], 'a-b-c', 'abc'],
            'regex' => [[$step('regex', '\s+', ' ')], "a \t b", 'a b'],
            'regex groups' => [[$step('regex', '^ART-(\d+)$', '$1')], 'ART-0042', '0042'],
            'regex with tilde' => [[$step('regex', '~', '-')], 'a~b', 'a-b'],
            'regex case-insensitive' => [[$step('regex', '(?i)^art-', '')], 'Art-7', '7'],
            'strip_html' => [['strip_html'], '<p>Soft &amp; warm</p><p>100&nbsp;% cotton<br>wash 30°</p>', 'Soft & warm 100 % cotton wash 30°'],
            'strip_html scripts and broken markup' => [['strip_html'], '<script>x()</script><b>Bold<i> text', 'Bold text'],
            'strip_html plain text' => [['strip_html'], " plain  text\n", 'plain text'],
            'prefix' => [[$step('prefix', 'DE-')], '42', 'DE-42'],
            'prefix skips empty' => [[$step('prefix', 'DE-')], '', ''],
            'suffix' => [[$step('suffix', ' cm')], '10', '10 cm'],
            'default' => [[$step('default', 'n/a')], '  ', 'n/a'],
            'default keeps value' => [[$step('default', 'n/a')], 'x', 'x'],
            'split first' => [[$step('split', '|')], 'a | b | c', 'a'],
            'split second' => [[$step('split', '|', 2)], 'a|b|c', 'b'],
            'split last' => [[$step('split', '|', -1)], 'a|b|c', 'c'],
            'split missing part' => [[$step('split', '|', 5)], 'a|b', ''],
            'split empty' => [[$step('split', '|', 1)], '', ''],
            'join' => [[$step('join', ' ', 'Brand', 'Empty', 'Color', 'Missing')], 'Shirt', 'Shirt ACME red'],
            'join without value' => [[$step('join', '-', 'Brand')], '', 'ACME'],
            'decimal_comma' => [['decimal_comma'], '1.234,50', '1234.5'],
            'decimal_comma thousands only' => [['decimal_comma'], '1.234', '1234'],
            'decimal_comma empty' => [['decimal_comma'], '', ''],
            'number' => [['number'], '1,5', '1.5'],
            'number with decimals' => [[$step('number', 2)], '5', '5.00'],
            'number rounds half up' => [[$step('number', 2)], '1.005', '1.01'],
            'math plus' => [[$step('math', '+', 5)], '10', '15'],
            'math minus sign' => [[$step('math', '−', '0,5')], '10', '9.5'],
            'math times' => [[$step('math', '×', 1.19)], '19.99', '23.7881'],
            'math word' => [[$step('math', 'divide', 4)], '1', '0.25'],
            'math empty' => [[$step('math', '*', 2)], '', ''],
            'round default' => [['round'], '2.345', '2.35'],
            'round decimals' => [[$step('round', 0)], '2.5', '3'],
            'round up' => [[$step('round', 2, 'up')], '1.101', '1.11'],
            'round up exact' => [[$step('round', 1, 'up')], '1.1', '1.1'],
            'round down' => [[$step('round', 2, 'down')], '1.109', '1.10'],
            'round down negative' => [[$step('round', 0, 'down')], '-1.5', '-2'],
            'round ending' => [[$step('round', 2, 'ending', '.99')], '12.30', '12.99'],
            'round ending exact' => [[$step('round', 2, 'ending', '0.99')], '12.99', '12.99'],
            'round ending next' => [[$step('round', 2, 'ending', '.49')], '12.60', '13.49'],
            'price_adjust' => [[$step('price_adjust', 10)], '19.99', '21.99'],
            'price_adjust down' => [[$step('price_adjust', '-5', 0)], '100', '95'],
            'date auto iso' => [['date'], '2024-01-31 14:30:00', '2024-01-31'],
            'date auto german' => [[$step('date', 'auto', 'Y-m-d H:i')], '31.01.2024 14:30', '2024-01-31 14:30'],
            'date given format' => [[$step('date', 'm/d/Y', 'd.m.Y')], '01/31/2024', '31.01.2024'],
            'date empty' => [['date'], '', ''],
            'expr' => [[$step('expr', "{Brand} . ' ' . value")], 'Shirt', 'ACME Shirt'],
            'expr arithmetic' => [[$step('expr', 'value * 2')], '1,5', '3'],
            'chain' => [['trim', 'lower', $step('replace', ' ', '-'), $step('prefix', 'p-')], ' Red Shirt ', 'p-red-shirt'],
        ];
    }

    /**
     * @dataProvider transforms
     * @param array<int, mixed> $definitions
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('transforms')]
    public function testTransform(array $definitions, string $value, string $expected): void
    {
        self::assertSame($expected, $this->transform($definitions, $value));
    }

    public function testValueMap(): void
    {
        $map = new ValueMap(['Ja' => '1', 'Nein' => '0', 'ja' => 'ignored duplicate']);

        self::assertSame('1', $this->transform(['map'], ' JA ', $map));
        self::assertSame('vielleicht', $this->transform(['map'], 'vielleicht', $map));
        self::assertSame(2, $map->count());
    }

    public function testCaseSensitiveAndStrictValueMap(): void
    {
        $map = new ValueMap(['Ja' => '1'], true, true);

        self::assertSame('1', $this->transform(['map'], 'Ja', $map));
        $this->expectException(TransformException::class);
        $this->expectExceptionMessage('The value "ja" is not in the value map.');
        $this->transform(['map'], 'ja', $map);
    }

    /**
     * @return array<string, array{0: array<int, mixed>, 1: string}>
     */
    public static function invalidDefinitions(): array
    {
        $step = static fn (string $name, mixed ...$args): array => ['name' => $name, 'args' => $args];

        return [
            'unknown' => [['shout'], 'Step 1: unknown transform "shout"'],
            'no name' => [[['args' => []]], 'Step 1: a transform needs a name'],
            'arguments to trim' => [[$step('trim', 'x')], 'Step 1: trim: takes 0 arguments, 1 given'],
            'missing argument' => [['trim', 'replace'], 'Step 2: replace: takes 1 to 2 arguments, 0 given'],
            'empty find' => [[$step('replace', '')], 'the text to find is empty'],
            'array argument' => [[$step('prefix', ['x'])], 'argument 1 must be text'],
            'bad regex' => [[$step('regex', '(')], 'the pattern "(" is not valid'],
            'empty regex' => [[$step('regex', '')], 'the pattern is empty'],
            'split empty separator' => [[$step('split', '')], 'the separator is empty'],
            'split position zero' => [[$step('split', ',', 0)], 'the position starts at 1'],
            'split position not whole' => [[$step('split', ',', 1.5)], 'must be a whole number'],
            'join without columns' => [[$step('join', ' ')], 'takes at least 2 arguments, 1 given'],
            'math operator' => [[$step('math', '^', 2)], 'the operator must be'],
            'math operand' => [[$step('math', '+', 'x')], 'argument 2 must be a number, "x" given'],
            'math division by zero' => [[$step('math', '/', 0)], 'division by zero'],
            'round mode' => [[$step('round', 2, 'nearest')], 'must be one of half_up, up, down, ending'],
            'round ending missing' => [[$step('round', 2, 'ending')], 'needs argument 3'],
            'round ending range' => [[$step('round', 2, 'ending', 5)], 'between 0 and 1'],
            'round ending without mode' => [[$step('round', 2, 'up', '.99')], 'only used with mode "ending"'],
            'round too many decimals' => [[$step('round', 11)], 'from 0 to 10'],
            'price_adjust minus 100' => [[$step('price_adjust', -100)], 'above -100'],
            'date empty output' => [[$step('date', 'auto', ' ')], 'the output format is empty'],
            'map without value map' => [['map'], 'the field "field" has no value map'],
            'expr parse error' => [[$step('expr', '1 +')], 'expr: The expression ends too early'],
            'max count' => [[$step('number', 1, '.', 3)], 'takes 0 to 2 arguments, 3 given'],
            'number separator' => [[$step('number', 2, ';')], 'the decimal separator must be "." or ","'],
        ];
    }

    /**
     * @dataProvider invalidDefinitions
     * @param array<int, mixed> $definitions
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidDefinitions')]
    public function testInvalidDefinitionsAreExplained(array $definitions, string $message): void
    {
        $this->expectException(TransformException::class);
        $this->expectExceptionMessage($message);
        (new TransformPool())->compile($definitions, new TransformScope('field'));
    }

    /**
     * @return array<string, array{0: array<int, mixed>, 1: string, 2: string}>
     */
    public static function failingValues(): array
    {
        $step = static fn (string $name, mixed ...$args): array => ['name' => $name, 'args' => $args];

        return [
            'not a number' => [['number'], 'abc', 'number: "abc" is not a number.'],
            'decimal_comma text' => [['decimal_comma'], 'n/a', 'decimal_comma: "n/a" is not a number.'],
            'impossible date' => [[$step('date', 'd.m.Y')], '31.02.2024', 'date: "31.02.2024" is not a date in the format d.m.Y.'],
            'not a date' => [['date'], 'tomorrow', 'is not a date in the format auto'],
            'expr error' => [[$step('expr', 'value / 0')], '5', 'expr: Division by zero. (in value / 0)'],
            'regex backtracking' => [[$step('regex', '(a+)+$')], str_repeat('a', 40) . 'b', 'regex: the pattern "(a+)+$" failed'],
        ];
    }

    /**
     * @dataProvider failingValues
     * @param array<int, mixed> $definitions
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('failingValues')]
    public function testFailingValues(array $definitions, string $value, string $message): void
    {
        $this->expectException(TransformException::class);
        $this->expectExceptionMessage($message);
        $this->transform($definitions, $value);
    }

    public function testOwnProvidersAddOrReplaceTransforms(): void
    {
        $provider = new class implements TransformProviderInterface {
            public function names(): array
            {
                return ['Reverse', 'trim'];
            }

            public function compile(string $name, Arguments $arguments, TransformScope $scope): \Closure
            {
                return $name === 'reverse'
                    ? static fn (string $value): string => strrev($value)
                    : static fn (string $value): string => '[' . $value . ']';
            }
        };
        $pool = new TransformPool([$provider]);

        self::assertContains('reverse', $pool->names());
        self::assertSame('[cba]', $pool->compile(['reverse', 'trim'], new TransformScope('x'))('abc', []));
    }

    public function testArgumentsGivenAsAScalar(): void
    {
        self::assertSame('x-1', $this->transform([['name' => 'prefix', 'args' => 'x-']], '1'));
    }
}
