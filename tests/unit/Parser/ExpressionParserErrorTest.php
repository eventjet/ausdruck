<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit\Parser;

use Eventjet\Ausdruck\Parser\Declarations;
use Eventjet\Ausdruck\Parser\ExpressionParser;
use Eventjet\Ausdruck\Parser\Span;
use Eventjet\Ausdruck\Parser\SyntaxError;
use Eventjet\Ausdruck\Parser\TypeError;
use Eventjet\Ausdruck\Type;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function assert;
use function preg_match;
use function sprintf;
use function strlen;

/**
 * What the parser rejects, and where it points when it does. The expressions it accepts, and how they print back out,
 * belong to {@see ExpressionParserTest}.
 */
final class ExpressionParserErrorTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1?: string}>
     */
    public static function invalidSyntaxExpressions(): iterable
    {
        yield 'string: missing closing quote' => ['"foo'];
        yield 'single pipe' => ['foo:bool | bar:bool'];
        yield 'single equals' => ['foo:string = bar:string'];
        yield 'double equals' => ['foo:string == bar:string'];
        yield 'double length fat arrow' => ['foo:string ==> bar:string'];
        yield 'end after single pipe' => ['foo:bool |'];
        yield 'end after single equals' => ['foo:bool =', 'Expected ==, got end of input'];
        yield 'end after double equals' => ['foo:bool =='];
        yield 'close brace after triple equals' => ['foo:bool === )'];
        yield 'lambda: missing closing brace' => ['(foo, bar => foo:string'];
        yield 'lambda: open brace instead of closing' => ['(foo, bar( => foo:string'];
        yield 'standalone open brace' => ['('];
        yield 'dot type' => ['foo:list<.>'];
        yield 'end of string after generic open angle' => ['foo:map<'];
        yield 'two variables separated by a space' => ['foo:string bar:int'];
        yield 'standalone dot' => ['.'];
        yield 'prop access without an object' => ['.foo:string'];
        yield 'triple equals without left hand side' => ['=== foo:string'];
        yield 'missing variable type' => ['foo'];
        yield 'missing variable type in sub-expression' => [
            'foo === bar:true',
            'Variable foo must either be declared or have an inline type',
        ];
        yield 'end of string after lambda argument' => ['|one, two'];
        yield 'two commas in lambda arguments' => ['|one,, two| one:bool || two:bool'];
        yield 'standalone pipe' => ['|'];
        yield 'colon after first parameter' => ['some(haystack:list<string>:, |item| item:string === needle:string)'];
        yield 'missing left hand side of or' => ['|| bar:bool'];
        yield 'some: missing closing paren' => [
            'haystack:list<string>.some:bool(|item| item:string === needle:string',
            'Expected ), got end of input',
        ];
        yield 'missing right hand side of minus' => ['foo:int -'];
        yield 'missing right hand side of plus' => ['foo:int +'];
        yield 'missing right hand side of star' => ['foo:int *'];
        yield 'missing right hand side of slash' => ['foo:int /'];
        yield 'missing right hand side of percent' => ['foo:int %'];
        yield 'missing left hand side of plus' => ['+ foo:int', 'Expected expression, got +'];
        yield 'missing left hand side of star' => ['* foo:int', 'Expected expression, got *'];
        // Unlike -, + is not a unary operator.
        yield 'plus as a sign' => ['a:int + + 2', 'Expected expression, got +'];
        yield 'empty string' => ['', 'Expected expression, got end of input'];
        yield 'missing left hand side of >' => ['> foo:int'];
        yield 'missing right hand side of >' => ['foo:int >'];
        yield 'end of string variable and colon' => ['foo:'];
        yield 'end of string after function call and colon' => ['foo:string.substr:'];
        yield 'end of string after function dot' => ['foo:string.'];
        yield 'missing function name' => ['foo:string.:string()'];
        yield 'list literal: missing closing bracket' => ['[1, 2'];
        yield 'end of string after curly brace' => ['{'];
        yield 'struct literal in field name position' => ['{{name: "John"}: "John"}'];
        yield 'missing colon in struct literal' => ['{name "John"}'];
        yield 'end of string after struct field name' => ['{name'];
        yield 'end of string after struct field colon' => ['{name:'];
        yield 'end of string after struct field value' => ['{name: "John"'];
        yield 'missing value in struct literal' => ['{name: }'];
        yield 'missing comma between struct fields' => ['{name: "John" age: 42}'];
        yield 'single ampersand' => ['foo:bool & bar:bool'];
        yield 'non-token, non-identifier symbol' => ['foo:bool € bar:bool'];
        yield 'identifier starting with a number' => ['42foo:bool', 'Unexpected identifier foo'];
        yield 'identifier starting with an underscore' => ['_foo:bool', 'Unexpected character _'];
        // === and > are non-associative, so a chain of them is a syntax error rather than a confusing type error.
        yield 'chained ===' => ['a:int === b:int === c:int', 'Unexpected ==='];
        yield 'chained >' => ['a:int > b:int > c:int', 'Unexpected >'];
        // Whatever follows a complete expression is a mistake, not something to drop: silently returning the expression
        // parsed so far turns a typo or an operator we don't have into a valid expression with a surprising value.
        yield 'trailing literal' => ['1 2', 'Unexpected 2'];
        yield 'trailing literal after a complete subtraction' => ['1 - 1 999', 'Unexpected 999'];
        yield 'trailing string literal' => ['"a" "b"', 'Unexpected "b"'];
        yield 'trailing keyword' => ['true false', 'Unexpected identifier false'];
        // `<` is not an operator, so this is a literal followed by junk rather than a comparison.
        yield 'less than' => ['42 < 23', 'Unexpected <'];
        yield 'trailing operator' => ['a:int -', 'Expected expression, got end of input'];
        yield 'missing comma between list items' => ['[1 2]', 'Expected ], got 2'];
        yield 'missing comma between function arguments' => ['foo:string.substr(0 3)', 'Expected ), got 3'];
        // A group is a whole expression between the parentheses: empty ones have nothing to group, and an unclosed one
        // is missing its ). A close paren with no group of its own to end is junk after a complete expression.
        yield 'empty parentheses' => ['()', 'Expected expression, got )'];
        yield 'unclosed parenthesis' => ['(a:bool', 'Expected ), got end of input'];
        yield 'unclosed parenthesis around a subtraction' => ['(a:int - b:int', 'Expected ), got end of input'];
        yield 'comma inside parentheses' => ['(a:bool, b:bool)', 'Expected ), got ,'];
        yield 'unmatched close parenthesis' => ['(a:bool))', 'Unexpected )'];
    }

    /**
     * @return iterable<string, array{0: string, 1?: string, 2?: Declarations}>
     */
    public static function typeErrorExpressions(): iterable
    {
        yield 'map type with bool key type' => ['foo:map<bool, string>'];
        yield 'or with string on the left' => [
            'foo:string || bar:bool',
            'The expression on the left side of || must be boolean, got string',
        ];
        yield 'or with string on the right' => [
            'foo:bool || bar:string',
            'The expression on the right side of || must be boolean, got string',
        ];
        yield 'and with string on the left' => [
            'foo:string && bar:bool',
            'The expression on the left side of && must be boolean, got string',
        ];
        yield 'and with string on the right' => [
            'foo:bool && bar:string',
            'The expression on the right side of && must be boolean, got string',
        ];
        yield 'equals: different operand types' => ['foo:string === bar:int'];
        yield 'subtract int from float' => ['foo:float - bar:int', 'Can\'t subtract int from float'];
        yield 'subtract float from int' => ['foo:int - bar:float', 'Can\'t subtract float from int'];
        yield 'subtract string from string' => ['foo:string - bar:string', 'Can\'t subtract string from string'];
        yield 'subtract string from int' => ['foo:int - bar:string', 'Can\'t subtract string from int'];
        yield 'subtract int from string' => ['foo:string - bar:int', 'Can\'t subtract int from string'];
        yield 'add float to int' => ['foo:int + bar:float', 'Can\'t add float to int'];
        yield 'add int to float' => ['foo:float + bar:int', 'Can\'t add int to float'];
        yield 'add string to string' => ['foo:string + bar:string', 'Can\'t add string to string'];
        yield 'add string to int' => ['foo:int + bar:string', 'Can\'t add string to int'];
        yield 'add int to string' => ['foo:string + bar:int', 'Can\'t add int to string'];
        yield 'multiply int by float' => ['foo:int * bar:float', 'Can\'t multiply int by float'];
        yield 'multiply float by int' => ['foo:float * bar:int', 'Can\'t multiply float by int'];
        yield 'multiply string by string' => ['foo:string * bar:string', 'Can\'t multiply string by string'];
        yield 'multiply string by int' => ['foo:string * bar:int', 'Can\'t multiply string by int'];
        yield 'multiply int by string' => ['foo:int * bar:string', 'Can\'t multiply int by string'];
        yield 'divide int by float' => ['foo:int / bar:float', 'Can\'t divide int by float'];
        yield 'divide float by int' => ['foo:float / bar:int', 'Can\'t divide float by int'];
        yield 'divide string by string' => ['foo:string / bar:string', 'Can\'t divide string by string'];
        yield 'divide string by int' => ['foo:string / bar:int', 'Can\'t divide string by int'];
        yield 'divide int by string' => ['foo:int / bar:string', 'Can\'t divide int by string'];
        yield 'int modulo float' => ['foo:int % bar:float', 'Can\'t take int modulo float'];
        yield 'float modulo int' => ['foo:float % bar:int', 'Can\'t take float modulo int'];
        yield 'string modulo string' => ['foo:string % bar:string', 'Can\'t take string modulo string'];
        yield 'string modulo int' => ['foo:string % bar:int', 'Can\'t take string modulo int'];
        yield 'int modulo string' => ['foo:int % bar:string', 'Can\'t take int modulo string'];
        // A quotient is an Option of the operand type, so it doesn't chain into further arithmetic, comparison or
        // negation without an unwrap.
        yield 'chained division' => ['a:int / b:int / c:int', 'Can\'t divide Option<int> by int'];
        yield 'chained modulo' => ['a:int % b:int % c:int', 'Can\'t take Option<int> modulo int'];
        yield 'subtracting from a quotient' => ['a:int / b:int - c:int', 'Can\'t subtract int from Option<int>'];
        yield 'negating a quotient' => ['-(a:int / b:int)', 'Can\'t negate Option<int>'];
        yield 'comparing a quotient to its operand type' => [
            'a:int / b:int === 3',
            'The expressions of both sides of === must be of the same type. Left: Option<int>, right: int',
        ];
        yield 'int > float' => ['foo:int > bar:float', 'Can\'t compare int to float'];
        yield 'float > int' => ['foo:float > bar:int', 'Can\'t compare float to int'];
        yield 'string > string' => ['foo:string > bar:string', 'Can\'t compare string to string'];
        yield 'string > int' => ['foo:string > bar:int', 'Can\'t compare string to int'];
        yield 'int > string' => ['foo:int > bar:string', 'Can\'t compare string to int'];
        yield 'generic syntax on string' => ['foo:string<int>'];
        yield 'unknown variable type' => ['foo:notavalidtype'];
        yield 'map with no type arguments' => ['foo:map', 'The map type requires two arguments, none given'];
        yield 'map with one type argument' => ['foo:map<string>', 'Invalid type "map<string>"'];
        yield 'map with three type arguments' => ['foo:map<string, string, string>'];
        yield 'map with an unknown key type' => ['foo:map<Foo, string>'];
        yield 'map with an unknown value type' => ['foo:map<string, Foo>'];
        yield 'list with no type arguments' => ['foo:list', 'The list type requires one argument, none given'];
        yield 'list with two type arguments' => ['foo:list<string, string>', 'Invalid type "list<string, string>"'];
        yield 'list with two type arguments, second is struct' => [
            'foo:list<string, { name: string }>',
            'Invalid type "list<string, { name: string }>"',
        ];
        yield 'list with an unknown type argument' => ['foo:list<Foo>'];
        yield 'function call with unknown type' => ['foo:string.substr:Foo(0, 3)'];
        yield 'negating a string literal' => ['-"foo"', 'Can\'t negate string'];
        yield 'negating a string variable' => ['-foo:string', 'Can\'t negate string'];
        yield 'option without type argument' => ['foo:Option', 'The Option type requires one argument, none given'];
        yield 'option with two type arguments' => [
            'foo:Option<string, string>',
            'Invalid type "Option<string, string>": Option expects exactly one argument, got 2',
        ];
        yield 'option with invalid type argument' => ['foo:Option<Foo>', 'Unknown type Foo'];
        yield 'inline variable type does not match declared' => [
            'foo:string',
            'Variable foo is declared as int, but used as string',
            new Declarations(variables: ['foo' => Type::int()]),
        ];
        yield 'inline function return type does not match declared' => [
            'foo:string.substr:int(0, 3)',
            'Inline return type int of function substr does not match declared return type string',
        ];
        yield 'calling a function on the wrong type' => [
            'foo:int.substr(0, 3)',
            'substr must be called on an expression of type string, but foo:int is of type int',
        ];
        yield 'calling a function that doesn\'t take any arguments' => [
            'foo:string.myCustomFn()',
            'myCustomFn can\'t be used as a receiver function because it doesn\'t accept any arguments',
            new Declarations(functions: ['myCustomFn' => Type::func(Type::string())]),
        ];
        yield 'too few function arguments' => [
            'foo:string.substr(0)',
            'substr expects 2 arguments, got 1',
        ];
        yield 'too many function arguments' => [
            'foo:string.substr(0, 3, 9)',
            'substr expects 2 arguments, got 3',
        ];
        yield 'arguments passed to a function that only takes a receiver' => [
            'foo:string.myCustomFn(42)',
            'myCustomFn expects 0 arguments, got 1',
            new Declarations(functions: ['myCustomFn' => Type::func(Type::string(), [Type::string()])]),
        ];
        yield 'wrong argument type' => [
            'foo:string.substr(0, "3")',
            'Argument 2 of substr must be of type int, got string',
        ];
        yield 'lambda returning the wrong type' => [
            'x:list<string>.some(|i| i:string)',
            'Argument 1 of some must be of type func(any): bool, got func(any): string',
        ];
        yield 'passing a string to a function expecting a lambda' => [
            'x:list<string>.some("foo")',
            'Argument 1 of some must be of type func(any): bool, got string',
        ];
        yield 'passing a lambda to a function expecting an int' => [
            'x:string.substr(|i| i:int, 3)',
            'Argument 1 of substr must be of type int, got func(any): int',
        ];
        yield 'calling contains on an int' => [
            'x:int.contains(42)',
            'contains must be called on an expression of type list<any>, but x:int is of type int',
        ];
        yield 'call to undeclared function without an inline type' => [
            'x:string.foo()',
            'Function foo is not declared and has no inline type',
        ];
        yield 'some with invalid type argument' => ['foo:Some<Foo>', 'Unknown type Foo'];
        yield 'unknown type in struct field' => ['foo:{ name: Foo }', 'Unknown type Foo'];
        yield 'access to unknown struct field' => [
            'foo:{ name: string }.age',
            'Unknown field "age" on type { name: string }',
        ];
        yield 'field access on string' => ['foo:string.age', 'Can\'t access field "age" on non-struct type string'];
    }

    /**
     * @return iterable<array-key, array{string, Span}>
     */
    public static function syntaxErrorLocationCases(): iterable
    {
        $cases = [
            [
                '--',
                '  =',
            ],
            [
                'x:list<int>.take:(5)',
                '                 =  ',
            ],
            [
                'x:list<int>.take:<int>(5)',
                '                 =       ',
            ],
            [
                'x:.take:list<int>(5)',
                '  =                 ',
            ],
            [
                'x:<int>.take:list<int>(5)',
                '  =                      ',
            ],
            [
                'x.take:list<int>(5)',
                '=                  ',
            ],
            [
                '|x x:string',
                '   =       ',
            ],
            [
                '|x, x:string',
                '     =      ',
            ],
            [
                '|x y| x:int + y:int',
                '   =               ',
            ],
            [
                '"foo" === :string',
                '          =      ',
            ],
            [
                '-42 === :int',
                '        =   ',
            ],
            [
                'x:bool || :bool',
                '          =    ',
            ],
            [
                'x:list<int>.',
                '            =',
            ],
            [
                'x:list<int>.take:',
                '                 =',
            ],
            [
                'x:list<int>.take: <int>(5)',
                '                  =       ',
            ],
            [
                'x:list<int>.take:list<int>(',
                '                           =',
            ],
            [
                'x:list<',
                '       =',
            ],
            [
                '',
                '=',
            ],
            [
                'x:list<int>.take:list<int>(===)',
                '                           === ',
            ],
            [
                '|x, "test"| x:string',
                '    ======          ',
            ],
            [
                'foo:bool & bar:bool',
                '         =         ',
            ],
            [
                'foo:bool && bar::bool',
                '                =    ',
            ],
            // An empty group is blamed at the close paren, where the expression it should have held is missing. A
            // surplus close paren is blamed at itself, the token with nothing left to close.
            [
                '()',
                ' =',
            ],
            [
                '(a:bool))',
                '        =',
            ],
        ];
        foreach ($cases as [$expression, $location]) {
            preg_match('/^(?<spaces> *)(?<underline>=+)/', $location, $matches);
            $startColumn = strlen($matches['spaces'] ?? '') + 1;
            $endColumn = $startColumn + strlen($matches['underline'] ?? '') - 1;
            assert($endColumn > 0);
            yield $expression => [$expression, new Span(1, $startColumn, 1, $endColumn)];
        }
        yield [
            <<<'EXPR'

                  :list<int>
                EXPR,
            Span::char(2, 3),
        ];
        yield [
            <<<'EXPR'
                  foo:string
                    === :string
                EXPR,
            Span::char(2, 9),
        ];
    }

    /**
     * @return iterable<array-key, array{string, Span}>
     */
    public static function typeErrorLocationCases(): iterable
    {
        $cases = [
            // > and - enforce the same rule -- both operands numeric and of the same type -- so they blame the same
            // operand for the same mistake. Compare the pair below with the '42 - "foo"' / '"foo" - 42' pair.
            [
                '42 > "foo"',
                '     =====',
            ],
            [
                '"foo" > 42',
                '=====     ',
            ],
            [
                'x:list<string, int>',
                '       =========== ',
            ],
            [
                'x:int<string>',
                '      ====== ',
            ],
            [
                '"foo" === 42',
                '          ==',
            ],
            [
                'a:int || b:bool',
                '=====          ',
            ],
            [
                'a:bool || b:int',
                '          =====',
            ],
            [
                'a:bool && b:int || c:bool',
                '          =====          ',
            ],
            [
                'a:bool || b:bool && c:int',
                '                    =====',
            ],
            [
                '42 - "foo"',
                '     =====',
            ],
            [
                '"foo" - 42',
                '=====     ',
            ],
            [
                '"foo" === 72 - 23',
                '          =======',
            ],
            [
                '"foo" === 72 + 23',
                '          =======',
            ],
            [
                '"foo" === 72 * 23',
                '          =======',
            ],
            [
                '"foo" === 72 / 23',
                '          =======',
            ],
            [
                '"foo" === 72 % 23',
                '          =======',
            ],
            [
                'foo:map<bool, string>',
                '        ====         ',
            ],
            [
                'foo:string === -bar:int',
                '               ========',
            ],
            [
                'foo:string === bar:list<string>.count:int()',
                '               ============================',
            ],
            [
                'foo:Option',
                '    ======',
            ],
            [
                'foo:Option<>',
                '    ========',
            ],
            [
                'foo:Option<string, int, bool>',
                '                   ========= ',
            ],
            // Calls are checked against the function's declared signature, so their type errors point at the operand
            // that doesn't fit it: the receiver, or the argument. A missing argument has no location of its own, so
            // the error spans the whole call; one too many is right there to point at.
            [
                'foo:int.substr(0, 3)',
                '=======             ',
            ],
            [
                'x:int.contains(42)',
                '=====             ',
            ],
            [
                'foo:string.substr(0)',
                '====================',
            ],
            [
                'foo:string.substr(0, 3, 9)',
                '                        = ',
            ],
            [
                'foo:string.substr(0, "3")',
                '                     === ',
            ],
            [
                'x:list<string>.some("foo")',
                '                    =====  ',
            ],
            [
                'x:string.foo()',
                '         ===  ',
            ],
        ];
        foreach ($cases as [$expression, $location]) {
            preg_match('/^(?<spaces> *)(?<underline>=+)/', $location, $matches);
            $startColumn = strlen($matches['spaces'] ?? '') + 1;
            $endColumn = $startColumn + strlen($matches['underline'] ?? '') - 1;
            assert($endColumn > 0);
            yield $expression => [$expression, new Span(1, $startColumn, 1, $endColumn)];
        }
    }

    private static function assertLocation(Span $expected, Span|null $actual): void
    {
        self::assertNotNull($actual);
        self::assertSame(
            [$expected->startLine, $expected->startColumn, $expected->endLine, $expected->endColumn],
            [$actual->startLine, $actual->startColumn, $actual->endLine, $actual->endColumn],
            sprintf(
                'Expected the error to span %d:%d-%d:%d, got %d:%d-%d:%d',
                $expected->startLine,
                $expected->startColumn,
                $expected->endLine,
                $expected->endColumn,
                $actual->startLine,
                $actual->startColumn,
                $actual->endLine,
                $actual->endColumn,
            ),
        );
    }

    #[DataProvider('invalidSyntaxExpressions')]
    public function testSyntaxError(string $expression, string|null $expectedMessage = null): void
    {
        $this->expectException(SyntaxError::class);
        if ($expectedMessage !== null) {
            $this->expectExceptionMessage($expectedMessage);
        }

        ExpressionParser::parse($expression);
    }

    #[DataProvider('typeErrorExpressions')]
    public function testTypeError(string $expression, string|null $expectedMessage = null, Declarations|null $declarations = null): void
    {
        $this->expectException(TypeError::class);
        if ($expectedMessage !== null) {
            $this->expectExceptionMessage($expectedMessage);
        }

        ExpressionParser::parse($expression, $declarations);
    }

    public function testParseTypedThrowsIfTheExpressionDoesNotMatchTheGivenType(): void
    {
        $this->expectException(TypeError::class);
        $this->expectExceptionMessage('Expected parsed expression to be of type string, got list<string>');

        ExpressionParser::parseTyped('foo:list<string>', Type::string());
    }

    #[DataProvider('syntaxErrorLocationCases')]
    public function testSyntaxErrorLocation(string $expression, Span $expected): void
    {
        try {
            ExpressionParser::parse($expression);
            self::fail('Expected a SyntaxError');
        } catch (SyntaxError $e) {
            self::assertLocation($expected, $e->location);
        }
    }

    #[DataProvider('typeErrorLocationCases')]
    public function testTypeErrorLocation(string $expression, Span $expected): void
    {
        try {
            ExpressionParser::parse($expression);
            self::fail('Expected a TypeError');
        } catch (TypeError $e) {
            self::assertLocation($expected, $e->location);
        }
    }
}
