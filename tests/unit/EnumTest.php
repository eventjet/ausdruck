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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EnumTest extends TestCase
{
    /** @return iterable<string, array{string, list<string>, array<string, list<Type>>, string}> */
    public static function invalidDefinitions(): iterable
    {
        yield 'no variants' => ['Empty', [], [], 'An enum needs variants and distinct type parameters'];
        yield 'duplicate parameters' => ['Result', ['T', 'T'], ['Ok' => [Type::var('T')]], 'An enum needs variants and distinct type parameters'];
        yield 'invalid name' => ['Bad-name', [], ['Ready' => []], 'Invalid enum identifier: Bad-name'];
        yield 'invalid second variant' => ['Status', [], ['Ready' => [], 'Bad-name' => []], 'Invalid enum identifier: Bad-name'];
        yield 'invalid name prefix' => ['1Status', [], ['Ready' => []], 'Invalid enum identifier: 1Status'];
        yield 'invalid name suffix' => ['Status!', [], ['Ready' => []], 'Invalid enum identifier: Status!'];
        yield 'trailing newline' => ["Status\n", [], ['Ready' => []], "Invalid enum identifier: Status\n"];
    }

    /** @param list<string> $parameters
     * @param array<string, list<Type>> $variants
     */
    #[DataProvider('invalidDefinitions')]
    public function testInvalidDefinitionsAreRejected(string $name, array $parameters, array $variants, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new EnumDefinition($name, $parameters, $variants);
    }

    public function testValueRejectsAnUnknownVariant(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown variant Missing');

        Prelude::option()->value('Missing');
    }

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
