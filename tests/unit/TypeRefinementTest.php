<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit;

use Eventjet\Ausdruck\Parser\ExpressionParser;
use Eventjet\Ausdruck\Type;
use PHPUnit\Framework\TestCase;

final class TypeRefinementTest extends TestCase
{
    public function testKeepsAnAliasWhenTheActualShapeDoesNotMatch(): void
    {
        $alias = Type::alias('Count', Type::int());

        self::assertSame($alias, $alias->refine(Type::string()));
    }

    public function testRefinesNestedFunctionResults(): void
    {
        $first = Type::func(Type::func(Type::none()));
        $second = Type::func(Type::func(Type::option(Type::int())));

        self::assertTrue($first->refine($second)->equals($second));
    }

    public function testKeepsConcreteFunctionResults(): void
    {
        $first = Type::func(Type::option(Type::int()));
        $second = Type::func(Type::option(Type::string()));

        self::assertTrue($first->refine($second)->equals($first));
    }

    public function testDoesNotWidenContravariantParameters(): void
    {
        $first = Type::func(Type::int(), [Type::none()]);
        $second = Type::func(Type::int(), [Type::option(Type::int())]);

        self::assertTrue($first->refine($second)->equals($first));
    }

    public function testDoesNotRefineAQuantifiedSignature(): void
    {
        $first = ExpressionParser::parse('f:fn<T>(T) -> Option<!>')->getType();
        $second = ExpressionParser::parse('f:fn<T>(T) -> Option<int>')->getType();

        self::assertTrue($first->refine($second)->equals($first));
    }

    public function testDoesNotBorrowAQuantifiedResult(): void
    {
        $first = Type::func(Type::never());
        $second = ExpressionParser::parse('f:fn<T>(T) -> T')->getType();

        self::assertTrue($first->refine($second)->equals($first));
    }

    public function testDoesNotRefineAFunctionFromAnotherShape(): void
    {
        $first = Type::func(Type::never());

        self::assertTrue($first->refine(Type::int())->equals($first));
    }
}
