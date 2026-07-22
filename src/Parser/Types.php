<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use Eventjet\Ausdruck\Type;
use InvalidArgumentException;

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
     * @var array<string, Type>
     */
    private readonly array $aliases;

    /**
     * An alias stands for one complete type -- see {@see Type::alias()}, which is what actually promotes every entry
     * here: each is wrapped through it exactly the way a consumer calling {@see Type::alias()} directly would wrap
     * one, so a function type is quantified and anything else that reaches a type variable nothing captures is
     * rejected with {@see Type::alias()}'s own message, rather than this constructor keeping a second copy of that
     * check.
     *
     * @param array<string, Type> $aliases
     *
     * @throws InvalidArgumentException {@see Type::alias()}
     */
    public function __construct(array $aliases = [])
    {
        $wrapped = [];
        foreach ($aliases as $name => $type) {
            $wrapped[$name] = Type::alias($name, $type);
        }
        $this->aliases = $wrapped;
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
