<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Test\Unit;

use function array_keys;
use function get_object_vars;
use function is_array;
use function is_object;

/**
 * The shared expectation of both evaluation harnesses: an evaluated value has to be the expected one, PHP type
 * included.
 *
 * assertEquals() is too weak to say that. It can't tell 0 from null, null from false, or 24 from 24.0 — and those are
 * exactly the distinctions the evaluation cases exist to pin. `isSome` on a none answers false rather than the null
 * inside it, and an int subtraction gives back an int rather than a float: under assertEquals either case passes
 * whether or not the library still holds up its end.
 *
 * Structs are the one place identity isn't available: the library builds its own objects, so an expectation is never
 * the same instance as the result. They are therefore compared field by field, and lists element by element, until the
 * recursion reaches a scalar — which is compared strictly. Every scalar anywhere in the value is held to the same
 * standard, however deeply it sits.
 */
trait AssertsEvaluatedValues
{
    private static function assertEvaluatesTo(mixed $expected, mixed $actual): void
    {
        if (is_array($expected) && is_array($actual)) {
            self::assertSame(array_keys($expected), array_keys($actual), 'List keys differ');
            /** @var mixed $item */
            foreach ($expected as $key => $item) {
                self::assertEvaluatesTo($item, $actual[$key]);
            }
            return;
        }
        if (is_object($expected) && is_object($actual)) {
            $expectedFields = get_object_vars($expected);
            $actualFields = get_object_vars($actual);
            self::assertSame(array_keys($expectedFields), array_keys($actualFields), 'Struct fields differ');
            /** @var mixed $value */
            foreach ($expectedFields as $field => $value) {
                self::assertEvaluatesTo($value, $actualFields[$field]);
            }
            return;
        }
        self::assertSame($expected, $actual);
    }
}
