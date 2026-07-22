<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Override;

use function array_map;

/**
 * A function type -- see {@see Type::func()}, {@see Type::genericFunc()} and {@see Type::nestedFunc()}. Whether the
 * {@see Signature} held here owns a binder of its own is {@see Signature::hasOwnBinder()}'s own answer, decided once
 * when the signature was built rather than guessed from where this shape ends up sitting in a {@see Type} tree: a
 * signature without one defers every variable it reaches to whichever signature does, wherever that turns out to be.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class FuncShape implements TypeShape
{
    public function __construct(
        public readonly Signature $signature,
    ) {
    }

    #[Override]
    public function name(): string
    {
        return 'fn';
    }

    /**
     * A signature with its own binder is opaque here: its variables are already quantified by itself, not free for
     * whatever this shape is nested inside to claim -- see {@see Signature::hasOwnBinder()}.
     *
     * @param array<string, true> $found
     * @return array<string, true>
     */
    #[Override]
    public function collectVariables(array $found): array
    {
        $signature = $this->signature;
        if ($signature->hasOwnBinder()) {
            return $found;
        }
        foreach ($signature->parameters as $parameter) {
            $found = $parameter->collectVariables($found);
        }
        return $signature->returnType->collectVariables($found);
    }

    #[Override]
    public function toString(): string
    {
        $signature = $this->signature;
        $params = array_map(static fn(Type $arg): string => (string)$arg, $signature->parameters);
        return TypeSyntax::func($signature->binder(), $params, (string)$signature->returnType);
    }

    /**
     * A signature with its own binder is left untouched: substituting it would rewrite variables that belong to
     * itself, not to whichever signature's own {@see Type::substitute()} call this shape is nested inside -- the
     * same rule {@see self::collectVariables()} applies.
     *
     * @param array<string, Type> $bindings
     */
    #[Override]
    public function substitute(array $bindings): Type
    {
        $signature = $this->signature;
        if ($signature->hasOwnBinder()) {
            return Type::of($this);
        }
        return Type::of(new self(new Signature(
            $signature->returnType->substitute($bindings),
            array_map(static fn(Type $parameter): Type => $parameter->substitute($bindings), $signature->parameters),
        )));
    }
}
