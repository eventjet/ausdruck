<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use Eventjet\Ausdruck\Type;
use Eventjet\Ausdruck\TypeSyntax;
use Override;

use function array_map;

/**
 * A function type, `fn<T>(int) -> T`: the one shape with a return type and a binder of its own, so the one that
 * needs more than a name and its arguments -- and the one with no name of its own either, unlike
 * {@see ApplicationTypeNode}: it's never looked up by one, only ever recognized by {@see TypeParser::parse()} seeing
 * the keyword `fn` and routing to {@see TypeParser::parseFunction()} directly. {@see TypeResolution::resolveSignature()}
 * is the one place that reads $parameters, $returnType and $typeParameters -- all unconditionally there rather than
 * nullable, so nothing has to assert that they exist.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class FunctionTypeNode extends TypeNode
{
    /**
     * @param list<TypeNode> $parameters
     * @param list<Identifier> $typeParameters The type variables this function type binds, which are only ever the
     *     names themselves -- not types, which is why they're {@see Identifier}s rather than {@see TypeNode}s -- each
     *     carrying the span an error about it points at; see {@see TypeResolution::checkTypeVariable()}.
     */
    public function __construct(
        public readonly array $parameters,
        public readonly TypeNode $returnType,
        public readonly array $typeParameters,
        Span $location,
    ) {
        parent::__construct($location);
    }

    #[Override]
    public function __toString(): string
    {
        return TypeSyntax::func(
            array_map(static fn(Identifier $parameter): string => $parameter->name, $this->typeParameters),
            array_map(static fn(TypeNode $arg): string => (string)$arg, $this->parameters),
            (string)$this->returnType,
        );
    }

    #[Override]
    public function resolveWith(TypeResolution $resolution): Type|TypeError
    {
        return $resolution->resolveSignature($this);
    }
}
