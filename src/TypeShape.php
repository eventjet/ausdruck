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
 * The behavior that differs per shape lives here too, one method per operation, rather than as a five-way
 * `instanceof` chain repeated on {@see Type} for every operation that needs one -- {@see Type::collectVariables()},
 * {@see Type::substitute()} and {@see Type::__toString()} used to each carry their own copy, every one ending in its
 * own `throw new LogicException('Unhandled type shape ...')`. A sixth shape now has to implement this interface to
 * exist at all, which the compiler enforces, rather than being one more `instanceof` branch a chain could quietly go
 * without -- falling through to that same throw only at runtime, the first time the branch was actually reached.
 * {@see Type} keeps only the operations that genuinely need two shapes at once -- {@see Type::isSubtypeOf()} and
 * {@see Type::bind()} -- since neither one reduces to a method on a single side.
 *
 * {@see Type::substitute()} is the one exception: a {@see VariableShape} substitutes to whatever its binding turned
 * out to be, which can be any shape at all, not necessarily another variable -- so it can't honor this interface's
 * `substitute(): static` contract the way every other shape does, by rebuilding its own kind with its children
 * substituted. {@see Type::substitute()} special-cases a variable before ever asking its shape, and
 * {@see VariableShape::substitute()} exists only to satisfy this interface; it is never actually called.
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
     * This shape with $bindings applied throughout -- see {@see Type::substitute()}. Every implementation but
     * {@see VariableShape::substitute()} rebuilds its own kind of shape with its children substituted the same way;
     * see this interface's own docblock for why that one is different.
     *
     * @param array<string, Type> $bindings
     */
    public function substitute(array $bindings): static;
}
