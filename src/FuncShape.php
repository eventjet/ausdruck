<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Override;

use function array_map;

/**
 * A function type -- see {@see Type::func()}. Whether the {@see Signature} held here owns a binder of its own, and
 * what that means for the methods below, is {@see Signature::hasOwnBinder()}'s own answer to give, decided once by
 * {@see Signature::quantified()} or {@see Signature::written()} rather than guessed from where this shape ends up
 * sitting in a {@see Type} tree.
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
     * A signature with its own binder is opaque here -- see {@see Signature::hasOwnBinder()}.
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
        return (string)$this->signature;
    }

    /**
     * A signature with its own binder is left untouched -- see {@see Signature::hasOwnBinder()}.
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
     * necessarily a function type at all, so a shape mismatch is rejected here, the same way a quantification
     * mismatch is.
     *
     * @todo Alpha-equivalence: `fn<T>(T) -> T` and `fn<U>(U) -> U` describe the same type but this doesn't say so,
     *     since neither side's binder is renamed to line up with the other's before the parameters and return type
     *     are compared -- both would need the same name for `isSubtypeOf()` to reach true here. Left open.
     */
    #[Override]
    public function isSubtypeOf(TypeShape $other): bool
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
     * A signature with its own binder is fixed as far as this walk is concerned -- see {@see Signature::hasOwnBinder()}.
     *
     * Otherwise: the return type always binds, and a parameter binds unless $other's own parameter in that position
     * is `any`, which is every {@see Lambda} parameter -- see {@see Type::bind()}'s own docblock for why that's the
     * rule rather than a position. Parameters are walked before the return type, the same order
     * {@see self::collectVariables()} walks a function type's own parts in, so a variable used both directly and
     * through a nested function type is decided in the same place either way. $other isn't necessarily a function
     * type either, the same reason {@see self::isSubtypeOf()} checks it, and there is nothing to learn from one
     * if it isn't.
     *
     * @param array<string, Type> $bindings
     * @return array<string, Type>
     */
    #[Override]
    public function bind(TypeShape $other, array $bindings): array
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
