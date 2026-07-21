<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use Eventjet\Ausdruck\Type;

/**
 * The public entry point for resolving a {@see TypeNode} against a set of aliases. Resolution itself is
 * {@see TypeResolution}'s job: a `fn<...>` binder adds type variables to the scope a node is resolved in, and that
 * scope is a second thing to carry alongside the aliases, not a parameter to thread through every method here.
 *
 * @api
 */
final class Types
{
    /**
     * @param array<string, Type> $aliases
     */
    public function __construct(private readonly array $aliases = [])
    {
    }

    /**
     * Delegates outright: resolving $node is entirely {@see TypeResolution}'s job, started here with an empty type
     * variable scope because a fresh, top-level $node has no enclosing `fn<...>` binder to have added any. This method
     * exists only so a caller resolving against a set of aliases -- what {@see Types} is @api for -- gets to do that
     * without also being handed {@see TypeResolution}'s own, @internal, binder-scope parameter.
     */
    public function resolve(TypeNode $node): Type|TypeError
    {
        return (new TypeResolution($this->aliases))->resolve($node);
    }
}
