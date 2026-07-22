<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Override;

use function array_map;
use function assert;

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

    /**
     * Two function types agree on their own quantification before anything else: a polymorphic `fn<T>(T) -> T` and a
     * monomorphic `fn(T) -> T` over a `T` some enclosing signature owns are different types, even though structurally
     * their return type and parameters read the same -- {@see Signature::hasOwnBinder()} is what tells them apart.
     * Once that agrees, the return type has to accept what the other returns, and each parameter -- contravariantly,
     * the same rule an ordinary function subtyping check follows -- has to accept what it's declared to.
     */
    #[Override]
    public function isSubtypeOfSame(TypeShape $other): bool
    {
        assert($other instanceof self);
        $signature = $this->signature;
        $otherSignature = $other->signature;
        if ($signature->hasOwnBinder() !== $otherSignature->hasOwnBinder()) {
            return false;
        }
        if (!$signature->returnType->isSubtypeOf($otherSignature->returnType)) {
            return false;
        }
        foreach ($signature->parameters as $index => $parameter) {
            $otherParameter = $otherSignature->parameters[$index] ?? null;
            if ($otherParameter === null || !$otherParameter->isSubtypeOf($parameter)) {
                return false;
            }
        }
        return true;
    }

    /**
     * A signature with its own binder is fixed as far as this walk is concerned, the same way {@see
     * self::collectVariables()} and {@see self::substitute()} both treat one -- there is nothing to learn from
     * matching into a self-contained generic signature, since none of its variables are free for the enclosing walk
     * to bind. Otherwise delegates to {@see Type::bindSignatures()}, which needs two {@see Type}-private helpers no
     * shape class can call directly.
     *
     * @param array<string, Type> $bindings
     * @return array<string, Type>
     */
    #[Override]
    public function bindSame(TypeShape $other, array $bindings): array
    {
        if ($this->signature->hasOwnBinder()) {
            return $bindings;
        }
        assert($other instanceof self);
        return Type::bindSignatures($this->signature, $other->signature, $bindings);
    }
}
