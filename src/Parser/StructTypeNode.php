<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use Override;

use function implode;
use function sprintf;

/**
 * A struct type, `{ name: string }`: written as its fields between `{ }` rather than a name and its arguments between
 * `< >`, so it's the one shape with no name of its own. {@see TypeNode::struct()} builds one, and
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
        return $this->fields === [] ? '' : sprintf('{ %s }', implode(', ', $this->fields));
    }
}
