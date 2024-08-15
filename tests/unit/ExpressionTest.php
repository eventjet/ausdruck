<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit;

use Eventjet\Ausdruck\EvaluationError;
use Eventjet\Ausdruck\Expr;
use Eventjet\Ausdruck\Expression;
use Eventjet\Ausdruck\Literal;
use Eventjet\Ausdruck\Parser\Declarations;
use Eventjet\Ausdruck\Parser\ExpressionParser;
use Eventjet\Ausdruck\Parser\Span;
use Eventjet\Ausdruck\Parser\Types;
use Eventjet\Ausdruck\Scope;
use Eventjet\Ausdruck\Type;
use PHPUnit\Framework\TestCase;

use function is_array;
use function is_callable;
use function is_string;
use function md5;
use function sprintf;

final class ExpressionTest extends TestCase
{
    /**
     * @return iterable<string, array{
     *     string | Expression | callable(): Expression,
     *     Scope,
     *     Declarations | null,
     *     mixed,
     * }>
     */
    public static function evaluateCases(): iterable
    {
        $s = Type::string();
        $user = new class {
            public string $name = 'John';
        };
        $cases = [
            [static fn(): Expression => Expr::get('foo', $s), new Scope(['foo' => 'bar']), 'bar'],
            [
                static fn(): Expression => Expr::get('foo', $s)->eq(Expr::get('bar', Type::string())),
                new Scope(['foo' => 'yes', 'bar' => 'yes']),
                true,
            ],
            [Expr::get('foo', $s)->eq(Expr::literal('bar')), new Scope(['foo' => 'bar']), true],
            [Expr::get('foo', $s)->eq(Expr::literal('bar')), new Scope(['foo' => 'nope']), false],
            [Expr::get('foo', $s), (new Scope(['foo' => 'bar']))->sub(['nothere' => 'nope']), 'bar'],
            [
                static fn(): Expression => Expr::get('test', $s)->eq(Expr::literal('foo'))
                    ->or_(Expr::get('test', $s)->eq(Expr::literal('bar'))),
                new Scope(['test' => 'bar']),
                true,
            ],
            [
                static fn(): Expression => Expr::get('test', $s)->eq(Expr::literal('bar'))
                    ->or_(Expr::get('test', $s)->eq(Expr::literal('foo'))),
                new Scope(['test' => 'bar']),
                true,
            ],
            [
                static fn(): Expression => Expr::get('test', $s)->eq(Expr::literal('bar'))
                    ->or_(Expr::get('test', $s)->eq(Expr::literal('foo'))),
                new Scope(['test' => 'baz']),
                false,
            ],
            [
                Expr::get('items', Type::listOf(Type::string()))
                    ->call('contains', Type::bool(), [Expr::get('needle', Type::string())]),
                new Scope(['items' => ['foo', 'bar', 'baz'], 'needle' => 'bar']),
                true,
            ],
            [
                Expr::get('items', Type::listOf(Type::string()))
                    ->call('contains', Type::bool(), [Expr::get('needle', Type::string())]),
                new Scope(['items' => ['foo', 'baz'], 'needle' => 'bar']),
                false,
            ],
            [
                static fn(): Expression => Expr::get('items', Type::listOf(Type::any()))
                    ->call('contains', Type::bool(), [Expr::get('needle', Type::string())]),
                new Scope(['items' => ['foo', 'bar', 'baz'], 'needle' => 'bar']),
                true,
            ],
            [
                static fn(): Expression => Expr::get('items', Type::listOf(Type::float()))
                    ->call('contains', Type::bool(), [Expr::get('needle', Type::float())]),
                new Scope(['items' => [1.5, 3.0, 4.5], 'needle' => 3.0]),
                true,
            ],
            (static function (): array {
                $a = ['foo'];
                $b = ['bar'];
                $c = ['baz'];
                return [
                    'items:list<list<string>>.contains:bool(needle:list<string>)',
                    new Scope(['items' => [$a, $b, $c], 'needle' => $b]),
                    true,
                ];
            })(),
            [
                Expr::get('foo', Type::int())->subtract(Expr::get('bar', Type::int())),
                new Scope(['foo' => 5, 'bar' => 2]),
                3,
            ],
            ['nums:list<int>.some:bool(|item| item:int === 5)', new Scope(['nums' => [4, 6]]), false],
            ['nums:list<int>.some:bool(|item| item:int === 5)', new Scope(['nums' => [4, 5, 6]]), true],
            ['"Rudolph".substr:string(1, 3)', new Scope(), 'udo'],
            ['a:int - b:int - c:int', new Scope(['a' => 10, 'b' => 3, 'c' => 4]), 3],
            ['"foo" === "bar"', new Scope(), false],
            ['"foo" === "foo"', new Scope(), true],
            ['foo:any === bar:any', new Scope(['foo' => 12.34, 'bar' => 12.34]), true],
            ['foo:any === bar:any', new Scope(['foo' => 1, 'bar' => '1']), false],
            ['foo:int > bar:int', new Scope(['foo' => 69, 'bar' => 69]), false],
            [
                'items:map<string, int>.filter:map<string, int>(|item| item:int > 23)',
                new Scope(['items' => ['c' => 69, 'a' => 23, 'b' => 24, 'd' => -23]]),
                ['c' => 69, 'b' => 24],
            ],
            [
                'items:list<int>.filter:list<int>(|item| item:int > 23)',
                new Scope(['items' => [69, 23, 24, -23]]),
                [69, 24],
            ],
            ['myfloat:float === 23.42', new Scope(['myfloat' => 23.42]), true],
            [
                'obj:list<string>.matches:bool()',
                new Scope(['obj' => ['foo']], ['matches' => static fn(mixed $value): bool => $value === ['foo']]),
                true,
                new Declarations(types: new Types(['MyCustomObject' => Type::listOf(Type::string())])),
            ],
            ['myMap:map<int, string>', new Scope(['myMap' => [42 => 'a', 69 => 'b']]), [42 => 'a', 69 => 'b']],
            [
                'items:list<string>.take:list<string>(3)',
                new Scope(['items' => ['a', 'b', 'c', 'd', 'e']]),
                ['a', 'b', 'c'],
            ],
            ['items:list<string>.take:list<string>(3)', new Scope(['items' => ['a', 'b']]), ['a', 'b']],
            ['items:list<string>.take:list<string>(3)', new Scope(['items' => []]), []],
            ['items:list<string>.take:list<string>(0)', new Scope(['items' => ['a', 'b', 'c', 'd', 'e']]), []],
            ['-myval:int', new Scope(['myval' => 42]), -42],
            [
                'names:list<string>.map:list<bool>(|name| name:string === "bar")',
                new Scope(['names' => ['foo', 'bar', 'baz']]),
                [false, true, false],
            ],
            ['ints:list<int>.map:list<int>(|i| i:int -2)', new Scope(['ints' => []]), []],
            ['maybe:Option<string>', new Scope(['maybe' => 'foo']), 'foo'],
            ['maybe:Option<string>', new Scope(['maybe' => null]), null],
            ['maybe:Option<int>.isSome:bool()', new Scope(['maybe' => 23]), true],
            ['maybe:Option<int>.isSome:bool()', new Scope(['maybe' => null]), false],
            ['ints:list<int>.unique:list<int>()', new Scope(['ints' => [23, 10, 23, 42, 420, 420]]), [23, 10, 42, 420]],
            ['ints:list<int>.unique:list<int>()', new Scope(['ints' => []]), []],
            ['foo', new Scope(['foo' => 'test']), 'test', new Declarations(variables: ['foo' => Type::string()])],
            ['foo:string.substr(0, 3)', new Scope(['foo' => 'test']), 'tes'],
            [
                'foo.customHash("test")',
                new Scope(['foo' => 'mystr'], ['customHash' => static fn(string $text, string $salt): string => md5($text . $salt)]),
                md5('mystrtest'),
                new Declarations(
                    variables: ['foo' => Type::string()],
                    functions: ['customHash' => Type::func(Type::string(), [Type::string()])],
                ),
            ],
            (static function () {
                $itemType = Type::listOf(Type::string());
                $bagType = Type::listOf(Type::listOf($itemType));
                /**
                * @param array{list<array{string}>} $bag
                * @return list<array{string}>
                * @psalm-suppress MixedReturnStatement
                * @psalm-suppress MixedInferredReturnType
                */
                $getItems = static fn(array $bag): array => $bag[0];
                /**
                * @param array{string} $item
                * @psalm-suppress MixedReturnStatement
                * @psalm-suppress MixedInferredReturnType
                */
                $getName = static fn(array $item): string => $item[0];
                return
                    [
                        'bag.items().map:list<string>(|i| i:Item.name())',
                        new Scope(['bag' => [[['a'], ['b']]]], ['items' => $getItems, 'name' => $getName]),
                        ['a', 'b'],
                        new Declarations(
                            types: new Types(['Bag' => $bagType, 'Item' => $itemType]),
                            variables: ['bag' => $bagType],
                            functions: [
                                'items' => Type::func(Type::listOf($itemType), [$bagType]),
                                'name' => Type::func(Type::string(), [$itemType]),
                            ],
                        ),
                    ];
            })(),
            ['["foo", "bar"]', new Scope(), ['foo', 'bar']],
            ['["foo", myVar:string, "bar"].contains("test")', new Scope(['myVar' => 'test']), true],
            ['["foo",]', new Scope(), ['foo']],
            ['foo:bool && bar:bool', new Scope(['foo' => true, 'bar' => true]), true],
            ['foo:bool && bar:bool', new Scope(['foo' => true, 'bar' => false]), false],
            ['foo:bool && bar:bool', new Scope(['foo' => false, 'bar' => true]), false],
            ['foo:bool && bar:bool', new Scope(['foo' => false, 'bar' => false]), false],
            ['ints1:list<int>.head:Option<int>().unwrap:int()', new Scope(['ints1' => [42, 69, 23]]), 42],
            ['ints2:list<int>.head:Option<int>().unwrap:int()', new Scope(['ints2' => [42]]), 42],
            ['ints:list<int>.head:Option<int>().isSome()', new Scope(['ints' => []]), false],
            ['ints1:list<int>.tail:list<int>()', new Scope(['ints1' => [42, 69, 23]]), [69, 23]],
            ['ints2:list<int>.tail:list<int>()', new Scope(['ints2' => [42]]), []],
            ['ints3:list<int>.tail:list<int>()', new Scope(['ints3' => []]), []],
            ['foo:float - bar:float', new Scope(['foo' => 5.5, 'bar' => 3.4]), 2.1],
            ['user:{ name: string }.name', new Scope(['user' => $user]), 'John'],
            [
                'user:{ name: string }.name()',
                new Scope(['user' => $user], ['name' => static fn() => 'from function']),
                'from function',
                new Declarations(functions: ['name' => Type::func(Type::string(), [Type::any()])]),
            ],
            ['user:{ name: string }.name.substr(1, 2)', new Scope(['user' => $user]), 'oh'],
            ['abcdefghijklmnopqrstuvwxyz:bool', new Scope(['abcdefghijklmnopqrstuvwxyz' => false]), false],
            ['ABCDEFGHIJKLMNOPQRSTUVWXYZ:bool', new Scope(['ABCDEFGHIJKLMNOPQRSTUVWXYZ' => false]), false],
            ['x0123456789:bool', new Scope(['x0123456789' => false]), false],
        ];
        foreach ($cases as $tuple) {
            [$expr, $scope, $expected] = $tuple;
            $declarations = $tuple[3] ?? null;
            $expectedStr = (string)Expr::literal($expected);
            $exprStr = is_callable($expr) ? $expr() : $expr;
            $name = sprintf('%s equals %s with %s', (string)$exprStr, $expectedStr, $scope->debug());
            yield $name => [$expr, $scope, $declarations, $expected];
        }
    }

    /**
     * @return iterable<string, array{0: Expression | string, 1: string, 2?: Declarations}>
     */
    public static function toStringCases(): iterable
    {
        yield Literal::class . ': string keys' => [Expr::literal(['foo' => 'bar']), '{"foo": "bar"}'];
        yield Literal::class . ': string array' => [Expr::literal(['foo']), '["foo"]'];
        yield Literal::class . ': int' => [Expr::literal(69), '69'];
        yield Literal::class . ': true' => [Expr::literal(true), 'true'];
        yield Literal::class . ': false' => [Expr::literal(false), 'false'];
        yield Literal::class . ': null' => [Expr::literal(null), 'null'];
        yield 'Option type' => ['myint:Option<int>', 'myint:Option<int>'];
        yield 'Variable access with implicit type' => [
            'foo',
            'foo',
            new Declarations(variables: ['foo' => Type::string()]),
        ];
        yield 'Any type' => ['myval:any', 'myval:any'];
        yield 'List literal' => ['["foo", "bar"]', '["foo", "bar"]'];
    }

    /**
     * @return iterable<string, array{0: Expression | array{0: string, 1?: Declarations}, 1: Scope, 2?: string}>
     */
    public static function evaluationErrorsCases(): iterable
    {
        yield 'Getting a variable that\'s not in scope' => [
            Expr::get('foo', Type::string()),
            new Scope(),
            'Unknown variable',
        ];
        yield 'Get string variable, but it\'s not a string' => [
            Expr::get('foo', Type::string()),
            new Scope(['foo' => 69]),
            'Expected string, got int',
        ];
        yield 'Get float variable, but it\'s not a float' => [
            Expr::get('foo', Type::float()),
            new Scope(['foo' => 'bar']),
            'Expected float, got string',
        ];
        yield 'Expect map, but it\'s not an array' => [
            Expr::get('item', Type::mapOf(Type::string(), Type::string())),
            new Scope(['item' => 'not an array']),
        ];
        yield 'Expect list, but it\'s not an array' => [
            Expr::get('item', Type::listOf(Type::string())),
            new Scope(['item' => 'not an array']),
            'Expected list<string>, got string',
        ];
        yield 'Expect list, but it\'s a map' => [
            Expr::get('item', Type::listOf(Type::string())),
            new Scope(['item' => ['foo' => 'bar']]),
            'Expected list<string>, got map<string, string>',
        ];
        yield 'Wrong item type in list' => [
            Expr::get('item', Type::listOf(Type::string())),
            new Scope(['item' => [42]]),
            'Expected variable "item" to be of type list<string>, got array',
        ];
        yield 'Expect int, but it\'s a string' => [
            Expr::get('item', Type::int()),
            new Scope(['item' => 'not an int']),
            'Expected int, got string',
        ];
        yield 'Expect bool, but it\'s a string' => [
            Expr::get('item', Type::bool()),
            new Scope(['item' => 'not a bool']),
            'Expected bool, got string',
        ];
        yield 'Calling an unknown function' => [
            Expr::literal('foo')->call('unknown', Type::string(), []),
            new Scope(['item' => 'foo']),
            'Unknown function',
        ];
        yield 'Declared function return type is different from actual return type' => [
            ['foo:string.chars()', new Declarations(functions: ['chars' => Type::func(Type::int(), [Type::string()])])],
            new Scope(['foo' => 'test'], ['chars' => static fn(string $text): string => $text]),
            'Expected int, got string',
        ];
        yield 'Subtracting a float from an integer' => [
            Expr::subtract(Expr::get('myint', Type::int()), Expr::get('myfloat', Type::float())),
            new Scope(['myint' => 42, 'myfloat' => 23.42]),
            'Expected operands to be of the same type, got int and float',
        ];
        yield 'Subtracting an integer from a float' => [
            Expr::subtract(Expr::get('myfloat', Type::float()), Expr::get('myint', Type::int())),
            new Scope(['myint' => 42, 'myfloat' => 23.42]),
            'Expected operands to be of the same type, got float and int',
        ];
        yield 'Subtracting a string from a string' => [
            Expr::subtract(Expr::get('mystr', Type::string()), Expr::get('mystr2', Type::string())),
            new Scope(['mystr' => 'foo', 'mystr2' => 'bar']),
            'Expected operands to be of type int or float, got string and string',
        ];
        yield 'Logical or with string on the left' => [
            Expr::get('foo', Type::string())->or_(Expr::get('bar', Type::bool())),
            new Scope(['foo' => 'foo', 'bar' => true]),
            'Expected boolean operands, got string and bool',
        ];
        yield 'Logical or with string on the right' => [
            Expr::get('foo', Type::bool())->or_(Expr::get('bar', Type::string())),
            new Scope(['foo' => true, 'bar' => 'bar']),
            'Expected boolean operands, got bool and string',
        ];
        yield 'Logical and with string on the left' => [
            Expr::get('foo', Type::string())->and_(Expr::get('bar', Type::bool())),
            new Scope(['foo' => 'foo', 'bar' => true]),
            'Expected boolean operands, got string and bool',
        ];
        yield 'Logical and with string on the right' => [
            Expr::get('foo', Type::bool())->and_(Expr::get('bar', Type::string())),
            new Scope(['foo' => true, 'bar' => 'bar']),
            'Expected boolean operands, got bool and string',
        ];
        yield 'Negative bool' => [
            Expr::negative(Expr::get('foo', Type::bool())),
            new Scope(['foo' => true]),
            'Expected operand to be of type int or float',
        ];
        yield 'Access non-existent struct field' => [
            Expr::fieldAccess(Expr::get('user', Type::struct(['name' => Type::string()])), 'age', self::span()),
            new Scope(['user' => (object)['name' => 'John']]),
            'Unknown field "age"',
        ];
        yield 'Access field non non-struct' => [
            Expr::fieldAccess(Expr::get('user', Type::string()), 'name', self::span()),
            new Scope(['user' => 'John']),
            'Expected object, got string',
        ];
    }

    /**
     * @return iterable<string, array{Expression | string, Type}>
     */
    public static function typeCases(): iterable
    {
        yield 'Eq' => [
            Expr::eq(Expr::get('foo', Type::bool()), Expr::get('foo', Type::bool())),
            Type::bool(),
        ];
        yield 'Get string' => [
            Expr::get('foo', Type::string()),
            Type::string(),
        ];
        yield 'Get int' => [
            Expr::get('foo', Type::int()),
            Type::int(),
        ];
        yield 'Get bool' => [
            Expr::get('foo', Type::bool()),
            Type::bool(),
        ];
        yield 'String literal' => [
            Expr::literal('foo'),
            Type::string(),
        ];
        yield 'Int literal' => [
            Expr::literal(42),
            Type::int(),
        ];
        yield 'True literal' => [
            Expr::literal(true),
            Type::bool(),
        ];
        yield 'False literal' => [
            Expr::literal(false),
            Type::bool(),
        ];
        yield 'String list literal' => [
            Expr::literal(['foo', 'bar']),
            Type::listOf(Type::string()),
        ];
        yield 'Int list literal' => [
            Expr::literal([1, 2]),
            Type::listOf(Type::int()),
        ];
        yield 'String string map literal' => [
            Expr::literal(['foo' => 'bar']),
            Type::mapOf(Type::string(), Type::string()),
        ];
        yield 'Int int map literal' => [
            Expr::literal([1 => 2]),
            Type::mapOf(Type::int(), Type::int()),
        ];
        yield 'Or' => [
            Expr::or_(Expr::get('foo', Type::bool()), Expr::get('bar', Type::bool())),
            Type::bool(),
        ];
        yield 'Some' => [
            Expr::get('foo', Type::listOf(Type::string()))->call('some', Type::bool(), [
                Expr::lambda(Expr::get('item', Type::string())->eq(Expr::get('needle', Type::string())), ['item']),
            ]),
            Type::bool(),
        ];
        yield 'List literal with strings' => ['["foo", myVar:string]', Type::listOf(Type::string())];
        yield 'List literal with strings and ints' => ['["foo", "bar", 42, myVar:string]', Type::listOf(Type::any())];
        yield 'Empty list literal' => ['[]', Type::listOf(Type::any())];
    }

    private static function span(): Span
    {
        return Span::char(1, 1);
    }

    /**
     * @param Expression | string | callable(): Expression $expression
     * @dataProvider evaluateCases
     */
    public function testEvaluate(Expression|string|callable $expression, Scope $scope, Declarations|null $declarations, mixed $expected): void
    {
        if (is_callable($expression)) {
            $expression = $expression();
        } elseif (is_string($expression)) {
            $expression = ExpressionParser::parse($expression, $declarations);
        }

        self::assertSame($expected, $expression->evaluate($scope));
    }

    /**
     * @dataProvider toStringCases
     */
    public function testToString(Expression|string $expr, string $expected, Declarations|null $declarations = null): void
    {
        if (is_string($expr)) {
            $expr = ExpressionParser::parse($expr, $declarations);
        }

        self::assertSame($expected, (string)$expr);
    }

    /**
     * @param Expression | array{0: string, 1?: Declarations} $expression
     * @dataProvider evaluationErrorsCases
     */
    public function testEvaluationErrors(Expression|array $expression, Scope $scope, string|null $message = null): void
    {
        if (is_array($expression)) {
            $declarations = $expression[1] ?? null;
            $expression = ExpressionParser::parse($expression[0], $declarations);
        }
        $this->expectException(EvaluationError::class);
        if ($message !== null) {
            $this->expectExceptionMessage($message);
        }

        $expression->evaluate($scope);
    }

    /**
     * @dataProvider typeCases
     */
    public function testType(Expression|string $expression, Type $expected): void
    {
        if (is_string($expression)) {
            $expression = ExpressionParser::parse($expression);
        }

        self::assertTrue(
            $expression->matchesType($expected),
            sprintf('Expected %s, got %s', $expected, $expression->getType()),
        );
    }
}
