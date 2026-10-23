<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Expression;

use Iranimij\OpenImport\Model\Expression\EvaluationException;
use Iranimij\OpenImport\Model\Expression\ExpressionException;
use Iranimij\OpenImport\Model\Expression\Parser;
use PHPUnit\Framework\TestCase;

class ParserTest extends TestCase
{
    private const ROW = [
        'Price' => '19,99',
        'Qty' => '3',
        'Name' => ' Red Shirt ',
        'Brand' => 'ACME',
        'Empty' => '',
        'Odd}Name' => 'odd',
        'Flag' => '0',
    ];

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function expressions(): array
    {
        return [
            'integer literal' => ['42', '42'],
            'decimal' => ['1.5', '1.5'],
            'exponent' => ['1e3', '1000'],
            'string' => ["'it''s'", "it's"],
            'double quoted' => ['"say ""hi"""', 'say "hi"'],
            'column' => ['{Brand}', 'ACME'],
            'column with brace' => ['{Odd}}Name}', 'odd'],
            'missing column' => ['{Nope}', ''],
            'decimal comma arithmetic' => ['{Price} * 2', '39.98'],
            'no float noise' => ['0.1 + 0.2', '0.3'],
            'precedence' => ['2 + 3 * 4', '14'],
            'parentheses' => ['(2 + 3) * 4', '20'],
            'unary minus' => ['-{Qty} + 1', '-2'],
            'unary plus' => ['+{Qty}', '3'],
            'modulo' => ['7 % 3', '1'],
            'division' => ['1 / 4', '0.25'],
            'concat' => ["{Brand} . ' ' . TRIM({Name})", 'ACME Red Shirt'],
            'concat binds looser than plus' => ["'n' . 1 + 2", 'n3'],
            'empty arithmetic stays empty' => ['{Empty} * 1.19', ''],
            'negated empty stays empty' => ['-{Empty}', ''],
            'comparison numeric' => ["{Qty} > 10", '0'],
            'comparison text ignores case' => ["{Brand} = 'acme'", '1'],
            'not equal' => ["{Brand} <> 'acme'", '0'],
            'numeric text equals number' => ['{Qty} = 3.0', '1'],
            'less or equal' => ["'a' <= 'B'", '1'],
            'greater or equal' => ['2 >= 2', '1'],
            'and or not' => ['not {Flag} and ({Qty} = 3 or false)', '1'],
            'true keyword' => ['TRUE', '1'],
            'value keyword' => ['value . "!"', 'current!'],
            'if' => ["IF({Qty} > 2, 'many', 'few')", 'many'],
            'if without else' => ["IF({Qty} > 5, 'many')", ''],
            'if is lazy' => ["IF(true, 'ok', 1 / 0)", 'ok'],
            'round' => ['ROUND({Price} * 1.19, 2)', '23.79'],
            'round default' => ['round(2.5)', '3'],
            'round empty' => ['ROUND({Empty}, 2)', ''],
            'concat function' => ["CONCAT({Brand}, '-', {Qty})", 'ACME-3'],
            'lower upper' => ["LOWER({Brand}) . UPPER('x')", 'acmeX'],
            'coalesce' => ["COALESCE({Empty}, '  ', {Brand})", 'ACME'],
            'coalesce all empty' => ['COALESCE({Empty})', ''],
            'len' => ['LEN(TRIM({Name}))', '9'],
            'len unicode' => ["LEN('Größe')", '5'],
            'number' => ["NUMBER('1.234,50')", '1234.5'],
            'number empty' => ['NUMBER({Empty})', ''],
            'replace' => ["REPLACE({Brand}, 'C', 'K')", 'AKME'],
            'contains' => ["CONTAINS({Name}, 'shirt')", '1'],
            'min max' => ["MIN(3, {Price}, {Empty}) . '/' . MAX(3, {Price})", '3/19.99'],
            'min of empties' => ['MIN({Empty})', ''],
            'abs' => ['ABS(-2.5)', '2.5'],
            'abs empty' => ['ABS({Empty})', ''],
            'long whole number stays exact' => ['12345678901234567', '12345678901234567'],
            'very long whole number stays exact' => ['123456789012345678901', '123456789012345678901'],
        ];
    }

    /**
     * @dataProvider expressions
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('expressions')]
    public function testEvaluation(string $expression, string $expected): void
    {
        self::assertSame($expected, (new Parser())->parse($expression)->evaluate(self::ROW, 'current'));
    }

    public function testColumnsAndSourceAreKept(): void
    {
        $expression = (new Parser())->parse('{Price} * {Qty} + {Price}');

        self::assertSame(['Price', 'Qty'], $expression->columns());
        self::assertSame('{Price} * {Qty} + {Price}', $expression->source());
    }

    public function testConditions(): void
    {
        $parser = new Parser();

        self::assertTrue($parser->parse("{Brand} = 'ACME'")->test(self::ROW));
        self::assertFalse($parser->parse('{Empty}')->test(self::ROW));
        self::assertFalse($parser->parse('{Flag}')->test(self::ROW));
        self::assertTrue($parser->parse('{Qty}')->test(self::ROW));
        self::assertFalse($parser->parse('0.0')->test(self::ROW));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidExpressions(): array
    {
        return [
            'empty' => ['  ', 'empty'],
            'unknown character' => ['1 # 2', 'Unexpected character "#" at position 3'],
            'unclosed string' => ["'abc", 'not closed'],
            'unclosed column' => ['{abc', 'not closed'],
            'empty column' => ['{ }', 'Empty column name'],
            'bare word' => ['Brand', 'Write column names in braces: {Brand}'],
            'unknown function' => ['EVAL(1)', 'Unknown function EVAL'],
            'too few arguments' => ['IF(1)', 'IF at position 1 takes 2 to 3 arguments, not 1'],
            'too many arguments' => ['LEN(1, 2)', 'LEN at position 1 takes 1 argument, not 2'],
            'at least' => ['CONCAT()', 'takes at least 1 argument, not 0'],
            'exact count' => ['REPLACE(1)', 'takes 3 arguments, not 1'],
            'missing parenthesis' => ['(1 + 2', 'Missing ")"'],
            'ends early' => ['1 +', 'ends too early'],
            'trailing' => ["1 2", 'Unexpected "2" at position 3'],
            'trailing string' => ["1 'x'", "Unexpected \"'x'\""],
            'trailing column' => ['1 {x}', 'Unexpected "{x}"'],
            'chained comparison' => ['1 < 2 < 3', 'Unexpected "<"'],
            'misplaced comma' => ['1, 2', 'Unexpected ","'],
        ];
    }

    /**
     * @dataProvider invalidExpressions
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidExpressions')]
    public function testParseErrorsAreExplained(string $expression, string $message): void
    {
        $this->expectException(ExpressionException::class);
        $this->expectExceptionMessage($message);
        (new Parser())->parse($expression);
    }

    public function testLengthAndDepthLimits(): void
    {
        $parser = new Parser();
        try {
            $parser->parse(str_repeat('1+', 1000) . '1');
            self::fail('Expected a length error');
        } catch (ExpressionException $exception) {
            self::assertStringContainsString('2000 characters', $exception->getMessage());
        }

        $this->expectException(ExpressionException::class);
        $this->expectExceptionMessage('nested more than 40 levels');
        $parser->parse(str_repeat('(', 50) . '1' . str_repeat(')', 50));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function failingExpressions(): array
    {
        return [
            'division by zero' => ['{Qty} / 0', 'Division by zero'],
            'modulo by zero' => ['{Qty} % 0', 'Division by zero'],
            'text in arithmetic' => ['{Brand} * 2', '"ACME" is not a number'],
            'text in NUMBER' => ['NUMBER({Name})', 'is not a number'],
        ];
    }

    /**
     * @dataProvider failingExpressions
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('failingExpressions')]
    public function testEvaluationErrors(string $expression, string $message): void
    {
        $parsed = (new Parser())->parse($expression);

        $this->expectException(EvaluationException::class);
        $this->expectExceptionMessage($message);
        $parsed->evaluate(self::ROW);
    }

    public function testFunctionNames(): void
    {
        self::assertContains('COALESCE', (new Parser())->functionNames());
    }
}
