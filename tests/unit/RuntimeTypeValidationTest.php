<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit;

use Eventjet\Ausdruck\EnumValue;
use Eventjet\Ausdruck\Parser\TypeError;
use Eventjet\Ausdruck\Type;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RuntimeTypeValidationTest extends TestCase
{
    /** @return iterable<string, array{Type, mixed}> */
    public static function callableContainers(): iterable
    {
        $function = Type::func(Type::int(), [Type::int()]);
        $callback = static fn(int $value): int => $value;
        yield 'list' => [Type::listOf($function), [$callback]];
        yield 'map' => [Type::mapOf(Type::string(), $function), ['first' => $callback]];
        yield 'struct' => [Type::struct(['f' => $function]), (object)['f' => $callback]];
        yield 'alias' => [Type::alias('Callbacks', Type::listOf($function)), [$callback]];
        yield 'nested containers' => [
            Type::mapOf(Type::string(), Type::listOf(Type::struct(['f' => $function]))),
            ['first' => [(object)['f' => $callback]]],
        ];
    }

    /** @return iterable<string, array{Type, mixed}> */
    public static function invalidContainers(): iterable
    {
        $function = Type::func(Type::int());
        yield 'non-array list' => [Type::listOf($function), 'invalid'];
        yield 'map passed as list' => [Type::listOf($function), ['first' => static fn(): int => 1]];
        yield 'list passed as map' => [Type::mapOf(Type::int(), $function), [static fn(): int => 1]];
        yield 'wrong map key' => [Type::mapOf(Type::int(), $function), ['first' => static fn(): int => 1]];
        yield 'non-callable list item' => [Type::listOf($function), [42]];
        yield 'non-callable map value' => [Type::mapOf(Type::string(), $function), ['first' => 42]];
        yield 'non-callable struct field' => [Type::struct(['f' => $function]), (object)['f' => 42]];
        yield 'enum is not a struct' => [Type::struct([]), new EnumValue(Type::none(), 'None')];
        yield 'scalar is not an enum' => [Type::option(Type::int()), 42];
        yield 'incompatible enum payload type' => [Type::option(Type::int()), new EnumValue(Type::option(Type::string()), 'Some', ['text'])];
    }

    #[DataProvider('callableContainers')]
    public function testEnumPayloadAcceptsNestedCallables(Type $payloadType, mixed $payload): void
    {
        $type = Type::option($payloadType);
        $value = new EnumValue($type, 'Some', [$payload]);

        self::assertSame($value, $type->assert($value));
    }

    #[DataProvider('invalidContainers')]
    public function testRejectsInvalidContainers(Type $type, mixed $value): void
    {
        $this->expectException(TypeError::class);

        $type->assert($value);
    }

    public function testRevalidatesMutablePayloads(): void
    {
        $payload = (object)['f' => static fn(): int => 42];
        $type = Type::option(Type::struct(['f' => Type::func(Type::int())]));
        $value = new EnumValue($type, 'Some', [$payload]);
        $payload->f = 42;
        $this->expectException(TypeError::class);

        $type->assert($value);
    }
}
