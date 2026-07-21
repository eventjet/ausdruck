<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit;

use Eventjet\Ausdruck\Add;
use Eventjet\Ausdruck\And_;
use Eventjet\Ausdruck\Call;
use Eventjet\Ausdruck\ComparisonOperator;
use Eventjet\Ausdruck\Divide;
use Eventjet\Ausdruck\Expr;
use Eventjet\Ausdruck\Expression;
use Eventjet\Ausdruck\FieldAccess;
use Eventjet\Ausdruck\Get;
use Eventjet\Ausdruck\Lambda;
use Eventjet\Ausdruck\ListLiteral;
use Eventjet\Ausdruck\Literal;
use Eventjet\Ausdruck\Modulo;
use Eventjet\Ausdruck\Multiply;
use Eventjet\Ausdruck\Negative;
use Eventjet\Ausdruck\Not;
use Eventjet\Ausdruck\Or_;
use Eventjet\Ausdruck\Parser\Span;
use Eventjet\Ausdruck\StructLiteral;
use Eventjet\Ausdruck\Subtract;
use Eventjet\Ausdruck\Type;
use PHPUnit\Framework\Attributes\DataProvider;
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
            Expr::add(Expr::literal(1), Expr::literal(2)),
            Expr::add(Expr::literal(1), Expr::literal(2)),
        ];
        yield [
            Expr::multiply(Expr::literal(1), Expr::literal(2)),
            Expr::multiply(Expr::literal(1), Expr::literal(2)),
        ];
        yield [
            Expr::divide(Expr::literal(1), Expr::literal(2)),
            Expr::divide(Expr::literal(1), Expr::literal(2)),
        ];
        yield [
            Expr::modulo(Expr::literal(1), Expr::literal(2)),
            Expr::modulo(Expr::literal(1), Expr::literal(2)),
        ];
        yield [
            Expr::gt(Expr::literal(1), Expr::literal(2)),
            Expr::gt(Expr::literal(1), Expr::literal(2)),
        ];
        yield [
            Expr::lt(Expr::literal(1), Expr::literal(2)),
            Expr::lt(Expr::literal(1), Expr::literal(2)),
        ];
        yield [
            Expr::gte(Expr::literal(1), Expr::literal(2)),
            Expr::gte(Expr::literal(1), Expr::literal(2)),
        ];
        yield [
            Expr::lte(Expr::literal(1), Expr::literal(2)),
            Expr::lte(Expr::literal(1), Expr::literal(2)),
        ];
        yield [
            Expr::neq(Expr::literal('a'), Expr::literal('b')),
            Expr::neq(Expr::literal('a'), Expr::literal('b')),
        ];
        yield [
            Expr::negative(Expr::get('a', Type::int())),
            Expr::negative(Expr::get('a', Type::int())),
        ];
        // A negated number literal is a negative number literal, not a negation of a positive one.
        yield [
            Expr::negative(Expr::literal(1)),
            Expr::literal(-1),
        ];
        yield [
            Expr::not(Expr::get('a', Type::bool())),
            Expr::not(Expr::get('a', Type::bool())),
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
        yield ComparisonOperator::Equals->name . ': left is different' => [
            Expr::eq(Expr::literal('a'), Expr::literal('a')),
            Expr::eq(Expr::literal('b'), Expr::literal('a')),
        ];
        yield ComparisonOperator::Equals->name . ': right is different' => [
            Expr::eq(Expr::literal('a'), Expr::literal('a')),
            Expr::eq(Expr::literal('a'), Expr::literal('b')),
        ];
        yield ComparisonOperator::Equals->name . ': different type' => [
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
        yield Add::class . ': augend is different' => [
            Expr::add(Expr::literal(1), Expr::literal(2)),
            Expr::add(Expr::literal(2), Expr::literal(2)),
        ];
        yield Add::class . ': addend is different' => [
            Expr::add(Expr::literal(1), Expr::literal(2)),
            Expr::add(Expr::literal(1), Expr::literal(1)),
        ];
        // The operands match, so only the operator tells the two apart.
        yield Add::class . ' and ' . Subtract::class => [
            Expr::add(Expr::literal(1), Expr::literal(2)),
            Expr::subtract(Expr::literal(1), Expr::literal(2)),
        ];
        yield Multiply::class . ': multiplicand is different' => [
            Expr::multiply(Expr::literal(1), Expr::literal(2)),
            Expr::multiply(Expr::literal(2), Expr::literal(2)),
        ];
        yield Multiply::class . ': multiplier is different' => [
            Expr::multiply(Expr::literal(1), Expr::literal(2)),
            Expr::multiply(Expr::literal(1), Expr::literal(1)),
        ];
        yield Multiply::class . ' and ' . Divide::class => [
            Expr::multiply(Expr::literal(1), Expr::literal(2)),
            Expr::divide(Expr::literal(1), Expr::literal(2)),
        ];
        yield Divide::class . ': dividend is different' => [
            Expr::divide(Expr::literal(1), Expr::literal(2)),
            Expr::divide(Expr::literal(2), Expr::literal(2)),
        ];
        yield Divide::class . ': divisor is different' => [
            Expr::divide(Expr::literal(1), Expr::literal(2)),
            Expr::divide(Expr::literal(1), Expr::literal(1)),
        ];
        yield Divide::class . ' and ' . Modulo::class => [
            Expr::divide(Expr::literal(1), Expr::literal(2)),
            Expr::modulo(Expr::literal(1), Expr::literal(2)),
        ];
        yield Modulo::class . ': dividend is different' => [
            Expr::modulo(Expr::literal(1), Expr::literal(2)),
            Expr::modulo(Expr::literal(2), Expr::literal(2)),
        ];
        yield Modulo::class . ': divisor is different' => [
            Expr::modulo(Expr::literal(1), Expr::literal(2)),
            Expr::modulo(Expr::literal(1), Expr::literal(1)),
        ];
        yield Call::class . ': target is different' => [
            Expr::literal(1)->call('foo', Type::int(), []),
            Expr::literal(2)->call('foo', Type::int(), []),
        ];
        yield Call::class . ': name is different' => [
            Expr::literal(1)->call('foo', Type::int(), []),
            Expr::literal(1)->call('bar', Type::int(), []),
        ];
        yield Call::class . ': type is different' => [
            Expr::literal(1)->call('foo', Type::int(), []),
            Expr::literal(1)->call('foo', Type::string(), []),
        ];
        yield Call::class . ': different number of arguments' => [
            Expr::literal(1)->call('foo', Type::int(), []),
            Expr::literal(1)->call('foo', Type::int(), [Expr::literal(1)]),
        ];
        yield Call::class . ': argument is different' => [
            Expr::literal(1)->call('foo', Type::int(), [Expr::literal(1), Expr::literal(2), Expr::literal(3)]),
            Expr::literal(1)->call('foo', Type::int(), [Expr::literal(1), Expr::literal(9), Expr::literal(3)]),
        ];
        yield Call::class . ': different type' => [
            Expr::literal(1)->call('foo', Type::int(), []),
            Expr::literal(1),
        ];
        yield ComparisonOperator::GreaterThan->name . ': left is different' => [
            Expr::gt(Expr::literal(1), Expr::literal(2)),
            Expr::gt(Expr::literal(2), Expr::literal(2)),
        ];
        yield ComparisonOperator::GreaterThan->name . ': right is different' => [
            Expr::gt(Expr::literal(1), Expr::literal(2)),
            Expr::gt(Expr::literal(1), Expr::literal(1)),
        ];
        yield ComparisonOperator::GreaterThan->name . ': both are different' => [
            Expr::gt(Expr::literal(1), Expr::literal(2)),
            Expr::gt(Expr::literal(2), Expr::literal(1)),
        ];
        yield ComparisonOperator::GreaterThan->name . ': different type' => [
            Expr::gt(Expr::literal(1), Expr::literal(2)),
            Expr::eq(Expr::literal(1), Expr::literal(2)),
        ];
        yield ComparisonOperator::LessThan->name . ': left is different' => [
            Expr::lt(Expr::literal(1), Expr::literal(2)),
            Expr::lt(Expr::literal(2), Expr::literal(2)),
        ];
        yield ComparisonOperator::LessThan->name . ': right is different' => [
            Expr::lt(Expr::literal(1), Expr::literal(2)),
            Expr::lt(Expr::literal(1), Expr::literal(1)),
        ];
        yield ComparisonOperator::LessThan->name . ': different type' => [
            Expr::lt(Expr::literal(1), Expr::literal(2)),
            Expr::gt(Expr::literal(1), Expr::literal(2)),
        ];
        yield ComparisonOperator::GreaterThanOrEqual->name . ': left is different' => [
            Expr::gte(Expr::literal(1), Expr::literal(2)),
            Expr::gte(Expr::literal(2), Expr::literal(2)),
        ];
        yield ComparisonOperator::GreaterThanOrEqual->name . ': right is different' => [
            Expr::gte(Expr::literal(1), Expr::literal(2)),
            Expr::gte(Expr::literal(1), Expr::literal(1)),
        ];
        yield ComparisonOperator::GreaterThanOrEqual->name . ': different type' => [
            Expr::gte(Expr::literal(1), Expr::literal(2)),
            Expr::gt(Expr::literal(1), Expr::literal(2)),
        ];
        yield ComparisonOperator::LessThanOrEqual->name . ': left is different' => [
            Expr::lte(Expr::literal(1), Expr::literal(2)),
            Expr::lte(Expr::literal(2), Expr::literal(2)),
        ];
        yield ComparisonOperator::LessThanOrEqual->name . ': right is different' => [
            Expr::lte(Expr::literal(1), Expr::literal(2)),
            Expr::lte(Expr::literal(1), Expr::literal(1)),
        ];
        yield ComparisonOperator::LessThanOrEqual->name . ': different type' => [
            Expr::lte(Expr::literal(1), Expr::literal(2)),
            Expr::lt(Expr::literal(1), Expr::literal(2)),
        ];
        yield ComparisonOperator::NotEquals->name . ': left is different' => [
            Expr::neq(Expr::literal(1), Expr::literal(2)),
            Expr::neq(Expr::literal(2), Expr::literal(2)),
        ];
        yield ComparisonOperator::NotEquals->name . ': right is different' => [
            Expr::neq(Expr::literal(1), Expr::literal(2)),
            Expr::neq(Expr::literal(1), Expr::literal(1)),
        ];
        yield ComparisonOperator::NotEquals->name . ': different type' => [
            Expr::neq(Expr::literal(1), Expr::literal(2)),
            Expr::eq(Expr::literal(1), Expr::literal(2)),
        ];
        yield Negative::class . ': different type' => [
            Expr::negative(Expr::get('a', Type::int())),
            Expr::get('a', Type::int()),
        ];
        yield Negative::class . ': different expression' => [
            Expr::negative(Expr::get('a', Type::int())),
            Expr::negative(Expr::get('b', Type::int())),
        ];
        yield Not::class . ': different type' => [
            Expr::not(Expr::literal(true)),
            Expr::literal(true),
        ];
        yield Not::class . ': different expression' => [
            Expr::not(Expr::literal(true)),
            Expr::not(Expr::literal(false)),
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
            Expr::get('person', $personType)->call('name', Type::string(), []),
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

    #[DataProvider('equalsCases')]
    public function testEquals(Expression $a, Expression $b): void
    {
        self::assertTrue($a->equals($b));
        self::assertTrue($b->equals($a));
    }

    #[DataProvider('notEqualsCases')]
    public function testNotEquals(Expression $a, Expression $b): void
    {
        self::assertFalse($a->equals($b));
        self::assertFalse($b->equals($a));
    }
}
