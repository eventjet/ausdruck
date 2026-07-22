<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use Eventjet\Ausdruck\TypeSyntax;
use Override;

use function array_map;

/**
 * A name applied to type arguments, e.g. `map<T, U>` or a bare `int`. {@see FieldTypeNode}, {@see FunctionTypeNode}
 * and {@see StructTypeNode} are their own classes, built directly by {@see TypeParser} rather than through a factory
 * here, because each carries parts -- a field's name and type, a function's return type and binder, a struct's
 * fields -- that nothing here has anywhere to put.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class ApplicationTypeNode extends TypeNode
{
    /**
     * @param list<TypeNode> $args The type's arguments, e.g. `T` and `U` in `map<T, U>`.
     */
    public function __construct(
        public readonly string $name,
        public readonly array $args,
        Span $location,
    ) {
        parent::__construct($location);
    }

    #[Override]
    public function __toString(): string
    {
        return TypeSyntax::application(
            $this->name,
            array_map(static fn(TypeNode $arg): string => (string)$arg, $this->args),
        );
    }
}
