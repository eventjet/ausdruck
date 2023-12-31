<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit;

use Eventjet\Ausdruck\EvaluationError;
use Eventjet\Ausdruck\Expr;
use Eventjet\Ausdruck\Expression;
use Eventjet\Ausdruck\Literal;
use Eventjet\Ausdruck\Parser\ExpressionParser;
use Eventjet\Ausdruck\Parser\Types;
use Eventjet\Ausdruck\Scope;
use Eventjet\Ausdruck\Test\Unit\Fixtures\SomeObject;
use Eventjet\Ausdruck\Type;
use PHPUnit\Framework\TestCase;
use stdClass;

use function is_callable;
use function is_string;
use function sprintf;

final class ExpressionTest extends TestCase
{
    /**
     * @return iterable<string, array{
     *     string | Expression<mixed> | callable(): Expression<mixed>,
     *     Scope,
     *     mixed,
     * }>
     */
    public static function evaluateCases(): iterable
    {
        $s = Type::string();
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
                $a = new stdClass();
                $b = new stdClass();
                $c = new stdClass();
                return [
                    static fn(): Expression => Expr::get('items', Type::listOf(Type::object(stdClass::class)))
                        ->call('contains', Type::bool(), [Expr::get('needle', Type::object(stdClass::class))]),
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
                'obj:MyCustomObject.matches:bool()',
                new Scope(['obj' => new SomeObject()], ['matches' => static fn(mixed $value): bool => $value instanceof SomeObject]),
                true,
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
        ];
        foreach ($cases as [$expr, $scope, $expected]) {
            $expectedStr = (string)Expr::literal($expected);
            $exprStr = is_callable($expr) ? $expr() : $expr;
            $name = sprintf('%s equals %s with %s', (string)$exprStr, $expectedStr, $scope->debug());
            yield $name => [$expr, $scope, $expected];
        }
    }

    /**
     * @return iterable<string, array{Expression<mixed>, string}>
     */
    public static function toStringCases(): iterable
    {
        yield Literal::class . ': string keys' => [Expr::literal(['foo' => 'bar']), '{"foo": "bar"}'];
        yield Literal::class . ': string array' => [Expr::literal(['foo']), '["foo"]'];
        yield Literal::class . ': int' => [Expr::literal(69), '69'];
        yield Literal::class . ': true' => [Expr::literal(true), 'true'];
        yield Literal::class . ': false' => [Expr::literal(false), 'false'];
        yield Literal::class . ': null' => [Expr::literal(null), 'null'];
    }

    /**
     * @return iterable<string, array{0: Expression<mixed>, 1: Scope, 2?: string}>
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
        yield 'Get object variable, but it\'s not an object' => [
            Expr::get('foo', stdClass::class),
            new Scope(['foo' => 'bar']),
        ];
        yield 'Get object variable, but it\'s not the right class' => [
            Expr::get('foo', stdClass::class),
            new Scope(['foo' => new SomeObject()]),
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
            'Expected array',
        ];
        yield 'Expect list, but it\'s a map' => [
            Expr::get('item', Type::listOf(Type::string())),
            new Scope(['item' => ['foo' => 'bar']]),
            'not a list',
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
    }

    /**
     * @return iterable<string, array{Expression<mixed>, Type<mixed>}>
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
        yield 'Get object' => [
            Expr::get('foo', Type::object(SomeObject::class)),
            Type::object(SomeObject::class),
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
    }

    /**
     * @param Expression<mixed> | string | callable(): Expression<mixed> $expression
     * @dataProvider evaluateCases
     */
    public function testEvaluate(Expression|string|callable $expression, Scope $scope, mixed $expected): void
    {
        if (is_callable($expression)) {
            $expression = $expression();
        } elseif (is_string($expression)) {
            $types = new Types(['MyCustomObject' => Type::object(SomeObject::class)]);
            $expression = ExpressionParser::parse($expression, $types);
        }

        self::assertSame($expected, $expression->evaluate($scope));
    }

    /**
     * @param Expression<mixed> $expr
     * @dataProvider toStringCases
     */
    public function testToString(Expression $expr, string $expected): void
    {
        self::assertSame($expected, (string)$expr);
    }

    /**
     * @param Expression<mixed> $expression
     * @dataProvider evaluationErrorsCases
     */
    public function testEvaluationErrors(Expression $expression, Scope $scope, string|null $message = null): void
    {
        $this->expectException(EvaluationError::class);
        if ($message !== null) {
            $this->expectExceptionMessage($message);
        }

        $expression->evaluate($scope);
    }

    /**
     * @param Expression<mixed> $expression
     * @param Type<mixed> $expected
     * @dataProvider typeCases
     */
    public function testType(Expression $expression, Type $expected): void
    {
        /**
         * @psalm-suppress ImplicitToStringCast
         * @psalm-suppress RedundantCondition I have no idea how to type this better
         */
        self::assertTrue(
            $expression->matchesType($expected),
            sprintf('Expected %s, got %s', $expected, $expression->getType()),
        );
    }
}
