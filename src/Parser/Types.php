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
     * A name the language spells itself is one of the {@see TypeConstructor}s; anything else is a consumer's alias, or
     * nothing at all—unless a `fn<...>` binder above the node names it a type variable instead, which is
     * {@see TypeResolution}'s to decide.
     */
    public function resolve(TypeNode $node): Type|TypeError
    {
        return (new TypeResolution($this->aliases))->resolve($node);
    }
}
