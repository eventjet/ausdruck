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
     * @param list<string> $binder The names this signature quantifies for itself, empty if none -- see
     *     {@see self::hasOwnBinder()} for what an empty binder means. {@see Type::func()} never passes one, since it
     *     builds structure only; {@see self::over()} derives one from what $returnType and $parameters reach, for a
     *     signature that has no binder of its own to keep, and {@see Parser\TypeResolution::resolveSignature()} --
     *     the one place a binder is written by hand -- passes the author's own order straight to this constructor
     *     instead, since {@see self::quantified()} keeps a binder that is already there rather than deriving one.
     *
     * @internal
     * @psalm-internal Eventjet\Ausdruck
     */
    public function __construct(
        public readonly Type $returnType,
        public readonly array $parameters = [],
        private readonly array $binder = [],
    ) {
    }

    /**
     * Every {@see Type::var()} $return and $parameters reach, in the order first seen -- receiver first, then the
     * rest of the parameters, then the return type -- without a duplicate. The one walk {@see self::quantified()}
     * derives a signature's own binder from, and {@see Parser\TypeResolution::resolveSignature()} checks a written
     * one's unused names against, rather than each repeating it on its own.
     *
     * @internal
     * @psalm-internal Eventjet\Ausdruck
     *
     * @param list<Type> $parameters
     * @return list<string>
     */
    public static function freeVariables(Type $return, array $parameters): array
    {
        $found = [];
        foreach ($parameters as $parameter) {
            $found = $parameter->collectVariables($found);
        }
        $found = $return->collectVariables($found);
        return array_keys($found);
    }

    /**
     * $funcType promoted to a complete, self-contained signature -- the one it already holds
     * ({@see Type::asFunction()}) -- with its own binder settled: kept as-is if it already has one of its own
     * (a written `fn<...>`, resolved through {@see Parser\TypeResolution::resolveSignature()}, whose author's order
     * this must not disturb), or derived from what its return type and parameters actually reach otherwise
     * ({@see self::over()}). Either way there is nothing left here to reject the way a written binder is checked at
     * parse time -- a derived binder can't miss a name or include a spurious one, by construction.
     *
     * This is the one seam a function type is promoted through, from "some function type, built through
     * {@see Type::func()} without committing to who quantifies it" to "the outermost signature of a declaration or
     * an alias target" -- see {@see Parser\Declarations} and {@see Type::alias()}, its two callers. Null if
     * $funcType isn't a function type at all, which each of those turns into its own, differently-worded rejection
     * instead of asking this a separate question first.
     */
    public static function quantified(Type $funcType): self|null
    {
        $signature = $funcType->asFunction();
        if ($signature === null) {
            return null;
        }
        return $signature->hasOwnBinder() ? $signature : self::over($signature->returnType, $signature->parameters);
    }

    /**
     * A signature built fresh from $returnType and $parameters, with its own binder derived from what they reach --
     * see {@see self::freeVariables()}. The door for a signature that has no binder of its own to keep:
     * {@see self::quantified()} is the one caller that has to choose between this and keeping a binder already
     * there, and {@see BuiltinFunctions::signature()} has nothing else to give a built-in's own top-level signature
     * in the first place.
     *
     * @internal
     * @psalm-internal Eventjet\Ausdruck
     *
     * @param list<Type> $parameters
     */
    public static function over(Type $returnType, array $parameters): self
    {
        return new self($returnType, $parameters, self::freeVariables($returnType, $parameters));
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
     * $this wrapped as a {@see Type} -- the door {@see Parser\TypeResolution::resolveSignature()} uses to hand a
     * resolved `fn<...>` node back as the {@see Type} it has to answer, since {@see Type::of()} and {@see FuncShape}
     * are both {@see Type}'s own internals, not the parser's, and {@see Parser\TypeResolution::resolveSignature()}
     * isn't a {@see TypeShape::substitute()} implementation either -- the only other place {@see Type::of()} is
     * called from.
     *
     * @internal
     * @psalm-internal Eventjet\Ausdruck
     */
    public function toType(): Type
    {
        return Type::of(new FuncShape($this));
    }

    /**
     * Whether this signature has anything to quantify. Quantifying nothing is not quantifying: ∀∅.τ ≡ τ, so a
     * function with no type variables at all and a signature that defers every variable it reaches to whichever
     * signature does own one are the same type, and read identically here, both empty.
     *
     * This is the fact that lets {@see FuncShape::collectVariables()}, {@see FuncShape::substitute()},
     * {@see FuncShape::bind()} and {@see Type::bind()} treat a self-contained generic function type -- one nested
     * inside a list, an Option, a struct field, or standing behind an alias used as a parameter -- as opaque: rank-1
     * polymorphism means its variables are already quantified by itself, never by whatever it's found inside, so
     * there is nothing for any of those to claim or rewrite.
     *
     * @internal
     * @psalm-internal Eventjet\Ausdruck
     */
    public function hasOwnBinder(): bool
    {
        return $this->binder !== [];
    }

    /**
     * The names this signature binds, without a duplicate, since one variable used twice is still one name. In the
     * order {@see self::quantified()} derived them in -- first seen walking $parameters then $returnType, receiver
     * first -- for a derived signature, or the order {@see Parser\TypeResolution::resolveSignature()} was given for
     * one written by hand, which needn't be the order a left-to-right walk would find them in. Empty for a signature
     * that defers every variable it reaches to whichever signature does own one; see {@see self::hasOwnBinder()}.
     *
     * @return list<string>
     */
    public function binder(): array
    {
        return $this->binder;
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
     * A signature with no binder of its own reaches no variable for a call to decide -- see {@see self::hasOwnBinder()}
     * -- so there is nothing here for it either: $this is handed back unchanged rather than rebuilt from a walk that
     * could only ever bind nothing and substitute nothing, which every monomorphic call, like `substr`'s, is.
     *
     * @param list<Type> $argumentTypes
     */
    public function instantiateForCall(Type $receiver, array $argumentTypes): self
    {
        if (!$this->hasOwnBinder()) {
            return $this;
        }
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
