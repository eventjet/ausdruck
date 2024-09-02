<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit;

use Eventjet\Ausdruck\And_;
use Eventjet\Ausdruck\Call;
use Eventjet\Ausdruck\Eq;
use Eventjet\Ausdruck\Expr;
use Eventjet\Ausdruck\Expression;
use Eventjet\Ausdruck\FieldAccess;
use Eventjet\Ausdruck\Get;
use Eventjet\Ausdruck\Gt;
use Eventjet\Ausdruck\Lambda;
use Eventjet\Ausdruck\ListLiteral;
use Eventjet\Ausdruck\Literal;
use Eventjet\Ausdruck\Negative;
use Eventjet\Ausdruck\Or_;
use Eventjet\Ausdruck\Parser\Span;
use Eventjet\Ausdruck\StructLiteral;
use Eventjet\Ausdruck\Subtract;
use Eventjet\Ausdruck\Type;
use PHPUnit\Framework\TestCase;

final class ExpressionComparisonTest extends TestCase
{
    /**
     * @return iterable<array-key, array{Expression, Expression}>
     */
    public static function equalsCases(): iterable
    {
        yield [
            Expr::eq(Expr::literal('a'), Expr::literal('b')),
            Expr::eq(Expr::literal('a'), Expr::literal('b')),
        ];
        yield [
            Expr::get('a', Type::string()),
            Expr::get('a', Type::string()),
        ];
        yield [
            Expr::literal('foo'),
            Expr::literal('foo'),
        ];
        yield [
            Expr::or_(Expr::literal(true), Expr::literal(false)),
            Expr::or_(Expr::literal(true), Expr::literal(false)),
        ];
        yield [
            Expr::subtract(Expr::literal(1), Expr::literal(2)),
            Expr::subtract(Expr::literal(1), Expr::literal(2)),
        ];
        yield [
            Expr::gt(Expr::literal(1), Expr::literal(2)),
            Expr::gt(Expr::literal(1), Expr::literal(2)),
        ];
        yield [
            Expr::negative(Expr::literal(1)),
            Expr::negative(Expr::literal(1)),
        ];
        yield [
            Expr::listLiteral([Expr::literal(1), Expr::literal(2), Expr::literal(3)], Span::char(1, 1)),
            Expr::listLiteral([Expr::literal(1), Expr::literal(2), Expr::literal(3)], Span::char(1, 1)),
        ];
        yield [
            Expr::and_(Expr::literal(true), Expr::literal(false)),
            Expr::and_(Expr::literal(true), Expr::literal(false)),
        ];
        yield [
            Expr::fieldAccess(Expr::get('person', Type::struct(['name' => Type::string()])), 'name', self::location()),
            Expr::fieldAccess(Expr::get('person', Type::struct(['name' => Type::string()])), 'name', self::location()),
        ];
        yield [
            Expr::structLiteral(['foo' => Expr::literal('bar')], self::location()),
            Expr::structLiteral(['foo' => Expr::literal('bar')], self::location()),
        ];
        yield [
            Expr::structLiteral(['foo' => Expr::literal('bar'), 'bar' => Expr::literal('baz')], self::location()),
            Expr::structLiteral(['bar' => Expr::literal('baz'), 'foo' => Expr::literal('bar')], self::location()),
        ];
    }

    /**
     * @return iterable<string, array{Expression, Expression}>
     */
    public static function notEqualsCases(): iterable
    {
        yield Eq::class . ': left is different' => [
            Expr::eq(Expr::literal('a'), Expr::literal('a')),
            Expr::eq(Expr::literal('b'), Expr::literal('a')),
        ];
        yield Eq::class . ': right is different' => [
            Expr::eq(Expr::literal('a'), Expr::literal('a')),
            Expr::eq(Expr::literal('a'), Expr::literal('b')),
        ];
        yield Eq::class . ': different type' => [
            Expr::eq(Expr::literal('a'), Expr::literal('a')),
            Expr::or_(Expr::literal(true), Expr::literal(false)),
        ];
        yield Get::class . ': different names' => [
            Expr::get('a', Type::string()),
            Expr::get('b', Type::string()),
        ];
        yield Get::class . ': different types' => [
            Expr::get('a', Type::string()),
            Expr::get('a', Type::int()),
        ];
        yield Get::class . ': different type' => [
            Expr::get('a', Type::string()),
            Expr::literal('a'),
        ];
        yield Lambda::class . ': different number of parameters' => [
            Expr::lambda(Expr::literal(true), ['foo']),
            Expr::lambda(Expr::literal(true), ['foo', 'bar']),
        ];
        yield Lambda::class . ': different parameter' => [
            Expr::lambda(Expr::literal(true), ['a', 'b', 'c']),
            Expr::lambda(Expr::literal(true), ['a', 'x', 'c']),
        ];
        yield Lambda::class . ': different body' => [
            Expr::lambda(Expr::literal(true), ['a', 'b', 'c']),
            Expr::lambda(Expr::literal(false), ['a', 'b', 'c']),
        ];
        yield Lambda::class . ': different type' => [
            Expr::lambda(Expr::literal(true), ['a']),
            Expr::literal(true),
        ];
        yield Literal::class . ': different values' => [
            Expr::literal('foo'),
            Expr::literal('bar'),
        ];
        yield Literal::class . ': different type' => [
            Expr::literal('foo'),
            Expr::get('foo', Type::string()),
        ];
        yield Or_::class . ': left is different' => [
            Expr::or_(Expr::literal(true), Expr::literal(false)),
            Expr::or_(Expr::literal(false), Expr::literal(false)),
        ];
        yield Or_::class . ': right is different' => [
            Expr::or_(Expr::literal(true), Expr::literal(false)),
            Expr::or_(Expr::literal(true), Expr::literal(true)),
        ];
        yield Or_::class . ': both are different' => [
            Expr::or_(Expr::literal(true), Expr::literal(false)),
            Expr::or_(Expr::literal(false), Expr::literal(true)),
        ];
        yield Or_::class . ': different type' => [
            Expr::or_(Expr::literal(true), Expr::literal(false)),
            Expr::eq(Expr::literal(true), Expr::literal(false)),
        ];
        yield And_::class . ': left is different' => [
            Expr::and_(Expr::literal(true), Expr::literal(false)),
            Expr::and_(Expr::literal(false), Expr::literal(false)),
        ];
        yield And_::class . ': right is different' => [
            Expr::and_(Expr::literal(true), Expr::literal(false)),
            Expr::and_(Expr::literal(true), Expr::literal(true)),
        ];
        yield And_::class . ': both are different' => [
            Expr::and_(Expr::literal(true), Expr::literal(false)),
            Expr::and_(Expr::literal(false), Expr::literal(true)),
        ];
        yield And_::class . ': different type' => [
            Expr::and_(Expr::literal(true), Expr::literal(false)),
            Expr::eq(Expr::literal(true), Expr::literal(false)),
        ];
        yield And_::class . ' and ' . Or_::class => [
            Expr::and_(Expr::literal(false), Expr::literal(false)),
            Expr::or_(Expr::literal(false), Expr::literal(false)),
        ];
        yield And_::class . ' and ' . Or_::class . ' with different operands' => [
            Expr::and_(Expr::literal(false), Expr::literal(false)),
            Expr::or_(Expr::literal(true), Expr::literal(true)),
        ];
        yield Subtract::class . ': minuend is different' => [
            Expr::subtract(Expr::literal(1), Expr::literal(2)),
            Expr::subtract(Expr::literal(2), Expr::literal(2)),
        ];
        yield Subtract::class . ': subtrahend is different' => [
            Expr::subtract(Expr::literal(1), Expr::literal(2)),
            Expr::subtract(Expr::literal(1), Expr::literal(1)),
        ];
        yield Subtract::class . ': subtrahend and minuend are different' => [
            Expr::subtract(Expr::literal(1), Expr::literal(2)),
            Expr::subtract(Expr::literal(2), Expr::literal(1)),
        ];
        yield Subtract::class . ': different type' => [
            Expr::subtract(Expr::literal(1), Expr::literal(2)),
            Expr::literal(1),
        ];
        yield Call::class . ': target is different' => [
            Expr::call(Expr::literal(1), 'foo', Type::int(), []),
            Expr::call(Expr::literal(2), 'foo', Type::int(), []),
        ];
        yield Call::class . ': name is different' => [
            Expr::call(Expr::literal(1), 'foo', Type::int(), []),
            Expr::call(Expr::literal(1), 'bar', Type::int(), []),
        ];
        yield Call::class . ': type is different' => [
            Expr::call(Expr::literal(1), 'foo', Type::int(), []),
            Expr::call(Expr::literal(1), 'foo', Type::string(), []),
        ];
        yield Call::class . ': different number of arguments' => [
            Expr::call(Expr::literal(1), 'foo', Type::int(), []),
            Expr::call(Expr::literal(1), 'foo', Type::int(), [Expr::literal(1)]),
        ];
        yield Call::class . ': argument is different' => [
            Expr::call(Expr::literal(1), 'foo', Type::int(), [Expr::literal(1), Expr::literal(2), Expr::literal(3)]),
            Expr::call(Expr::literal(1), 'foo', Type::int(), [Expr::literal(1), Expr::literal(9), Expr::literal(3)]),
        ];
        yield Call::class . ': different type' => [
            Expr::call(Expr::literal(1), 'foo', Type::int(), []),
            Expr::literal(1),
        ];
        yield Gt::class . ': left is different' => [
            Expr::gt(Expr::literal(1), Expr::literal(2)),
            Expr::gt(Expr::literal(2), Expr::literal(2)),
        ];
        yield Gt::class . ': right is different' => [
            Expr::gt(Expr::literal(1), Expr::literal(2)),
            Expr::gt(Expr::literal(1), Expr::literal(1)),
        ];
        yield Gt::class . ': both are different' => [
            Expr::gt(Expr::literal(1), Expr::literal(2)),
            Expr::gt(Expr::literal(2), Expr::literal(1)),
        ];
        yield Gt::class . ': different type' => [
            Expr::gt(Expr::literal(1), Expr::literal(2)),
            Expr::eq(Expr::literal(1), Expr::literal(2)),
        ];
        yield Negative::class . ': different type' => [
            Expr::negative(Expr::literal(1)),
            Expr::literal(1),
        ];
        yield Negative::class . ': different expression' => [
            Expr::negative(Expr::literal(1)),
            Expr::negative(Expr::literal(2)),
        ];
        yield ListLiteral::class . ': different elements' => [
            Expr::listLiteral([Expr::literal(1), Expr::literal(2), Expr::literal(3)], Span::char(1, 1)),
            Expr::listLiteral([Expr::literal(1), Expr::literal(9), Expr::literal(3)], Span::char(1, 1)),
        ];
        yield ListLiteral::class . ': different type' => [
            Expr::listLiteral([Expr::literal(1)], Span::char(1, 1)),
            Expr::literal(1),
        ];
        $personType = Type::struct(['name' => Type::string(), 'age' => Type::int()]);
        yield FieldAccess::class . ': different fields' => [
            Expr::fieldAccess(Expr::get('person', $personType), 'name', self::location()),
            Expr::fieldAccess(Expr::get('person', $personType), 'age', self::location()),
        ];
        yield FieldAccess::class . ': different targets' => [
            Expr::fieldAccess(Expr::get('person', $personType), 'name', self::location()),
            Expr::fieldAccess(Expr::get('address', $personType), 'name', self::location()),
        ];
        yield FieldAccess::class . ': different type' => [
            Expr::fieldAccess(Expr::get('person', $personType), 'name', self::location()),
            Expr::call(Expr::get('person', $personType), 'name', Type::string(), []),
        ];
        yield StructLiteral::class . ': different type' => [
            new StructLiteral(['foo' => Expr::literal('bar')], self::location()),
            Expr::literal('bar'),
        ];
        yield StructLiteral::class . ': different field name' => [
            new StructLiteral(['foo' => Expr::literal('bar')], self::location()),
            new StructLiteral(['bar' => Expr::literal('bar')], self::location()),
        ];
        yield StructLiteral::class . ': different field value' => [
            new StructLiteral(['foo' => Expr::literal('bar')], self::location()),
            new StructLiteral(['foo' => Expr::literal('baz')], self::location()),
        ];
        yield StructLiteral::class . ': additional field' => [
            new StructLiteral(['foo' => Expr::literal('bar')], self::location()),
            new StructLiteral(['foo' => Expr::literal('bar'), 'bar' => Expr::literal('baz')], self::location()),
        ];
        yield StructLiteral::class . ': different field value in second field' => [
            new StructLiteral(['foo' => Expr::literal('bar'), 'bar' => Expr::literal('a')], self::location()),
            new StructLiteral(['foo' => Expr::literal('bar'), 'bar' => Expr::literal('b')], self::location()),
        ];
    }

    private static function location(): Span
    {
        return Span::char(1, 1);
    }

    /**
     * @dataProvider equalsCases
     */
    public function testEquals(Expression $a, Expression $b): void
    {
        self::assertTrue($a->equals($b));
        self::assertTrue($b->equals($a));
    }

    /**
     * @dataProvider notEqualsCases
     */
    public function testNotEquals(Expression $a, Expression $b): void
    {
        self::assertFalse($a->equals($b));
        self::assertFalse($b->equals($a));
    }
}
