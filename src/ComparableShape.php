<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

/**
 * Operations on canonical shapes. Type resolves aliases before dispatching here, so AliasShape does not implement
 * this interface. Each shape owns runtime validation and the structural parts of comparison, binding, and refinement.
 * Type keeps cross-shape rules such as bottom types and canonicalization.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
interface ComparableShape extends TypeShape
{
    /** Whether a runtime value satisfies this shape, including its nested fields. */
    public function accepts(mixed $value): bool;

    /** Fill bottom types using a canonical actual type, preserving concrete bindings. */
    public function refine(Type $actual): Type;

    /**
     * Whether $this -- the actual value's shape -- is a subtype of $supertype -- the declared one it's checked
     * against -- called from {@see Type::isSubtypeOf()} once both sides are already known to be concrete shapes --
     * aliases seen through, the bottom/any/empty-map coercions all handled. $supertype may be any shape, not
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
