<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use InvalidArgumentException;

use function array_key_exists;
use function count;

/** @api */
final class EnumValue
{
    /** @param list<mixed> $fields */
    public function __construct(public readonly Type $type, public readonly string $variant, public readonly array $fields = [])
    {
        if ($type->hasFreeVariables()) {
            throw new InvalidArgumentException('Enum values require concrete type arguments');
        }
        $shape = $type->asEnum();
        if ($shape === null || !array_key_exists($variant, $shape->definition->variants)) {
            throw new InvalidArgumentException('Unknown enum variant ' . $variant);
        }
        $expected = $shape->fields($variant);
        if (count($fields) !== count($expected)) {
            throw new InvalidArgumentException('Wrong field count for ' . $variant);
        }
        foreach ($expected as $index => $field) {
            $field->assert($fields[$index]);
        }
    }
}
