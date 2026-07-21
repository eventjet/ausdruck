<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use Override;

use function sprintf;

/**
 * One field of a struct type, `name: type`. {@see TypeNode::keyValue()} builds one, and {@see StructTypeNode::$fields}
 * is a list of them; nothing else is.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class FieldTypeNode extends TypeNode
{
    public function __construct(public readonly TypeNode $fieldName, public readonly TypeNode $fieldType)
    {
        parent::__construct('', [], $fieldName->location->to($fieldType->location));
    }

    #[Override]
    public function __toString(): string
    {
        return sprintf('%s: %s', $this->fieldName, $this->fieldType);
    }
}
