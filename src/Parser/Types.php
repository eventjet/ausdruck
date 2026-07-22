<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use Eventjet\Ausdruck\Type;
use InvalidArgumentException;

use function array_key_exists;

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
     *
     * $node itself is what keeps this @internal despite {@see self} being @api: a {@see TypeNode} is only ever built
     * by {@see TypeParser}, which is @internal too, so no consumer outside this package could ever construct one to
     * call this with in the first place. Scoped to {@see Eventjet\Ausdruck}, not just this namespace, the same as
     * the rest of the type system's own internals -- this test suite is the one thing throughout that namespace that
     * does build a {@see TypeNode} itself, through {@see TypeParser} directly, to exercise resolution with.
     *
     * @internal
     * @psalm-internal Eventjet\Ausdruck
     */
    public function resolve(TypeNode $node): Type|TypeError
    {
        return (new TypeResolution($this->aliases))->resolve($node);
    }

    /**
     * Whether $name is one of the aliases this scope resolves a reference to -- the same question
     * {@see TypeResolution::checkTypeVariable()} asks of a written `fn<...>` binder's own names, from outside the one
     * door that builds a {@see TypeResolution} in the first place. {@see Declarations::__construct()} is the other
     * place a name has to clear that same restriction: the binder {@see \Eventjet\Ausdruck\Signature::quantified()}
     * derives for a builder-constructed function declaration is never written text a parser could reject, so this is
     * what it asks instead.
     *
     * @internal
     * @psalm-internal Eventjet\Ausdruck\Parser
     */
    public function hasAlias(string $name): bool
    {
        return array_key_exists($name, $this->aliases);
    }
}
