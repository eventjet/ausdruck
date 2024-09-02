<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit\Parser;

use Eventjet\Ausdruck\Parser\Span;
use Eventjet\Ausdruck\Parser\SyntaxError;
use Eventjet\Ausdruck\Parser\TypeParser;
use Eventjet\Ausdruck\Parser\Types;
use Eventjet\Ausdruck\Type;
use LogicException;
use PHPUnit\Framework\TestCase;

use function explode;
use function implode;
use function preg_match;
use function strlen;

final class TypeParserTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function syntaxErrorCases(): iterable
    {
        yield 'End of string after function argument' => [
            'fn(int',
            'Expected ), got end of input',
        ];
        yield 'End of string after function arrow' => [
            'fn(int) ->',
            'Expected return type, got end of input',
        ];
        yield 'Dot after function arrow' => [
            'fn(int) -> .',
            'Expected return type, got .',
        ];
        yield 'Empty string' => [
            <<<'AUSDRUCK'
            
            =
            AUSDRUCK,
            'Invalid type ""',
        ];
        yield 'Whitespace-only string' => [
            '  ',
            'Invalid type ""',
        ];
        yield 'Arrow' => [
            <<<'AUSDRUCK'
            ->
            ==
            AUSDRUCK,
            'Expected type, got ->',
        ];
        yield 'Open curly brace' => [
            '{',
            'Expected }, got end of input',
        ];
        yield 'Struct instead of struct field name' => [
            '{{name: string}: string}',
            'Expected field name, got {',
        ];
        yield 'Struct: end of input after field name' => [
            '{name',
            'Expected :, got end of input',
        ];
        yield 'Struct: missing colon between field name and type' => [
            '{name string}',
            'Expected :, got string',
        ];
        yield 'Struct: pipe instead of colon' => [
            '{name | string}',
            'Expected :, got |',
        ];
        yield 'End of input after struct colon' => [
            '{name:',
            'Expected type, got end of input',
        ];
        yield 'Struct: double colon' => [
            '{name:: string}',
            'Expected type, got :',
        ];
        yield 'Struct: end of input after struct type' => [
            '{name: string',
            'Expected }, got end of input',
        ];
        yield 'Struct: no comma between fields' => [
            '{name: string age: int}',
            'Expected }, got age',
        ];
    }

    /**
     * @return iterable<string, array{string, Type}>
     */
    public static function parseStringCases(): iterable
    {
        yield 'Empty struct' => ['{}', Type::struct([])];
        yield 'Empty struct with newline' => ["{\n}", Type::struct([])];
        yield 'Empty struct with blank line' => ["{\n\n}", Type::struct([])];
        yield 'Struct with a single field' => ['{name: string}', Type::struct(['name' => Type::string()])];
        yield 'Struct with a single field and whitespace around it' => [
            '{ name: string }',
            Type::struct(['name' => Type::string()]),
        ];
        yield 'Struct: whitespace after colon' => [
            '{name : string}',
            Type::struct(['name' => Type::string()]),
        ];
        yield 'Struct: no whitespace after colon' => [
            '{name:string}',
            Type::struct(['name' => Type::string()]),
        ];
        yield 'Struct with a single field on a separate line' => [
            <<<EOF
                {
                name: string
                }
                EOF,
            Type::struct(['name' => Type::string()]),
        ];
        yield 'Struct with a single field on a separate line with indent' => [
            <<<EOF
                {
                    name: string
                }
                EOF,
            Type::struct(['name' => Type::string()]),
        ];
        yield 'Trailing comma after struct field' => ['{name: string,}', Type::struct(['name' => Type::string()])];
        yield 'Trailing comma and whitespace after struct field' => [
            '{name: string, }',
            Type::struct(['name' => Type::string()]),
        ];
        yield 'Struct field on separate line with trailing comma' => [
            <<<EOF
                {
                name: string,
                }
                EOF,
            Type::struct(['name' => Type::string()]),
        ];
        yield 'Struct with multiple fields and no trailing comma' => [
            '{name: string, age: int}',
            Type::struct(['name' => Type::string(), 'age' => Type::int()]),
        ];
        yield 'Struct with multiple fields, each on a separate line, with no trailing comma' => [
            <<<EOF
                {
                name: string,
                age: int
                }
                EOF,
            Type::struct(['name' => Type::string(), 'age' => Type::int()]),
        ];
        yield 'Struct with multiple fields, each on a separate line, with trailing comma' => [
            <<<EOF
                {
                name: string,
                age: int,
                }
                EOF,
            Type::struct(['name' => Type::string(), 'age' => Type::int()]),
        ];
        yield 'Struct with multiple fields, all on one separate line' => [
            <<<'EOF'
                {
                    name: string, age: int
                }
                EOF,
            Type::struct(['name' => Type::string(), 'age' => Type::int()]),
        ];
        yield 'Struct with multiple fields, all on one separate line and a trailing comma' => [
            <<<'EOF'
                {
                    name: string, age: int,
                }
                EOF,
            Type::struct(['name' => Type::string(), 'age' => Type::int()]),
        ];
        yield 'Struct nested inside another struct' => [
            '{name: {first: string}}',
            Type::struct(['name' => Type::struct(['first' => Type::string()])]),
        ];
        yield 'Comma after nested struct' => [
            '{name: {first: string},}',
            Type::struct(['name' => Type::struct(['first' => Type::string()])]),
        ];
    }

    /**
     * @dataProvider syntaxErrorCases
     */
    public function testSyntaxErrors(string $type, string $expectedMessage): void
    {
        $expectedSpan = null;
        $lines = explode("\n", $type);
        foreach ($lines as $lineIndex => $line) {
            $result = preg_match('/^(?<indent>\s*)(?<marker>=+)\s*$/', $line, $matches);
            if ($result !== 1) {
                continue;
            }
            if ($expectedSpan !== null) {
                throw new LogicException('Multi-line markers are not implemented');
            }
            $lineNumber = $lineIndex;
            if ($lineNumber < 1) {
                throw new LogicException('A marker in the first line does not make sense');
            }
            $startCol = strlen($matches['indent']) + 1;
            $endCol = strlen($matches['indent']) + strlen($matches['marker']);
            /** @psalm-suppress InvalidArgument False positive */
            $expectedSpan = new Span($lineNumber, $startCol, $lineNumber, $endCol);
            unset($lines[$lineIndex]);
        }
        $type = implode("\n", $lines);

        /**
         * @psalm-suppress InternalClass
         * @psalm-suppress InternalMethod
         */
        $error = TypeParser::parseString($type);

        self::assertInstanceOf(SyntaxError::class, $error);
        self::assertSame($expectedMessage, $error->getMessage());
        if ($expectedSpan !== null) {
            self::assertSame((string)$expectedSpan, (string)$error->location);
        }
    }

    /**
     * @dataProvider parseStringCases
     */
    public function testParseString(string $typeString, Type $expected): void
    {
        /**
         * @psalm-suppress InternalMethod
         * @psalm-suppress InternalClass
         */
        $node = TypeParser::parseString($typeString);
        if ($node instanceof SyntaxError) {
            self::fail($node->getMessage());
        }
        $actual = (new Types())->resolve($node);

        self::assertInstanceOf(Type::class, $actual);
        self::assertTrue($actual->equals($expected));
    }
}
