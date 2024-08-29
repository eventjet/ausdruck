<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit;

use Eventjet\Ausdruck\And_;
use Eventjet\Ausdruck\Call;
use Eventjet\Ausdruck\Expr;
use Eventjet\Ausdruck\Expression;
use Eventjet\Ausdruck\Get;
use Eventjet\Ausdruck\Lambda;
use Eventjet\Ausdruck\ListLiteral;
use Eventjet\Ausdruck\Literal;
use Eventjet\Ausdruck\Parser\Span;
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
    }

    /**
     * @return iterable<string, array{Expression, Expression}>
     */
    public static function notEqualsCases(): iterable
    {
        yield '===: left is different' => [
            Expr::eq(Expr::literal('a'), Expr::literal('a')),
            Expr::eq(Expr::literal('b'), Expr::literal('a')),
        ];
        yield '===: right is different' => [
            Expr::eq(Expr::literal('a'), Expr::literal('a')),
            Expr::eq(Expr::literal('a'), Expr::literal('b')),
        ];
        yield '===: different type' => [
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
        yield '||: left is different' => [
            Expr::or_(Expr::literal(true), Expr::literal(false)),
            Expr::or_(Expr::literal(false), Expr::literal(false)),
        ];
        yield '||: right is different' => [
            Expr::or_(Expr::literal(true), Expr::literal(false)),
            Expr::or_(Expr::literal(true), Expr::literal(true)),
        ];
        yield '||: both are different' => [
            Expr::or_(Expr::literal(true), Expr::literal(false)),
            Expr::or_(Expr::literal(false), Expr::literal(true)),
        ];
        yield '||: different type' => [
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
        yield '-: minuend is different' => [
            Expr::subtract(Expr::literal(1), Expr::literal(2)),
            Expr::subtract(Expr::literal(2), Expr::literal(2)),
        ];
        yield '-: subtrahend is different' => [
            Expr::subtract(Expr::literal(1), Expr::literal(2)),
            Expr::subtract(Expr::literal(1), Expr::literal(1)),
        ];
        yield '-: subtrahend and minuend are different' => [
            Expr::subtract(Expr::literal(1), Expr::literal(2)),
            Expr::subtract(Expr::literal(2), Expr::literal(1)),
        ];
        yield '-: different type' => [
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
        yield '>: left is different' => [
            Expr::gt(Expr::literal(1), Expr::literal(2)),
            Expr::gt(Expr::literal(2), Expr::literal(2)),
        ];
        yield '>: right is different' => [
            Expr::gt(Expr::literal(1), Expr::literal(2)),
            Expr::gt(Expr::literal(1), Expr::literal(1)),
        ];
        yield '>: both are different' => [
            Expr::gt(Expr::literal(1), Expr::literal(2)),
            Expr::gt(Expr::literal(2), Expr::literal(1)),
        ];
        yield '>: different type' => [
            Expr::gt(Expr::literal(1), Expr::literal(2)),
            Expr::eq(Expr::literal(1), Expr::literal(2)),
        ];
        yield 'Negative: different type' => [
            Expr::negative(Expr::literal(1)),
            Expr::literal(1),
        ];
        yield 'Negative: different expression' => [
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
