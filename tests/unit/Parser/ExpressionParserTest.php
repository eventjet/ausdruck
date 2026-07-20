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
use Eventjet\Ausdruck\Parser\Types;
use Eventjet\Ausdruck\Type;
use PHPUnit\Framework\Attributes\DataProvider;
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
            // Unary binds tighter than additive, so this subtracts a negation rather than negating a subtraction.
            [
                'a:int - -b:int',
                Expr::subtract(Expr::get('a', $i), Expr::negative(Expr::get('b', $i))),
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
                '-(a:int - b:int)',
                Expr::negative(Expr::subtract(Expr::get('a', $i), Expr::get('b', $i))),
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
            // A method can be called on a grouped expression, so the postfix dot has to parenthesize a target looser
            // than a call's own — everything from a subtraction down to a negation.
            [
                '(a:int - b:int).abs:int()',
                Expr::subtract(Expr::get('a', $i), Expr::get('b', $i))->call('abs', $i, []),
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
     * @return iterable<string, array{0: string, 1?: string}>
     */
    public static function invalidSyntaxExpressions(): iterable
    {
        yield 'string: missing closing quote' => ['"foo'];
        yield 'single pipe' => ['foo:bool | bar:bool'];
        // Equality is `===`; a `=` or `==` is blamed as the beginning of one, with the missing rest named.
        yield 'single equals' => ['foo:string = bar:string', 'Expected ===, got ='];
        yield 'double equals' => ['foo:string == bar:string', 'Expected ===, got =='];
        yield 'double length fat arrow' => ['foo:string ==> bar:string', 'Expected ===, got =='];
        yield 'end after single pipe' => ['foo:bool |'];
        yield 'end after single equals' => ['foo:bool =', 'Expected ===, got ='];
        yield 'end after double equals' => ['foo:bool =='];
        yield 'close brace after triple equals' => ['foo:bool === )'];
        yield 'lambda: missing closing brace' => ['(foo, bar => foo:string'];
        yield 'lambda: open brace instead of closing' => ['(foo, bar( => foo:string'];
        yield 'standalone open brace' => ['('];
        yield 'dot type' => ['foo:list<.>'];
        // A generic constructor's `<` opens an argument list that has to close, whichever constructor it is: an
        // unclosed one is an error, not a rewind to a less-than that was never there.
        yield 'end of string after generic open angle' => ['foo:map<'];
        yield 'end of string after option open angle' => ['foo:Option<'];
        yield 'end of string after some open angle' => ['foo:Some<'];
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
        yield 'missing left hand side of <' => ['< foo:int'];
        yield 'missing right hand side of <' => ['foo:int <'];
        yield 'missing right hand side of >=' => ['foo:int >='];
        yield 'missing right hand side of <=' => ['foo:int <='];
        yield 'missing right hand side of !==' => ['foo:int !=='];
        // Equality is `===`, so inequality is `!==`; a bare `!` or `!=` is read as the beginning of one, and the
        // error says how far it got.
        yield 'not equals with one equals' => ['a:int != 1', 'Expected !==, got !='];
        yield 'lone bang' => ['a:int ! 1', 'Expected !==, got !'];
        yield 'bang at end of input' => ['a:int !', 'Expected !==, got !'];
        // Two `=` after a `>` keep the angle bare, whatever comes next (see the `foo:list<int>===bar` parse case), so
        // the `==` here is blamed as its own broken `===` rather than a `>=` eating its first `=`.
        yield 'greater-equals with an extra equals' => ['a:int >== 1', 'Expected ===, got =='];
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
        yield 'single ampersand' => ['foo:bool & bar:bool', 'Expected &&, got &'];
        yield 'non-token, non-identifier symbol' => ['foo:bool € bar:bool'];
        yield 'identifier starting with a number' => ['42foo:bool', 'Unexpected identifier foo'];
        yield 'identifier starting with an underscore' => ['_foo:bool', 'Unexpected character _'];
        // The comparison operators are non-associative, so a chain of any of them is a syntax error rather than a
        // confusing type error against the bool the first comparison produces.
        yield 'chained ===' => ['a:int === b:int === c:int', 'Unexpected ==='];
        yield 'chained !==' => ['a:int !== b:int !== c:int', 'Unexpected !=='];
        yield 'chained >' => ['a:int > b:int > c:int', 'Unexpected >'];
        yield 'chained <' => ['a:int < b:int < c:int', 'Unexpected <'];
        yield 'chained >=' => ['a:int >= b:int >= c:int', 'Unexpected >='];
        yield 'chained <=' => ['a:int <= b:int <= c:int', 'Unexpected <='];
        // Whatever follows a complete expression is a mistake, not something to drop: silently returning the expression
        // parsed so far turns a typo or an operator we don't have into a valid expression with a surprising value.
        yield 'trailing literal' => ['1 2', 'Unexpected 2'];
        yield 'trailing literal after a complete subtraction' => ['1 - 1 999', 'Unexpected 999'];
        yield 'trailing string literal' => ['"a" "b"', 'Unexpected "b"'];
        yield 'trailing keyword' => ['true false', 'Unexpected identifier false'];
        yield 'trailing operator' => ['a:int -', 'Expected expression, got end of input'];
        // The leftmost mistake is the one to fix first, so it's the one to report—whatever garbage follows it. Reading
        // the token that trailing junk starts with must not scan the characters after that token, or the tokenizer
        // would throw over text further right before the parser ever gets to blame the junk it already has.
        yield 'trailing literal before an unterminated string' => ['1 2 "unterminated', 'Unexpected 2'];
        yield 'trailing literal before a non-token symbol' => ['1 2 €', 'Unexpected 2'];
        yield 'trailing keyword before a non-token symbol' => ['true false €', 'Unexpected identifier false'];
        yield 'trailing variable before a non-token symbol' => ['a:int b €', 'Unexpected identifier b'];
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
        yield 'int > float' => ['foo:int > bar:float', 'Can\'t compare int to float'];
        yield 'float > int' => ['foo:float > bar:int', 'Can\'t compare float to int'];
        yield 'string > string' => ['foo:string > bar:string', 'Can\'t compare string to string'];
        yield 'string > int' => ['foo:string > bar:int', 'Can\'t compare string to int'];
        yield 'int > string' => ['foo:int > bar:string', 'Can\'t compare string to int'];
        // The ordering operators share one comparability rule and one wording, so a mistake reads the same whichever
        // one it's made with.
        yield 'int < float' => ['foo:int < bar:float', 'Can\'t compare int to float'];
        yield 'string <= string' => ['foo:string <= bar:string', 'Can\'t compare string to string'];
        yield 'float >= int' => ['foo:float >= bar:int', 'Can\'t compare float to int'];
        yield 'not equals: different operand types' => [
            'foo:string !== bar:int',
            'The expressions of both sides of !== must be of the same type. Left: string, right: int',
        ];
        yield 'generic syntax on string' => ['foo:string<int>'];
        // An alias is a name for one complete type: like the argument-less built-ins, it rejects type arguments
        // instead of silently dropping them.
        yield 'generic syntax on an alias' => [
            'foo:Foo<int>',
            'Invalid type "Foo<int>": Foo does not accept arguments',
            new Declarations(types: new Types(['Foo' => Type::int()])),
        ];
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
            // A `!` or `=` that isn't the start of `!==`/`===` is blamed with everything read after it: the operator
            // that is actually there is underlined whole.
            [
                'a:int != 1',
                '      ==  ',
            ],
            [
                'a:int ! 1',
                '      =  ',
            ],
            [
                'foo:string == bar:string',
                '           ==           ',
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
            // Reading `int <` as the start of a type argument list fails on the `list<int` that follows, but that
            // failure is not the error: it only means the `<` was a less-than after all. So the attempt is rewound and
            // the blame lands on the undeclared variable that really is there, not on the `>` the abandoned reading
            // wanted.
            [
                'a:int < list<int',
                '        ====    ',
            ],
            // The `>` of an arrow is a column like any other: what follows it is blamed where it actually is.
            [
                'x:fn(int) -> int &',
                '                 =',
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
                '"foo" !== 42',
                '          ==',
            ],
            // The right-hand operand of these lands past a two-character operator, so its column also pins that the
            // tokenizer counts `<=` and `>=` as two columns, not one.
            [
                'foo:int <= bar:string',
                '           ==========',
            ],
            [
                'foo:int >= bar:string',
                '           ==========',
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

    #[DataProvider('typeErrorLocationCases')]
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

    #[DataProvider('syntaxErrorLocationCases')]
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
