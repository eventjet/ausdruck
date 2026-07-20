<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit\Parser;

use Eventjet\Ausdruck\Parser\Span;
use Eventjet\Ausdruck\Parser\SyntaxError;
use Eventjet\Ausdruck\Parser\TypeParser;
use Eventjet\Ausdruck\Parser\Types;
use Eventjet\Ausdruck\Type;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_keys;
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
        // A type is required everywhere one may appear, so one message serves them all; where a position has a name of
        // its own, it says that instead of just "type".
        //
        // The input ran out one column past the whole `->`, not one past the column it starts in: a token is as wide
        // as it is written.
        yield 'End of string after function arrow' => [
            <<<'AUSDRUCK'
                fn(int) ->
                          =
                AUSDRUCK,
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
            'Expected type, got end of input',
        ];
        yield 'Whitespace-only string' => [
            '  ',
            'Expected type, got end of input',
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
            <<<'AUSDRUCK'
                {name
                     =
                AUSDRUCK,
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
        // An argument list ends at the first missing comma, so whatever follows gets blamed as the closing bracket it
        // isn't. Forgetting the bracket is the likelier mistake, and this names it. What the token is doesn't matter:
        // a type, a literal or an operator all end the list the same way, so a type argument list separates its
        // elements with commas exactly like every other list in the language.
        yield 'Type instead of a second type argument' => [
            'list<int string>',
            'Expected >, got string',
        ];
        yield 'Literal instead of a second type argument' => [
            'list<int 42>',
            'Expected >, got 42',
        ];
        yield 'Type instead of a second function parameter' => [
            'fn(int string) -> bool',
            'Expected ), got string',
        ];
        yield 'Literal instead of a second function parameter' => [
            'fn(int 42) -> string',
            'Expected ), got 42',
        ];
        yield 'Unclosed function parameter list' => [
            'fn(int -> string',
            'Expected ), got ->',
        ];
        yield 'Unclosed type argument list' => [
            'list<int .',
            'Expected >, got .',
        ];
        // A `<` after a name that isn't a generic constructor might be a less-than, so the argument list after it is
        // only tried, and any error inside it merely rules that reading out. A broken list nested in one is nested in
        // the trying too, which is why the outer `<` is blamed rather than the comma the inner list is missing—the
        // reading fallen back to is a less-than, and it is that one the tokens after it then have to fit.
        yield 'Committed type argument list broken inside a speculative one' => [
            'MyType<list<int int>>',
            'Unexpected <',
        ];
        yield 'Committed type argument list broken on its own' => [
            'list<list<int int>>',
            'Expected >, got int',
        ];
        // A type string is a whole type, so anything after the first complete one is an error rather than ignored.
        yield 'Trailing identifier' => [
            'int foo',
            'Unexpected identifier foo',
        ];
        yield 'Trailing identifier after a generic type' => [
            'list<int> bar',
            'Unexpected identifier bar',
        ];
        yield 'Trailing literal' => [
            'string 42',
            'Unexpected 42',
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
     * @return iterable<string, array{string, string}>
     */
    public static function declarationErrorCases(): iterable
    {
        yield 'Name is not an identifier' => ['42: int', 'Expected type name, got 42'];
        yield 'Missing colon after name' => ['Foo int', 'Expected :, got int'];
        yield 'End of input after colon' => ['Foo:', 'Expected type, got end of input'];
        yield 'Non-type token after colon' => ['Foo: ->', 'Expected type, got ->'];
    }

    #[DataProvider('syntaxErrorCases')]
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
            /** @psalm-suppress InvalidArgument False positive - Psalm can't analyze regular expressions */
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

    #[DataProvider('parseStringCases')]
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

    public function testParseDeclarationsReadsBackToBackTypesOffOneStream(): void
    {
        /**
         * @psalm-suppress InternalClass
         * @psalm-suppress InternalMethod
         */
        $declarations = TypeParser::parseDeclarations(
            <<<'AUSDRUCK'
                Item: {
                    tags: list<string>,
                }
                Bag: {
                    items: list<Item>,
                }
                AUSDRUCK,
        );

        self::assertSame(['Item', 'Bag'], array_keys($declarations));
        self::assertSame('{ tags: list<string> }', (string)$declarations['Item']);
        self::assertSame('{ items: list<Item> }', (string)$declarations['Bag']);
    }

    public function testParseDeclarationsAcceptsEmptyInput(): void
    {
        /**
         * @psalm-suppress InternalClass
         * @psalm-suppress InternalMethod
         */
        self::assertSame([], TypeParser::parseDeclarations('   '));
    }

    #[DataProvider('declarationErrorCases')]
    public function testParseDeclarationsRejectsMalformedInput(string $src, string $expectedMessage): void
    {
        $this->expectException(SyntaxError::class);
        $this->expectExceptionMessage($expectedMessage);

        /**
         * @psalm-suppress InternalClass
         * @psalm-suppress InternalMethod
         */
        TypeParser::parseDeclarations($src);
    }
}
