<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit;

use Eventjet\Ausdruck\AbstractLiteral;
use Eventjet\Ausdruck\EnumDefinition;
use Eventjet\Ausdruck\EnumValue;
use Eventjet\Ausdruck\Parser\ExpressionParser;
use Eventjet\Ausdruck\Parser\TypeError;
use Eventjet\Ausdruck\Parser\Types;
use Eventjet\Ausdruck\Prelude;
use Eventjet\Ausdruck\Type;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EnumTest extends TestCase
{
    public function testFunctionAliasRejectsShadowingANestedBinderBeforeAnOption(): void
    {
        $first = ExpressionParser::parse('first:fn<A>(A) -> A')->getType();
        $second = ExpressionParser::parse('second:fn<B>(B) -> B')->getType();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('would introduce B, which a nested function type already declares');

        Type::alias('Outer', Type::func(Type::var('B'), [$first, $second, Prelude::option()->type(Type::int())]));
    }

    public function testDuplicateEnumTypeNamesAreRejected(): void
    {
        $first = new EnumDefinition('Duplicate', [], ['First' => []]);
        $second = new EnumDefinition('Duplicate', [], ['Second' => []]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate or reserved type Duplicate');

        new Types(enums: [$first, $second]);
    }

    public function testEnumValueRejectsAnIncorrectPayloadType(): void
    {
        $this->expectException(TypeError::class);
        $this->expectExceptionMessage('Expected int, got string');

        new EnumValue(Prelude::option()->type(Type::int()), 'Some', ['wrong']);
    }

    public function testDuplicateVariantNamesAreRejected(): void
    {
        $first = new EnumDefinition('First', [], ['Shared' => []]);
        $second = new EnumDefinition('Second', [], ['Shared' => []]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate or reserved variant Shared');

        new Types(enums: [$first, $second]);
    }

    public function testParsedLiteralInputRejectsAnEnumPayloadThatNeedsAScope(): void
    {
        $expression = ExpressionParser::parse('{ payload: Some(x:any) }');
        self::assertInstanceOf(AbstractLiteral::class, $expression);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Variant field is not a literal');

        $expression->value();
    }
}
