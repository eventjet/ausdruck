<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit\Parser;

use Eventjet\Ausdruck\Expr;
use Eventjet\Ausdruck\Expression;
use Eventjet\Ausdruck\Parser\Declarations;
use Eventjet\Ausdruck\Parser\ExpressionParser;
use Eventjet\Ausdruck\Parser\Span;
use Eventjet\Ausdruck\Parser\Types;
use Eventjet\Ausdruck\Type;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function sprintf;

/**
 * What the parser accepts, and how those expressions print back out. {@see self::parseCases()} feeds both directions,
 * so every case there fixes the canonical spelling of its tree: parsing it and printing the result has to give the
 * string back unchanged. {@see self::nonCanonicalParseCases()} holds the spellings that parse to the same tree but
 * aren't what it prints as. What the parser rejects belongs to {@see ExpressionParserErrorTest}.
 */
final class ExpressionParserTest extends TestCase
{
    /**
     * @return iterable<array-key, array{string, Expression}>
     */
    public static function parseCases(): iterable
    {
        $s = Type::string();
        $b = Type::bool();
        $i = Type::int();
        /**
         * One expression that spans the entire cascade: || is its loosest level, and the postfix . of a field access
         * and of a call its tightest. A position that parses it has to run every level in between, because that's the
         * only way down from || to the dot. Nesting it below is therefore a stronger statement than nesting any single
         * operator would be, and the reason there's no case per operator down there.
         */
        $wholeCascade = 'o:{ n: string }.n === s:string.substr:string(0, 1) || b:bool';
        $wholeCascadeExpr = Expr::or_(
            Expr::eq(
                Expr::fieldAccess(Expr::get('o', Type::struct(['n' => $s])), 'n', Span::char(1, 1)),
                Expr::get('s', $s)->call('substr', $s, [Expr::literal(0), Expr::literal(1)]),
            ),
            Expr::get('b', $b),
        );
        $cases = [
            ['foo:string', Expr::get('foo', $s)],
            ['"my-literal"', Expr::literal('my-literal')],
            ['foo:string === bar:string', Expr::get('foo', Type::string())->eq(Expr::get('bar', Type::string()))],
            ['foo:string !== bar:string', Expr::get('foo', $s)->neq(Expr::get('bar', $s))],
            ['foo:int > bar:int', Expr::get('foo', $i)->gt(Expr::get('bar', $i))],
            // `<` doubles as a generic's opening bracket, so this pins that the parser reads it as less-than here, not as
            // the start of a broken `int<...>` type.
            ['foo:int < bar:int', Expr::get('foo', $i)->lt(Expr::get('bar', $i))],
            ['foo:int >= bar:int', Expr::get('foo', $i)->gte(Expr::get('bar', $i))],
            ['foo:int <= bar:int', Expr::get('foo', $i)->lte(Expr::get('bar', $i))],
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
            // + and - share the additive level, * / % the multiplicative one, so a chain mixing operators of one
            // level is still left-associative across them.
            [
                'a:int + b:int + c:int',
                Expr::add(Expr::add(Expr::get('a', $i), Expr::get('b', $i)), Expr::get('c', $i)),
            ],
            [
                'a:int + b:int - c:int',
                Expr::subtract(Expr::add(Expr::get('a', $i), Expr::get('b', $i)), Expr::get('c', $i)),
            ],
            [
                'a:int - b:int + c:int',
                Expr::add(Expr::subtract(Expr::get('a', $i), Expr::get('b', $i)), Expr::get('c', $i)),
            ],
            [
                'a:int * b:int * c:int',
                Expr::multiply(Expr::multiply(Expr::get('a', $i), Expr::get('b', $i)), Expr::get('c', $i)),
            ],
            [
                'a:int * b:int / c:int',
                Expr::divide(Expr::multiply(Expr::get('a', $i), Expr::get('b', $i)), Expr::get('c', $i)),
            ],
            [
                'a:int * b:int % c:int',
                Expr::modulo(Expr::multiply(Expr::get('a', $i), Expr::get('b', $i)), Expr::get('c', $i)),
            ],
            ['"💩"', Expr::literal('💩')],
            ['foo:map<string, int>', Expr::get('foo', Type::mapOf(Type::string(), Type::int()))],
            [
                'a:bool && b:bool || c:bool',
                Expr::or_(
                    Expr::and_(Expr::get('a', $b), Expr::get('b', $b)),
                    Expr::get('c', $b),
                ),
            ],
            [
                'a:bool || b:bool && c:bool',
                Expr::or_(
                    Expr::get('a', $b),
                    Expr::and_(Expr::get('b', $b), Expr::get('c', $b)),
                ),
            ],
            [
                'a:bool && b:bool && c:bool',
                Expr::and_(
                    Expr::and_(Expr::get('a', $b), Expr::get('b', $b)),
                    Expr::get('c', $b),
                ),
            ],
            [
                'a:bool || b:bool || c:bool',
                Expr::or_(
                    Expr::or_(Expr::get('a', $b), Expr::get('b', $b)),
                    Expr::get('c', $b),
                ),
            ],
            // Each level of the cascade binds tighter than the one above it. Evaluating an expression can only ever
            // half-prove that: && and || are monotone, so a wrong grouping of `a && b || c` can return the wrong value
            // for operands that make it true, but never for operands that make it false. The grouping itself is what
            // has to be pinned, so every adjacent pair of levels is checked here rather than by example.
            [
                'a:int - b:int > c:int && d:bool',
                Expr::and_(
                    Expr::gt(
                        Expr::subtract(Expr::get('a', $i), Expr::get('b', $i)),
                        Expr::get('c', $i),
                    ),
                    Expr::get('d', $b),
                ),
            ],
            [
                'a:int === b:int - c:int || d:bool',
                Expr::or_(
                    Expr::eq(
                        Expr::get('a', $i),
                        Expr::subtract(Expr::get('b', $i), Expr::get('c', $i)),
                    ),
                    Expr::get('d', $b),
                ),
            ],
            // A comparison prints both of its operands at the additive level, and the cases above only ever put a
            // looser expression on the left. These put one on the right, so dropping the parentheses there is caught:
            // without them `a:bool === b:bool || c:bool` re-parses as `(a === b) || c`, a different tree.
            [
                'a:bool === (b:bool || c:bool)',
                Expr::eq(Expr::get('a', $b), Expr::or_(Expr::get('b', $b), Expr::get('c', $b))),
            ],
            [
                'a:bool !== (b:bool && c:bool)',
                Expr::neq(Expr::get('a', $b), Expr::and_(Expr::get('b', $b), Expr::get('c', $b))),
            ],
            // Comparison is non-associative, so a comparison nested in a comparison's operand slot needs the
            // parentheses whichever side it sits on.
            [
                'a:bool === (b:int > c:int)',
                Expr::eq(Expr::get('a', $b), Expr::gt(Expr::get('b', $i), Expr::get('c', $i))),
            ],
            // Multiplicative binds tighter than additive.
            [
                'a:int + b:int * c:int',
                Expr::add(Expr::get('a', $i), Expr::multiply(Expr::get('b', $i), Expr::get('c', $i))),
            ],
            [
                'a:int * b:int - c:int',
                Expr::subtract(Expr::multiply(Expr::get('a', $i), Expr::get('b', $i)), Expr::get('c', $i)),
            ],
            // ...and on the right of - just like on the right of +: a multiplicative subtrahend prints bare.
            [
                'a:int - b:int * c:int',
                Expr::subtract(Expr::get('a', $i), Expr::multiply(Expr::get('b', $i), Expr::get('c', $i))),
            ],
            // Unary binds tighter than additive, so this subtracts a negation rather than negating a subtraction.
            [
                'a:int - -b:int',
                Expr::subtract(Expr::get('a', $i), Expr::negative(Expr::get('b', $i))),
            ],
            [
                'a:int + -b:int',
                Expr::add(Expr::get('a', $i), Expr::negative(Expr::get('b', $i))),
            ],
            // ...and tighter than multiplicative, so this multiplies by a negation.
            [
                'a:int * -b:int',
                Expr::multiply(Expr::get('a', $i), Expr::negative(Expr::get('b', $i))),
            ],
            // Operators are allowed wherever an expression is expected, not just at the top level.
            [
                '[1 - 2]',
                Expr::listLiteral([Expr::subtract(Expr::literal(1), Expr::literal(2))], Span::char(1, 1)),
            ],
            [
                '{a: 1 - 2}',
                Expr::structLiteral(['a' => Expr::subtract(Expr::literal(1), Expr::literal(2))], Span::char(1, 1)),
            ],
            [
                '"abcdef".substr:string(5 - 3, 2)',
                Expr::literal('abcdef')->call(
                    'substr',
                    $s,
                    [Expr::subtract(Expr::literal(5), Expr::literal(3)), Expr::literal(2)],
                ),
            ],
            // A call argument, a list item and a struct field value are full expressions, like a lambda body: each is
            // parsed by parseExpression() rather than by some smaller grammar of its own. The item and field cases put
            // a second element after the nested expression, because the operators in it must not swallow the comma
            // that ends it.
            [
                sprintf('xs:list<bool>.contains:bool(%s)', $wholeCascade),
                Expr::get('xs', Type::listOf($b))->call('contains', $b, [$wholeCascadeExpr]),
            ],
            [
                sprintf('[%s, false]', $wholeCascade),
                Expr::listLiteral([$wholeCascadeExpr, Expr::literal(false)], Span::char(1, 1)),
            ],
            [
                sprintf('{matches: %s, fallback: b:bool}', $wholeCascade),
                Expr::structLiteral(
                    ['matches' => $wholeCascadeExpr, 'fallback' => Expr::get('b', $b)],
                    Span::char(1, 1),
                ),
            ],
            // Parentheses group without leaving a node of their own, so a grouped tree prints with exactly the
            // parentheses needed to parse it back the same way: one per operand that would otherwise be captured by a
            // tighter operator around it. There's a canonical case for each level of the cascade being forced to sit
            // inside a looser one, which is the grouping that level's precedence would never produce on its own.
            [
                'a:bool && (b:bool || c:bool)',
                Expr::and_(Expr::get('a', $b), Expr::or_(Expr::get('b', $b), Expr::get('c', $b))),
            ],
            [
                '(a:bool || b:bool) && c:bool',
                Expr::and_(Expr::or_(Expr::get('a', $b), Expr::get('b', $b)), Expr::get('c', $b)),
            ],
            [
                'a:bool || (b:bool || c:bool)',
                Expr::or_(Expr::get('a', $b), Expr::or_(Expr::get('b', $b), Expr::get('c', $b))),
            ],
            [
                'a:bool && (b:bool && c:bool)',
                Expr::and_(Expr::get('a', $b), Expr::and_(Expr::get('b', $b), Expr::get('c', $b))),
            ],
            [
                'a:int - (b:int - c:int)',
                Expr::subtract(Expr::get('a', $i), Expr::subtract(Expr::get('b', $i), Expr::get('c', $i))),
            ],
            [
                'a:int + (b:int + c:int)',
                Expr::add(Expr::get('a', $i), Expr::add(Expr::get('b', $i), Expr::get('c', $i))),
            ],
            [
                'a:int * (b:int * c:int)',
                Expr::multiply(Expr::get('a', $i), Expr::multiply(Expr::get('b', $i), Expr::get('c', $i))),
            ],
            [
                '(a:int + b:int) * c:int',
                Expr::multiply(Expr::add(Expr::get('a', $i), Expr::get('b', $i)), Expr::get('c', $i)),
            ],
            [
                'a:int * (b:int + c:int)',
                Expr::multiply(Expr::get('a', $i), Expr::add(Expr::get('b', $i), Expr::get('c', $i))),
            ],
            [
                'a:int / (b:int * c:int)',
                Expr::divide(Expr::get('a', $i), Expr::multiply(Expr::get('b', $i), Expr::get('c', $i))),
            ],
            [
                'a:int % (b:int - c:int)',
                Expr::modulo(Expr::get('a', $i), Expr::subtract(Expr::get('b', $i), Expr::get('c', $i))),
            ],
            // A multiplicative-level divisor keeps its parentheses too: dropped, a:int % b:int * c:int would re-parse
            // as (a:int % b:int) * c:int — a different tree.
            [
                'a:int % (b:int * c:int)',
                Expr::modulo(Expr::get('a', $i), Expr::multiply(Expr::get('b', $i), Expr::get('c', $i))),
            ],
            [
                'a:int - (b:int + c:int)',
                Expr::subtract(Expr::get('a', $i), Expr::add(Expr::get('b', $i), Expr::get('c', $i))),
            ],
            [
                '-(a:int - b:int)',
                Expr::negative(Expr::subtract(Expr::get('a', $i), Expr::get('b', $i))),
            ],
            [
                '-(a:int * b:int)',
                Expr::negative(Expr::multiply(Expr::get('a', $i), Expr::get('b', $i))),
            ],
            // === and > are non-associative, so a parenthesized comparison is the only way one ends up inside another.
            [
                '(a:int === b:int) === c:bool',
                Expr::eq(Expr::eq(Expr::get('a', $i), Expr::get('b', $i)), Expr::get('c', $b)),
            ],
            [
                '(a:int > b:int) === c:bool',
                Expr::eq(Expr::gt(Expr::get('a', $i), Expr::get('b', $i)), Expr::get('c', $b)),
            ],
            // A comparison is the only place a quotient appears as a bare operand — every arithmetic slot rejects an
            // Option — so this is the one case that exercises the comparison level's left slot against a division.
            [
                'a:int / b:int === c:int / d:int',
                Expr::eq(
                    Expr::divide(Expr::get('a', $i), Expr::get('b', $i)),
                    Expr::divide(Expr::get('c', $i), Expr::get('d', $i)),
                ),
            ],
            // A method can be called on a grouped expression, so the postfix dot has to parenthesize a target looser
            // than a call's own — everything from a subtraction down to a negation.
            [
                '(a:int - b:int).abs:int()',
                Expr::subtract(Expr::get('a', $i), Expr::get('b', $i))->call('abs', $i, []),
            ],
            [
                '(a:int * b:int).abs:int()',
                Expr::multiply(Expr::get('a', $i), Expr::get('b', $i))->call('abs', $i, []),
            ],
            // The way to get at a quotient: / makes an Option, and isSome/unwrap are calls on it.
            [
                '(a:int / b:int).isSome:bool()',
                Expr::divide(Expr::get('a', $i), Expr::get('b', $i))->call('isSome', $b, []),
            ],
            [
                '(a:int / b:int).unwrap:int()',
                Expr::divide(Expr::get('a', $i), Expr::get('b', $i))->call('unwrap', $i, []),
            ],
            [
                '(a:int % b:int).unwrap:int()',
                Expr::modulo(Expr::get('a', $i), Expr::get('b', $i))->call('unwrap', $i, []),
            ],
            [
                '(-a:int).abs:int()',
                Expr::negative(Expr::get('a', $i))->call('abs', $i, []),
            ],
            // A lambda is the loosest target of all: its body runs rightward, so without the parentheses the printed
            // form would fold the `.foo` into the body and re-parse as a different tree.
            [
                '(|x| x:bool).foo:bool()',
                Expr::lambda(Expr::get('x', $b), ['x'])->call('foo', $b, []),
            ],
            // A number literal is primary-tight, yet a method call on one still has to wrap it: `2.abs:int()` would
            // read the `.` as a decimal point, and `-2.abs:int()` would bind the `.` tighter than the leading minus.
            [
                '(2).abs:int()',
                Expr::literal(2)->call('abs', $i, []),
            ],
            [
                '(-2).abs:int()',
                Expr::literal(-2)->call('abs', $i, []),
            ],
            [
                '(2.5).floor:int()',
                Expr::literal(2.5)->call('floor', $i, []),
            ],
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
            // Whitespace must not decide whether the minus is a subtraction or the sign of a literal.
            ['foo:int-2', Expr::subtract(Expr::get('foo', Type::int()), Expr::literal(2))],
            ['foo:int -2', Expr::subtract(Expr::get('foo', Type::int()), Expr::literal(2))],
            ['foo:int- 2', Expr::subtract(Expr::get('foo', Type::int()), Expr::literal(2))],
            // The other arithmetic operators don't double as a literal's sign, so whitespace has nothing to decide;
            // it just must not matter.
            ['foo:int+2', Expr::add(Expr::get('foo', Type::int()), Expr::literal(2))],
            ['foo:int +2', Expr::add(Expr::get('foo', Type::int()), Expr::literal(2))],
            ['foo:int*2', Expr::multiply(Expr::get('foo', Type::int()), Expr::literal(2))],
            ['foo:int/2', Expr::divide(Expr::get('foo', Type::int()), Expr::literal(2))],
            ['foo:int%2', Expr::modulo(Expr::get('foo', Type::int()), Expr::literal(2))],
            // Trailing commas are allowed in argument and list literal element lists.
            [
                'foo:string.substr:string(0, 3,)',
                Expr::get('foo', Type::string())->call(
                    'substr',
                    Type::string(),
                    [Expr::literal(0), Expr::literal(3)],
                ),
            ],
            ['[1, 2,]', Expr::listLiteral([Expr::literal(1), Expr::literal(2)], Span::char(1, 1))],
            [
                // Newline after variable type
                '
                foo:string
                ',
                Expr::get('foo', Type::string()),
            ],
            // A negated literal is a literal like any other, so the levels below unary still apply to it: postfix binds
            // tighter than the minus, exactly as it does for -foo:int.abs:int().
            [
                '-2 .abs:int()',
                Expr::negative(Expr::literal(2)->call('abs', Type::int(), [])),
            ],
            // Negating a number literal folds into a negative literal, so the fold survives being nested.
            ['[-2]', Expr::listLiteral([Expr::literal(-2)], Span::char(1, 1))],
            ['- -1', Expr::literal(1)],
            // Parentheses that only restate the default grouping leave no trace: the tree is the one the operators
            // would have built anyway, so it prints back without them. A single atom in parentheses is the same atom.
            ['(a:bool)', Expr::get('a', Type::bool())],
            ['((a:bool))', Expr::get('a', Type::bool())],
            [
                '(a:int - b:int) - c:int',
                Expr::subtract(
                    Expr::subtract(Expr::get('a', Type::int()), Expr::get('b', Type::int())),
                    Expr::get('c', Type::int()),
                ),
            ],
            [
                '(a:bool && b:bool) || c:bool',
                Expr::or_(
                    Expr::and_(Expr::get('a', Type::bool()), Expr::get('b', Type::bool())),
                    Expr::get('c', Type::bool()),
                ),
            ],
            // The grouping the issue asks for: left-associative subtraction already means (a - 1) - 2, so the
            // parentheses are redundant, but they now parse instead of being a syntax error.
            [
                '(a:int - 1) - 2',
                Expr::subtract(
                    Expr::subtract(Expr::get('a', Type::int()), Expr::literal(1)),
                    Expr::literal(2),
                ),
            ],
            [
                '(a:int + b:int) + c:int',
                Expr::add(
                    Expr::add(Expr::get('a', Type::int()), Expr::get('b', Type::int())),
                    Expr::get('c', Type::int()),
                ),
            ],
            [
                '(a:int * b:int) * c:int',
                Expr::multiply(
                    Expr::multiply(Expr::get('a', Type::int()), Expr::get('b', Type::int())),
                    Expr::get('c', Type::int()),
                ),
            ],
            // A generic's closing angle glued to `===` or `!==`: the `>` must stay a bare close-angle instead of
            // munching a `>=` that would orphan a `==`, which is no token at all.
            [
                'foo:list<int>===bar:list<int>',
                Expr::eq(Expr::get('foo', Type::listOf(Type::int())), Expr::get('bar', Type::listOf(Type::int()))),
            ],
            [
                'foo:list<int>!==bar:list<int>',
                Expr::get('foo', Type::listOf(Type::int()))->neq(Expr::get('bar', Type::listOf(Type::int()))),
            ],
            // ...but one `=` after the angle is still `>=`/`<=`.
            ['a:int>=1', Expr::get('a', Type::int())->gte(Expr::literal(1))],
            ['a:int<=1', Expr::get('a', Type::int())->lte(Expr::literal(1))],
        ];
        foreach ($cases as $case) {
            yield $case[0] => $case;
        }
    }

    /**
     * @return iterable<string, array{string, array<string, Type>}>
     */
    public static function aliasRoundTripCases(): iterable
    {
        yield 'alias of a list' => ['foo:Bag', ['Bag' => Type::listOf(Type::string())]];
        yield 'alias of a map' => ['foo:Lookup', ['Lookup' => Type::mapOf(Type::string(), Type::int())]];
        yield 'alias of an option' => ['foo:Maybe', ['Maybe' => Type::option(Type::int())]];
        yield 'alias of a scalar' => ['foo:Count', ['Count' => Type::int()]];
        yield 'alias of a struct' => ['foo:Person', ['Person' => Type::struct(['name' => Type::string()])]];
        yield 'alias inside a list' => ['foo:list<Bag>', ['Bag' => Type::listOf(Type::string())]];
        // An alias is none of the constructors that are written with type arguments, so a `<` after one can't be
        // opening an argument list and has to read as less-than. `foo:int < bar:int` in parseCases() pins the same
        // decision for a name the parser does know; this is the branch for every name it doesn't.
        yield 'alias before a less-than' => ['foo:Count < bar:Count', ['Count' => Type::int()]];
    }

    #[DataProvider('parseCases')]
    #[DataProvider('nonCanonicalParseCases')]
    public function testParse(string $str, Expression $expected): void
    {
        $actual = ExpressionParser::parse($str);

        self::assertTrue($actual->equals($expected), sprintf(
            "Expected:\n%s\nActual:\n%s",
            $expected,
            $actual,
        ));
    }

    #[DataProvider('parseCases')]
    public function testToString(string $expected, Expression $expr): void
    {
        self::assertSame($expected, (string)$expr);
    }

    /**
     * Printing an expression has to spell a type the parser reads back. An alias of a parameterized type is the case
     * that gets this wrong most easily: the type it stands for has arguments, the alias itself takes none, and printing
     * the former under the latter's name produces `Bag<string>`, which no longer parses.
     *
     * @param array<string, Type> $aliases
     */
    #[DataProvider('aliasRoundTripCases')]
    public function testAliasedTypesRoundTrip(string $expression, array $aliases): void
    {
        $declarations = new Declarations(types: new Types($aliases));
        $expr = ExpressionParser::parse($expression, $declarations);

        $reparsed = ExpressionParser::parse((string)$expr, $declarations);

        self::assertSame($expression, (string)$expr);
        self::assertTrue($expr->equals($reparsed), sprintf('%s does not equal %s', $expr, $reparsed));
    }

    public function testParseTypedReturnsTheParsedExpressionIfItMatchesTheGivenType(): void
    {
        $actual = ExpressionParser::parseTyped('foo:string', Type::string());

        self::assertSame('foo:string', (string)$actual);
    }
}
