<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use Eventjet\Ausdruck\Type;
use InvalidArgumentException;

use function sprintf;

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
     * An alias stands for one complete type -- see {@see Type::alias()} -- so $aliases is the one place a
     * consumer-supplied {@see Type} is promoted to a name text can reference on its own, with nothing enclosing it:
     * the one boundary, alongside {@see Declarations}'s functions and variables, that has to notice a $type built
     * through {@see Type::nestedFunc()}, deferring a variable to a signature that was never going to enclose it, or
     * a bare {@see Type::var()} used standalone -- either way, {@see Type::hasFreeVariables()} true. Left uncaught,
     * `Mapper` naming such a $type would resolve every reference to it -- however many lists, Options, or struct
     * fields deep the reference itself sits -- with a variable no written `fn<...>` binder could ever declare, since
     * the text naming `Mapper` has no way to see what it hides.
     *
     * @param array<string, Type> $aliases
     *
     * @throws InvalidArgumentException if an alias reaches a type variable nothing captures.
     */
    public function __construct(private readonly array $aliases = [])
    {
        foreach ($aliases as $name => $type) {
            if (!$type->hasFreeVariables()) {
                continue;
            }
            throw new InvalidArgumentException(sprintf(
                '%s is declared as %s, which reaches a type variable nothing captures -- every function type it '
                    . 'reaches through Type::nestedFunc() needs its own binder instead, via Type::func() or '
                    . 'Type::genericFunc()',
                $name,
                $type,
            ));
        }
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
