<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

/**
 * Which shape a {@see Type} is: at most one of a plain name's type arguments, a struct's fields, a function's
 * signature, or the type an alias stands for is ever there, so the shape holding exactly the one that applies is
 * what tells them apart -- rather than a name a caller could spell directly ({@see Type::alias('Struct', ...)}) or
 * a name a constructor happens to reuse ({@see Type::var('fn')}), or a set of nullable fields that would otherwise
 * have to be kept in sync with each other and with a separate marker by convention alone.
 *
 * The behavior that differs per shape lives here too, one method per operation, rather than as an `instanceof` chain
 * repeated on {@see Type} for every operation that needs one: a new shape has to implement this interface to exist
 * at all, which the compiler enforces. {@see Type} keeps the coercion between two different shapes that
 * {@see Type::isSubtypeOf()} and {@see Type::bind()} each start with -- None into Option, never, any, and so on --
 * since that isn't any one shape's business either; both then delegate the rest of their work, once a same-shape
 * comparison is the only question left, to {@see self::isSubtypeOfSame()} and {@see self::bindSame()} below. {@see
 * Type} also keeps a handful of small, private predicates that only ever ask about one specific shape by name --
 * is this an `Option`, a `list`, a struct -- rather than dispatching on whichever shape a {@see Type} happens to
 * hold; those aren't operations that differ per shape, so they have no reason to live here.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
interface TypeShape
{
    /**
     * Every {@see Type::var()} this shape reaches, folded into $found in the order first seen -- see
     * {@see Type::collectVariables()}. Most shapes simply recurse into whatever {@see Type}s they hold, but
     * {@see FuncShape::collectVariables()} is the one that matters: a signature with its own binder is opaque to
     * this walk, since its variables are already quantified by itself, not free for whatever is walking this shape
     * to claim -- see {@see Signature::hasOwnBinder()}.
     *
     * @param array<string, true> $found
     * @return array<string, true>
     */
    public function collectVariables(array $found): array;

    /**
     * This shape's own syntax, in the grammar {@see TypeSyntax} spells -- see {@see Type::__toString()}.
     */
    public function toString(): string;

    /**
     * This shape with $bindings applied throughout, as a {@see Type} rather than another {@see self} -- see
     * {@see Type::substitute()}. Every implementation but {@see VariableShape::substitute()} rebuilds its own kind
     * of shape with its children substituted and hands it back through {@see Type::of()}; a variable is the one
     * exception, since what it substitutes to can be any shape at all, not necessarily another variable.
     *
     * @param array<string, Type> $bindings
     */
    public function substitute(array $bindings): Type;

    /**
     * Whether $this is a subtype of $other, once {@see Type::isSubtypeOf()} has already established, from both
     * sides' own classes, that there is a same-shape comparison to make at all -- called only then, so $other is
     * always $this's own concrete class in practice, even though the parameter is typed as the wider {@see TypeShape}
     * here: PHP requires every implementation of one interface method to accept the same parameter type, not a
     * narrower one, so each implementation below asserts the narrower type its own docblock promises before reading
     * $other's properties, the same way {@see Type::asFunction()}'s own callers assert a nullable non-null.
     * {@see AliasShape} never implements this meaningfully: {@see Type::isSubtypeOf()} canonicalizes both sides
     * before it ever asks a shape this question, so an alias is never one of the two being compared.
     */
    public function isSubtypeOfSame(self $other): bool;

    /**
     * What $other, matched structurally against $this, teaches about the variables $this reaches -- {@see
     * Type::bind()}'s same-shape half, called on the same terms {@see self::isSubtypeOfSame()} is, with the same
     * widened parameter type. A {@see VariableShape} answers a different question before ever reaching a same-shape
     * comparison -- see {@see Type::bind()} -- so neither it nor {@see AliasShape}, excluded the same way it is from
     * {@see self::isSubtypeOfSame()}, ever has $this called for real.
     *
     * @param array<string, Type> $bindings
     * @return array<string, Type>
     */
    public function bindSame(self $other, array $bindings): array;
}
