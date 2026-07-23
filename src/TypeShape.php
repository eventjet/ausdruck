<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

/**
 * Which shape a {@see Type} is: at most one of a plain name's type arguments, a struct's fields, a function's
 * signature, or the type an alias stands for is ever there, so the shape holding exactly the one that applies is
 * what tells them apart -- rather than a name a caller could spell directly ({@see Type::alias('Struct', ...)}) or
 * a name a constructor happens to reuse ({@see Type::var('never')}), or a set of nullable fields that would otherwise
 * have to be kept in sync with each other and with a separate marker by convention alone.
 *
 * The behavior that differs per shape lives here too, one method per operation, rather than as an `instanceof` chain
 * repeated on {@see Type} for every operation that needs one: a new shape has to implement this interface to exist
 * at all, which the compiler enforces. The two operations that only run once a second shape is already known to be
 * concrete -- the comparison halves of {@see Type::isSubtypeOf()} and {@see Type::bind()} -- live on
 * {@see ComparableShape} instead, which every shape but {@see AliasShape} also implements; see that interface for
 * why. {@see Type} keeps the coercion between two different shapes that {@see Type::isSubtypeOf()} and
 * {@see Type::bind()} each start with -- None into Option, never, any, and so on -- since that isn't any one shape's
 * business either. {@see Type} also keeps a handful of small, private predicates that only ever ask about one
 * specific shape by name -- is this an `Option`, a `list`, a struct -- rather than dispatching on whichever shape a
 * {@see Type} happens to hold; those aren't operations that differ per shape, so they have no reason to live here.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
interface TypeShape
{
    /**
     * Every {@see Type::var()} this shape reaches, folded into $found in the order first seen -- see
     * {@see Type::collectVariables()}. Most shapes simply recurse into whatever {@see Type}s they hold, but
     * {@see Signature::collectVariables()} is the one that matters -- see {@see Signature::hasOwnBinder()}.
     *
     * @param array<string, true> $found
     * @return array<string, true>
     */
    public function collectVariables(array $found): array;

    /**
     * Every name a `fn<...>` binder declares anywhere this shape reaches, folded into $found -- see
     * {@see Type::collectBinderNames()}. Unlike {@see self::collectVariables()}, this doesn't stop at a nested
     * signature that owns its binder: it adds that binder's own names and keeps descending, since a binder further out
     * encloses them all and may not reuse any of their names -- see {@see Signature::over()}. An {@see AliasShape} is
     * the one shape that doesn't recurse: an alias prints as its name alone, so a binder inside its target never
     * surfaces in the text an enclosing binder would shadow.
     *
     * @param array<string, true> $found
     * @return array<string, true>
     */
    public function collectBinderNames(array $found): array;

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
}
