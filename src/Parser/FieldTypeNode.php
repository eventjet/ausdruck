<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use Override;

use function sprintf;

/**
 * One field of a struct type, `name: type`. {@see TypeNode::keyValue()} builds one, and the list
 * {@see TypeNode::struct()} takes is a list of them; nothing else does, so there's no sentinel to read this shape off
 * of the way {@see TypeNode::$delimiters} tells a struct apart from a plain name.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class FieldTypeNode extends TypeNode
{
    public function __construct(TypeNode $key, TypeNode $value)
    {
        parent::__construct('', [$key, $value], $key->location->to($value->location));
    }

    #[Override]
    public function __toString(): string
    {
        return sprintf('%s: %s', $this->args[0], $this->args[1]);
    }
}
