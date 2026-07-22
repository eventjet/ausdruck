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
 * at all, which the compiler enforces. {@see Type} keeps only the operations that genuinely need two shapes at
 * once -- {@see Type::isSubtypeOf()} and {@see Type::bind()} -- since neither one reduces to a method on a single
 * side.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
interface TypeShape
{
    /**
     * The name a {@see Type} built from this shape carries -- see {@see Type::$name}. Fixed for {@see FuncShape}
     * ('fn') and {@see StructShape} ('Struct'), since neither is ever named anything else; {@see ApplicationShape},
     * {@see VariableShape} and {@see AliasShape} each carry the name they were given, since it varies per instance.
     */
    public function name(): string;

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
}
