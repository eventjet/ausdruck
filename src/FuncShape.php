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

    /**
     * True outright if {@see Signature::hasOwnBinder()} does; otherwise folds {@see TypeShape::hasNestedBinder()}
     * over the parameters and the return type, since a binder-less function type -- one built through
     * {@see Type::nestedFunc()} -- can still carry one deeper inside it. See {@see TypeShape::hasNestedBinder()} for
     * what this asks.
     */
    #[Override]
    public function hasNestedBinder(): bool
    {
        $signature = $this->signature;
        if ($signature->hasOwnBinder()) {
            return true;
        }
        foreach ($signature->parameters as $parameter) {
            if ($parameter->hasNestedBinder()) {
                return true;
            }
        }
        return $signature->returnType->hasNestedBinder();
    }

    #[Override]
    public function toString(): string
    {
        return (string)$this->signature;
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
     * the same rule an ordinary function subtyping check follows -- has to accept what it's declared to. $other isn't
     * necessarily a function type at all: {@see Type::isSubtypeOf()} no longer checks that before asking, so a shape
     * mismatch is rejected here, the same way a quantification mismatch is.
     */
    #[Override]
    public function isSubtypeOfSame(TypeShape $other): bool
    {
        if (!$other instanceof self) {
            return false;
        }
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
     * to bind.
     *
     * Otherwise: the return type always binds, and a parameter binds unless $other's own parameter in that position
     * is `any`, which is every {@see Lambda} parameter -- see {@see Type::bind()}'s own docblock for why that's the
     * rule rather than a position. Parameters are walked before the return type, the same order
     * {@see self::collectVariables()} walks a function type's own parts in, so a variable used both directly and
     * through a nested function type is decided in the same place either way. $other isn't necessarily a function
     * type either, the same reason {@see self::isSubtypeOfSame()} checks it, and there is nothing to learn from one
     * if it isn't.
     *
     * @param array<string, Type> $bindings
     * @return array<string, Type>
     */
    #[Override]
    public function bindSame(TypeShape $other, array $bindings): array
    {
        $signature = $this->signature;
        if ($signature->hasOwnBinder() || !$other instanceof self) {
            return $bindings;
        }
        $otherSignature = $other->signature;
        foreach ($signature->parameters as $index => $parameter) {
            $otherParameter = $otherSignature->parameters[$index] ?? null;
            if ($otherParameter === null || $otherParameter->isAny()) {
                continue;
            }
            $bindings = $parameter->bind($otherParameter, $bindings);
        }
        return $signature->returnType->bind($otherSignature->returnType, $bindings);
    }
}
