<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

/**
 * A struct, written as its fields between `{ }` rather than as a name -- see {@see Type::struct()}.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class StructShape implements TypeShape
{
    /**
     * @param array<string, Type> $fields
     */
    public function __construct(
        public readonly array $fields,
    ) {
    }
}
