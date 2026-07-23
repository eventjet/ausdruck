<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

/**
 * The two {@see TypeShape} operations that only run once a second shape is already known to be concrete on both
 * sides: {@see Type::isSubtypeOf()} and {@see Type::bind()} each canonicalize both of their own arguments first --
 * seeing through every alias -- before either ever compares shapes, so by the time either reaches
 * {@see self::isSubtypeOf()} or {@see self::bind()} below, an {@see AliasShape} can never be on either side of the
 * call. {@see AliasShape} is therefore the one {@see TypeShape} that doesn't implement this interface at all, rather
 * than implementing it with a throw for a call that can never happen -- the four shapes that can be compared,
 * {@see ApplicationShape}, {@see StructShape}, {@see Signature} and {@see VariableShape}, are the only ones that do.
 * {@see Type}'s two dispatch sites narrow to this interface with an `assert()` right after they call
 * {@see Type::canonical()}, the same way the rest of this codebase asserts a precondition a caller already
 * guarantees rather than re-deriving it.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
interface ComparableShape extends TypeShape
{
    /**
     * Whether $this -- the actual value's shape -- is a subtype of $supertype -- the declared one it's checked
     * against -- called from {@see Type::isSubtypeOf()} once both sides are already known to be concrete shapes --
     * aliases seen through, the None/any/Option/never/map coercions all handled. $supertype may be any shape, not
     * necessarily this one's own class, so telling that mismatch apart from a real comparison is this method's own
     * first step rather than a gate {@see Type} runs before calling it -- most implementations reject it before
     * recovering $supertype's own class for the real comparison that follows: name, args, signature, or fields,
     * whichever is this shape's own.
     */
    public function isSubtypeOf(self $supertype): bool;

    /**
     * What $actual, matched structurally against $this, teaches about the variables $this reaches -- {@see
     * Type::bind()}'s shape-comparison half, called on the same terms {@see self::isSubtypeOf()} is, with the same
     * mismatch check this side of it. $actual is a {@see Type}, not a {@see self}, unlike {@see self::isSubtypeOf()}'s
     * $other: {@see VariableShape::bind()} needs to record it whole, as the type its own variable is bound to, rather
     * than a shape to compare against -- every other implementation reaches the shape it does need to compare through
     * {@see Type::shape()}.
     *
     * @param array<string, Type> $bindings
     * @return array<string, Type>
     */
    public function bind(Type $actual, array $bindings): array;
}
