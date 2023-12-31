<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit\Parser;

use Eventjet\Ausdruck\Expr;
use Eventjet\Ausdruck\Expression;
use Eventjet\Ausdruck\Parser\ExpressionParser;
use Eventjet\Ausdruck\Parser\SyntaxError;
use Eventjet\Ausdruck\Parser\TypeError;
use Eventjet\Ausdruck\Type;
use PHPUnit\Framework\TestCase;

use function sprintf;

final class ExpressionParserTest extends TestCase
{
    /**
     * @return iterable<array-key, array{string, Expression<mixed>}>
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
            ['myMap:map<string, int>', Expr::get('myMap', Type::mapOf(Type::string(), Type::int()))],
        ];
        foreach ($cases as $case) {
            yield $case[0] => $case;
        }
    }

    /**
     * @return iterable<array-key, array{string, Expression<mixed>}>
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
        yield 'offset without a target' => ['["foo"]'];
        yield 'missing variable type' => ['foo'];
        yield 'missing variable type in sub-expression' => ['foo === bar:true', 'Expected :, got ==='];
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
    }

    /**
     * @return iterable<string, array{0: string, 1?: string}>
     */
    public static function invalidExpressions(): iterable
    {
        yield 'map type with bool key type' => ['foo:map<bool, string>'];
        yield 'or with string on the left' => ['foo:string || bar:bool'];
        yield 'or with string on the right' => ['foo:bool || bar:string'];
        yield 'equals: different operand types' => ['foo:string === bar:int'];
        yield 'subtract int from float' => ['foo:float - bar:int'];
        yield 'subtract float from int' => ['foo:int - bar:float'];
        yield 'subtract string from string' => ['foo:string - bar:string'];
        yield 'subtract string from int' => ['foo:int - bar:string'];
        yield 'subtract int from string' => ['foo:string - bar:int'];
        yield 'int > float' => ['foo:int > bar:float'];
        yield 'float > int' => ['foo:float > bar:int'];
        yield 'string > string' => ['foo:string > bar:string'];
        yield 'string > int' => ['foo:string > bar:int'];
        yield 'int > string' => ['foo:int > bar:string'];
        yield 'generic syntax on string' => ['foo:string<int>'];
        yield 'unknown variable type' => ['foo:notavalidtype'];
        yield 'map with no type arguments' => ['foo:map', 'Invalid type "map"'];
        yield 'map with one type argument' => ['foo:map<string>', 'Invalid type "map<string>"'];
        yield 'map with three type arguments' => ['foo:map<string, string, string>'];
        yield 'map with an unknown key type' => ['foo:map<Foo, string>'];
        yield 'map with an unknown value type' => ['foo:map<string, Foo>'];
        yield 'list with no type arguments' => ['foo:list', 'Invalid type "list"'];
        yield 'list with two type arguments' => ['foo:list<string, string>', 'Invalid type "list<string, string>"'];
        yield 'list with an unknown type argument' => ['foo:list<Foo>'];
        yield 'function call with unknown type' => ['foo:string.substr:Foo(0, 3)'];
    }

    /**
     * @param Expression<mixed> $expected
     * @dataProvider parseCases
     * @dataProvider nonCanonicalParseCases
     */
    public function testParse(string $str, Expression $expected): void
    {
        $actual = ExpressionParser::parse($str);

        /** @psalm-suppress ImplicitToStringCast */
        self::assertTrue($actual->equals($expected), sprintf(
            "Expected:\n%s\nActual:\n%s",
            $expected,
            $actual,
        ));
    }

    /**
     * @param Expression<mixed> $expr
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
    public function testTypeError(string $expression, string|null $expectedMessage = null): void
    {
        $this->expectException(TypeError::class);
        if ($expectedMessage !== null) {
            $this->expectExceptionMessage($expectedMessage);
        }

        ExpressionParser::parse($expression);
    }

    public function testParseTypedThrowsIfTheExpressionDoesNotMatchTheGivenType(): void
    {
        $this->expectException(TypeError::class);
        $this->expectExceptionMessage('Expected parsed expression to be of type string, got list<string>');

        ExpressionParser::parseTyped('foo:list<string>', Type::string());
    }

    public function testParseTypedReturnsTheParsedExpressionIfItMatchesTheGivenType(): void
    {
        $actual = ExpressionParser::parseTyped('foo:string', Type::string());

        self::assertSame('foo:string', (string)$actual);
    }
}
