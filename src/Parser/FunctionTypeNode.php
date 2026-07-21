<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use Override;

use function implode;
use function sprintf;

/**
 * A function type, `fn<T>(int) -> T`: the one shape with a return type and a binder of its own, so the one that
 * needs more than a name and its arguments. {@see TypeNode::function()} builds one, and
 * {@see TypeResolution::resolveFunction()} is the one place that reads $returnType and $typeParameters -- both are
 * unconditionally there rather than nullable, so nothing has to assert that a node named `fn` has them.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class FunctionTypeNode extends TypeNode
{
    /**
     * @param list<TypeNode> $parameters
     * @param list<TypeNode> $typeParameters The type variables this function type binds, which are only ever the
     *     names themselves. They are nodes rather than strings so that each one carries the span an error about it
     *     points at; see {@see Types::resolve()}.
     */
    public function __construct(
        array $parameters,
        public readonly TypeNode $returnType,
        public readonly array $typeParameters,
        Span $location,
    ) {
        parent::__construct('fn', $parameters, $location);
    }

    #[Override]
    public function __toString(): string
    {
        return sprintf(
            'fn%s(%s) -> %s',
            $this->typeParameters === [] ? '' : sprintf('<%s>', implode(', ', $this->typeParameters)),
            implode(', ', $this->args),
            $this->returnType,
        );
    }
}
