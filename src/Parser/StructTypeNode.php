<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use Eventjet\Ausdruck\TypeSyntax;
use Override;

use function array_map;

/**
 * A struct type, `{ name: string }`: written as its fields between `{ }` rather than a name and its arguments between
 * `< >`, so it's the one shape with no name of its own. {@see TypeParser} builds one, and
 * {@see TypeResolution::resolveStruct()} is the one place that reads $fields.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class StructTypeNode extends TypeNode
{
    /**
     * @param list<FieldTypeNode> $fields
     */
    public function __construct(
        public readonly array $fields,
        Span $location,
    ) {
        parent::__construct('', [], $location);
    }

    #[Override]
    public function __toString(): string
    {
        return TypeSyntax::struct(array_map(static fn(FieldTypeNode $field): string => (string)$field, $this->fields));
    }
}
