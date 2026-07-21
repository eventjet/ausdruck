<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use Override;
use Stringable;

use function sprintf;

/**
 * One field of a struct type, `name: type` -- a pair, not a type in its own right, which is why this doesn't extend
 * {@see TypeNode} the way {@see FunctionTypeNode} and {@see StructTypeNode} do: nothing here should ever be handed to
 * {@see TypeResolution::resolve()}. {@see TypeParser} is the only thing that builds one, and
 * {@see StructTypeNode::$fields} is a list of them. Nothing needs this pair's own span as a whole -- an error about
 * the name points at {@see self::$fieldName}'s, and one about the type at {@see self::$fieldType}'s -- so unlike
 * {@see TypeNode} and {@see Identifier}, there is no combined one to carry.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class FieldTypeNode implements Stringable
{
    public function __construct(
        public readonly Identifier $fieldName,
        public readonly TypeNode $fieldType,
    ) {
    }

    #[Override]
    public function __toString(): string
    {
        return sprintf('%s: %s', $this->fieldName, $this->fieldType);
    }
}
