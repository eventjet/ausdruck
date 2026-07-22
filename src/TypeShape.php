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
 * at all, which the compiler enforces. {@see Type} keeps the coercion between two different shapes that
 * {@see Type::isSubtypeOf()} and {@see Type::bind()} each start with -- None into Option, never, any, and so on --
 * since that isn't any one shape's business either; both then delegate the rest of their work, once a shape
 * comparison is the only question left, to {@see self::isSubtypeOf()} and {@see self::bind()} below. {@see
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
     * Whether $this is a subtype of $other, called from {@see Type::isSubtypeOf()} once both sides are already known
     * to be concrete shapes -- aliases seen through, the None/any/Option/never/map coercions all handled -- so all
     * that's left is this one comparison. $other is not necessarily $this's own class: nothing upstream of this call
     * gates on the two classes matching, so each implementation below checks `$other instanceof self` itself and
     * answers false on a mismatch, the same way a name or field mismatch is answered, rather than that being asserted
     * by a caller. {@see AliasShape} answers false unconditionally: {@see Type::isSubtypeOf()} canonicalizes both
     * sides -- seeing through every alias -- before it ever asks a shape this question, so a {@see AliasShape} is
     * never $this here in practice, but nothing needs that to be true for its answer to still be correct.
     */
    public function isSubtypeOf(self $other): bool;

    /**
     * What $other, matched structurally against $this, teaches about the variables $this reaches -- {@see
     * Type::bind()}'s shape-comparison half, called on the same terms {@see self::isSubtypeOf()} is, with the same
     * widened parameter type and the same "check it yourself" rule for a class mismatch. {@see VariableShape} answers
     * a different question before ever reaching this comparison -- see {@see Type::bind()} -- and {@see AliasShape}
     * is excluded from it the same way it is from {@see self::isSubtypeOf()}; both still implement this rather than
     * asserting unreachability, answering $bindings unchanged, since there is nothing to learn from either one either
     * way.
     *
     * @param array<string, Type> $bindings
     * @return array<string, Type>
     */
    public function bind(self $other, array $bindings): array;
}
