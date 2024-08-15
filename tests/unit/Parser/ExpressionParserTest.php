<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit\Parser;

use Eventjet\Ausdruck\Expr;
use Eventjet\Ausdruck\Expression;
use Eventjet\Ausdruck\Parser\Declarations;
use Eventjet\Ausdruck\Parser\ExpressionParser;
use Eventjet\Ausdruck\Parser\Span;
use Eventjet\Ausdruck\Parser\SyntaxError;
use Eventjet\Ausdruck\Parser\TypeError;
use Eventjet\Ausdruck\Type;
use PHPUnit\Framework\TestCase;

use function assert;
use function preg_match;
use function sprintf;
use function strlen;

final class ExpressionParserTest extends TestCase
{
    /**
     * @return iterable<array-key, array{string, Expression}>
     */
    public static function parseCases(): iterable
    {
        $s = Type::string();
        $cases = [
            ['foo:string', Expr::get('foo', $s)],
            ['"my-literal"', Expr::literal('my-literal')],
            ['foo:string === bar:string', Expr::get('foo', Type::string())->eq(Expr::get('bar', Type::string()))],
            ['foo:bool || bar:bool', Expr::get('foo', Type::bool())->or_(Expr::get('bar', Type::bool()))],
            [
                'haystack:list<string>.some:bool(|item| item:string === needle:string)',
                Expr::get('haystack', Type::listOf($s))->call(
                    'some',
                    Type::bool(),
                    [Expr::lambda(Expr::get('item', $s)->eq(Expr::get('needle', $s)), ['item'])],
                ),
            ],
            [
                '|foo, bar| foo:bool || bar:bool',
                Expr::lambda(Expr::get('foo', Type::bool())->or_(Expr::get('bar', Type::bool())), ['foo', 'bar']),
            ],
            ['23.42', Expr::literal(23.42)],
            ['-23.42', Expr::literal(-23.42)],
            ['69', Expr::literal(69)],
            ['-69', Expr::literal(-69)],
            ['69 - foo:int', Expr::literal(69)->subtract(Expr::get('foo', Type::int()))],
            [
                'a:int - b:int - c:int',
                Expr::subtract(
                    Expr::subtract(
                        Expr::get('a', Type::int()),
                        Expr::get('b', Type::int()),
                    ),
                    Expr::get('c', Type::int()),
                ),
            ],
            ['"💩"', Expr::literal('💩')],
            ['foo:map<string, int>', Expr::get('foo', Type::mapOf(Type::string(), Type::int()))],
        ];
        foreach ($cases as $case) {
            yield $case[0] => $case;
        }
    }

    /**
     * @return iterable<array-key, array{string, Expression}>
     */
    public static function nonCanonicalParseCases(): iterable
    {
        $cases = [
            [
                '|foo, bar,| foo:bool || bar:bool',
                Expr::lambda(Expr::get('foo', Type::bool())->or_(Expr::get('bar', Type::bool())), ['foo', 'bar']),
            ],
            ['69-foo:int', Expr::literal(69)->subtract(Expr::get('foo', Type::int()))],
            [
                // Newline after variable type
                '
                foo:string
                ',
                Expr::get('foo', Type::string()),
            ],
        ];
        foreach ($cases as $case) {
            yield $case[0] => $case;
        }
    }

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
        yield 'empty string' => ['', 'Expected expression, got end of input'];
        yield 'missing left hand side of >' => ['> foo:int'];
        yield 'missing right hand side of >' => ['foo:int >'];
        yield 'end of string variable and colon' => ['foo:'];
        yield 'end of string after function call and colon' => ['foo:string.substr:'];
        yield 'end of string after function dot' => ['foo:string.'];
        yield 'missing function name' => ['foo:string.:string()'];
        yield 'end of string after function name' => ['foo:string.substr'];
        yield 'list literal: missing closing bracket' => ['[1, 2'];
        yield 'empty pair or curly braces' => ['{}'];
        yield 'single ampersand' => ['foo:bool & bar:bool'];
    }

    /**
     * @return iterable<string, array{0: string, 1?: string, 2?: Declarations}>
     */
    public static function invalidExpressions(): iterable
    {
        yield 'map type with bool key type' => ['foo:map<bool, string>'];
        yield 'or with string on the left' => ['foo:string || bar:bool'];
        yield 'or with string on the right' => ['foo:bool || bar:string'];
        yield 'equals: different operand types' => ['foo:string === bar:int'];
        yield 'subtract int from float' => ['foo:float - bar:int', 'Can\'t subtract int from float'];
        yield 'subtract float from int' => ['foo:int - bar:float', 'Can\'t subtract float from int'];
        yield 'subtract string from string' => ['foo:string - bar:string', 'Can\'t subtract string from string'];
        yield 'subtract string from int' => ['foo:int - bar:string', 'Can\'t subtract string from int'];
        yield 'subtract int from string' => ['foo:string - bar:int', 'Can\'t subtract int from string'];
        yield 'int > float' => ['foo:int > bar:float'];
        yield 'float > int' => ['foo:float > bar:int'];
        yield 'string > string' => ['foo:string > bar:string'];
        yield 'string > int' => ['foo:string > bar:int'];
        yield 'int > string' => ['foo:int > bar:string'];
        yield 'generic syntax on string' => ['foo:string<int>'];
        yield 'unknown variable type' => ['foo:notavalidtype'];
        yield 'map with no type arguments' => ['foo:map', 'The map type requires two arguments, none given'];
        yield 'map with one type argument' => ['foo:map<string>', 'Invalid type "map<string>"'];
        yield 'map with three type arguments' => ['foo:map<string, string, string>'];
        yield 'map with an unknown key type' => ['foo:map<Foo, string>'];
        yield 'map with an unknown value type' => ['foo:map<string, Foo>'];
        yield 'list with no type arguments' => ['foo:list', 'The list type requires one argument, none given'];
        yield 'list with two type arguments' => ['foo:list<string, string>', 'Invalid type "list<string, string>"'];
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
            [
                '42 > "foo"',
                '     =====',
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
        ];
        foreach ($cases as [$expression, $location]) {
            preg_match('/^(?<spaces> *)(?<underline>=+)/', $location, $matches);
            $startColumn = strlen($matches['spaces'] ?? '') + 1;
            $endColumn = $startColumn + strlen($matches['underline'] ?? '') - 1;
            assert($endColumn > 0);
            yield $expression => [$expression, new Span(1, $startColumn, 1, $endColumn)];
        }
    }

    /**
     * @dataProvider parseCases
     * @dataProvider nonCanonicalParseCases
     */
    public function testParse(string $str, Expression $expected): void
    {
        $actual = ExpressionParser::parse($str);

        self::assertTrue($actual->equals($expected), sprintf(
            "Expected:\n%s\nActual:\n%s",
            $expected,
            $actual,
        ));
    }

    /**
     * @dataProvider parseCases
     */
    public function testToString(string $expected, Expression $expr): void
    {
        self::assertSame($expected, (string)$expr);
    }

    /**
     * @dataProvider invalidSyntaxExpressions
     */
    public function testSyntaxError(string $expression, string|null $expectedMessage = null): void
    {
        $this->expectException(SyntaxError::class);
        if ($expectedMessage !== null) {
            $this->expectExceptionMessage($expectedMessage);
        }

        ExpressionParser::parse($expression);
    }

    /**
     * @dataProvider invalidExpressions
     */
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

    /**
     * @dataProvider typeErrorLocationCases
     */
    public function testTypeErrorLocation(string $expression, Span $expected): void
    {
        try {
            ExpressionParser::parse($expression);
            self::fail('Expected a TypeError');
        } catch (TypeError $e) {
            self::assertNotNull($e->location);
            self::assertSame(
                [$expected->startLine, $expected->startColumn, $expected->endLine, $expected->endColumn],
                [$e->location->startLine, $e->location->startColumn, $e->location->endLine, $e->location->endColumn],
                sprintf(
                    'Expected the error to span %d:%d-%d:%d, got %d:%d-%d:%d',
                    $expected->startLine,
                    $expected->startColumn,
                    $expected->endLine,
                    $expected->endColumn,
                    $e->location->startLine,
                    $e->location->startColumn,
                    $e->location->endLine,
                    $e->location->endColumn,
                ),
            );

        }
    }

    public function testParseTypedReturnsTheParsedExpressionIfItMatchesTheGivenType(): void
    {
        $actual = ExpressionParser::parseTyped('foo:string', Type::string());

        self::assertSame('foo:string', (string)$actual);
    }

    /**
     * @dataProvider syntaxErrorLocationCases
     */
    public function testSyntaxErrorLocation(string $expression, Span $expected): void
    {
        try {
            ExpressionParser::parse($expression);
            self::fail('Expected a SyntaxError');
        } catch (SyntaxError $e) {
            self::assertNotNull($e->location);
            self::assertSame(
                [$expected->startLine, $expected->startColumn, $expected->endLine, $expected->endColumn],
                [$e->location->startLine, $e->location->startColumn, $e->location->endLine, $e->location->endColumn],
                sprintf(
                    'Expected the error to span %d:%d-%d:%d, got %d:%d-%d:%d',
                    $expected->startLine,
                    $expected->startColumn,
                    $expected->endLine,
                    $expected->endColumn,
                    $e->location->startLine,
                    $e->location->startColumn,
                    $e->location->endLine,
                    $e->location->endColumn,
                ),
            );

        }
    }
}
