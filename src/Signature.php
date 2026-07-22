<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Override;
use Stringable;

use function array_keys;
use function array_map;
use function array_slice;
use function count;

/**
 * A function type read as what it's for: a return type, a receiver, and the arguments a call passes in parentheses.
 *
 * @api
 */
final class Signature implements Stringable
{
    /**
     * @param list<Type> $parameters The types the PHP callable receives, in order, receiver first -- `substr` is
     *     declared as `fn(string, int, int) -> string` and called as `foo:string.substr:string(0, 3)`, so its
     *     parameters are `[string, int, int]` and `foo` is checked against the first of them; see
     *     {@see self::receiverType()} and {@see self::argumentTypes()}.
     * @param list<string>|null $ownBinder The names this signature quantifies for itself, or null if it doesn't own
     *     one at all -- see {@see self::hasOwnBinder()}. Only {@see Type::genericFunc()} ever passes a non-null
     *     value here; every other caller leaves it null, deferring every variable this signature reaches to
     *     whichever signature does own one.
     */
    public function __construct(
        public readonly Type $returnType,
        public readonly array $parameters = [],
        private readonly array|null $ownBinder = null,
    ) {
    }

    /**
     * This signature the way it would be written -- e.g. `fn<T>(list<T>) -> T` -- with {@see self::binder()}'s
     * names in front, even when there are none to write.
     */
    #[Override]
    public function __toString(): string
    {
        return TypeSyntax::func(
            $this->binder(),
            array_map(static fn(Type $parameter): string => (string)$parameter, $this->parameters),
            (string)$this->returnType,
        );
    }

    /**
     * Whether this signature owns a binder of its own, decided once at construction rather than guessed from where
     * this signature sits in a {@see Type} tree. A signature built through {@see Type::genericFunc()} -- and,
     * through it, one {@see Parser\TypeResolution::resolveSignature()} resolves outside another one's own
     * parameters or return type -- owns one, even an empty one; a signature built through {@see Type::func()}, or
     * nested inside another written signature, never does, and defers every variable it reaches to whichever
     * signature does.
     *
     * This is what lets {@see FuncShape::collectVariables()}, {@see FuncShape::substitute()} and {@see Type::bind()}
     * tell a self-contained generic function type -- one nested inside a list, an Option, a struct field, or
     * standing behind an alias used as a parameter -- from one whose variables genuinely belong to whatever encloses
     * it: the former is opaque to all three, since rank-1 polymorphism means its variables are already quantified by
     * itself, never by whatever it's found inside.
     *
     * @internal
     * @psalm-internal Eventjet\Ausdruck
     */
    public function hasOwnBinder(): bool
    {
        return $this->ownBinder !== null;
    }

    /**
     * The names this signature binds: what it was declared with, if {@see self::hasOwnBinder()} says there is one,
     * or else the names first found walking $parameters then $returnType -- receiver first, then the rest of the
     * parameters, then the return type, the same order {@see self::instantiateForCall()} decides them in -- without
     * a duplicate, since one variable used twice is still one name.
     *
     * The derived fallback is what a signature built through {@see Type::func()} relies on: it never owns a binder
     * of its own, so reading one back through {@see Type::asFunction()} derives it fresh from wherever the
     * variables it reaches turned out to be.
     *
     * @return list<string>
     */
    public function binder(): array
    {
        if ($this->ownBinder !== null) {
            return $this->ownBinder;
        }
        $found = [];
        foreach ($this->parameters as $parameter) {
            $found = $parameter->collectVariables($found);
        }
        $found = $this->returnType->collectVariables($found);
        return array_keys($found);
    }

    /**
     * The type a receiver function is called on: `substr` is declared as `fn(string, int, int) -> string` and called as
     * `foo:string.substr:string(0, 3)`, so its receiver type is string. Null if the function declares no parameters at
     * all, which is what makes it unusable as a receiver function.
     */
    public function receiverType(): Type|null
    {
        return $this->parameters[0] ?? null;
    }

    /**
     * The types of the arguments a call passes in parentheses, which are the parameters the receiver doesn't take up.
     *
     * @return list<Type>
     */
    public function argumentTypes(): array
    {
        return array_slice($this->parameters, 1);
    }

    /**
     * This signature with its type variables resolved against the receiver and argument types a call applies it to:
     * the signature the call is actually checked against, with no variables left in it.
     *
     * The parameters -- receiver first, then the arguments a call passes in parentheses -- are walked in order and a
     * variable keeps the first type that lands on it, so the receiver decides `T` for `fn(list<T>, T) -> bool` before
     * the argument is looked at. A variable no parameter reaches becomes `any`—nothing constrained it, so nothing
     * about the call should be rejected on its account, and an argument that isn't there is reported as the missing
     * argument it is.
     *
     * A lambda argument is harmless regardless of where its parameter falls in that order: a lambda's own parameters
     * are always typed `any`, since a lambda never knows its parameter types ahead of the call it's an argument to, and
     * {@see Type::bind()} doesn't let a parameter typed `any` decide a variable that sits in a function's own parameter
     * position, walked first or not.
     *
     * Nothing here is an error. Where a variable's binding and a later parameter disagree, the substituted signature
     * says what was expected and {@see Expr::call()}'s receiver and argument checks report it, pointing at the
     * expression that's wrong.
     *
     * @param list<Type> $argumentTypes
     */
    public function instantiateForCall(Type $receiver, array $argumentTypes): self
    {
        $arguments = [$receiver, ...$argumentTypes];
        $bindings = [];
        // Only the parameters an argument faces have anything to say. A call with the wrong number of arguments is
        // still instantiated, from the ones it does have, so that Expr::call() can report the count against a
        // signature that reads the way the rest of the call decided it should.
        foreach (array_slice($this->parameters, 0, count($arguments)) as $index => $parameter) {
            $bindings = $parameter->bind($arguments[$index], $bindings);
        }
        return new self(
            $this->returnType->substitute($bindings),
            array_map(static fn(Type $parameter): Type => $parameter->substitute($bindings), $this->parameters),
        );
    }
}
