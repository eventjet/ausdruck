<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use InvalidArgumentException;
use Override;
use Stringable;

use function array_keys;
use function array_map;
use function array_slice;
use function count;
use function is_callable;
use function sprintf;

/**
 * A function type read as what it's for: a return type, a receiver, and the arguments a call passes in parentheses --
 * and, since a function type is one of the shapes a {@see Type} can hold, this class is also its own
 * {@see ComparableShape}: {@see self::collectVariables()} through {@see self::bind()} below are that interface's
 * (by way of {@see TypeShape}, which it extends), called the way every other shape's are, from {@see Type} once it
 * already holds a {@see self} rather than from a consumer of this @api class directly.
 *
 * @api
 */
final class Signature implements Stringable, ComparableShape
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
     * A derived name may not be one a `fn<...>` binder nested inside $returnType or $parameters already declares:
     * that binder is enclosed by the one derived here, so the shared name would shadow it, and shadowing an enclosing
     * variable is not allowed -- the same rule {@see Parser\TypeResolution::checkTypeVariable()} enforces for a
     * written binder, applied here to a derived one. A nested signature is opaque, so its own name never reaches
     * {@see self::freeVariables()} on its own; this asks {@see Type::collectBinderNames()} the separate question of
     * which names it does declare, and rejects a collision rather than print a signature that could never be read
     * back -- see {@see Type::__toString()} and this project's `parse(str($type)) === $type` invariant.
     *
     * @internal
     * @psalm-internal Eventjet\Ausdruck
     *
     * @param list<Type> $parameters
     * @throws InvalidArgumentException if a derived binder name shadows one a nested signature already declares.
     */
    public static function over(Type $returnType, array $parameters): self
    {
        $binder = self::freeVariables($returnType, $parameters);
        $nestedBinderNames = $returnType->collectBinderNames([]);
        foreach ($parameters as $parameter) {
            $nestedBinderNames = $parameter->collectBinderNames($nestedBinderNames);
        }
        foreach ($binder as $name) {
            if (!isset($nestedBinderNames[$name])) {
                continue;
            }
            throw new InvalidArgumentException(sprintf(
                'The binder derived for %s would introduce %s, which a nested function type already declares -- the '
                    . 'inner one would shadow the outer variable, so the type could never be read back the way it '
                    . 'prints',
                (string)new self($returnType, $parameters, $binder),
                $name,
            ));
        }
        return new self($returnType, $parameters, $binder);
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
     * $this wrapped as a {@see Type} -- the consumer-facing counterpart to {@see self::quantified()}, for placing a
     * signature somewhere a {@see Type} belongs, such as a nested parameter or return type, without going through
     * {@see Parser\Declarations} or {@see Type::alias()} to get there: `Signature::quantified($t)->toType()` is a
     * function type promoted this way, printing and re-parsing the same way one declared or aliased does. $this
     * without a binder of its own -- built directly through {@see self::over()}, or a bare {@see self::__construct()}
     * -- isn't complete yet, see {@see self::hasOwnBinder()}, and wrapped here prints the same open text
     * {@see Type::func()} does.
     */
    public function toType(): Type
    {
        return Type::of($this);
    }

    /**
     * Whether this signature has anything to quantify. Quantifying nothing is not quantifying: ∀∅.τ ≡ τ, so a
     * function with no type variables at all and a signature that defers every variable it reaches to whichever
     * signature does own one are the same type, and read identically here, both empty.
     *
     * This is the fact that lets {@see self::collectVariables()}, {@see self::substitute()}, {@see self::bind()} and
     * {@see Type::bind()} treat a self-contained generic function type -- one nested inside a list, an Option, a
     * struct field, or standing behind an alias used as a parameter -- as opaque: rank-1 polymorphism means its
     * variables are already quantified by itself, never by whatever it's found inside, so there is nothing for any of
     * those to claim or rewrite.
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

    /**
     * $this with its own binder -- see {@see self::hasOwnBinder()} -- opaque here: a self-contained generic
     * signature's variables are quantified by itself, never by whatever type it's found inside.
     *
     * @param array<string, true> $found
     * @return array<string, true>
     */
    #[Override]
    public function collectVariables(array $found): array
    {
        if ($this->hasOwnBinder()) {
            return $found;
        }
        foreach ($this->parameters as $parameter) {
            $found = $parameter->collectVariables($found);
        }
        return $this->returnType->collectVariables($found);
    }

    /**
     * This signature's own binder names, then every binder name reachable below it -- the opposite of
     * {@see self::collectVariables()}, which stops at a signature that owns its binder. Here that binder's names are
     * exactly what has to be added, and the walk continues into the parameters and return type regardless, since a
     * binder further out encloses every one of them and {@see self::over()} may not derive a name any of them uses.
     *
     * @param array<string, true> $found
     * @return array<string, true>
     */
    #[Override]
    public function collectBinderNames(array $found): array
    {
        foreach ($this->binder as $name) {
            $found[$name] = true;
        }
        foreach ($this->parameters as $parameter) {
            $found = $parameter->collectBinderNames($found);
        }
        return $this->returnType->collectBinderNames($found);
    }

    /**
     * {@see self::__toString()} itself, under the name {@see TypeShape} asks every shape for -- see {@see Type::__toString()}.
     */
    #[Override]
    public function toString(): string
    {
        return (string)$this;
    }

    /**
     * $this with its own binder left untouched -- see {@see self::hasOwnBinder()}.
     *
     * @param array<string, Type> $bindings
     */
    #[Override]
    public function substitute(array $bindings): Type
    {
        if ($this->hasOwnBinder()) {
            return Type::of($this);
        }
        return Type::of(new self(
            $this->returnType->substitute($bindings),
            array_map(static fn(Type $parameter): Type => $parameter->substitute($bindings), $this->parameters),
        ));
    }

    /**
     * Two function types agree on their own quantification before anything else: a polymorphic `fn<T>(T) -> T` and a
     * monomorphic `fn(T) -> T` over a `T` some enclosing signature owns are different types, even though structurally
     * their return type and parameters read the same -- {@see self::hasOwnBinder()} is what tells them apart. Once
     * that agrees, the return type has to accept what the other returns, and each parameter -- contravariantly, the
     * same rule an ordinary function subtyping check follows -- has to accept what it's declared to. $supertype may
     * be any shape, not just this one's own class; that mismatch is rejected the same way a quantification mismatch
     * is.
     *
     * @todo Alpha-equivalence: `fn<T>(T) -> T` and `fn<U>(U) -> U` describe the same type but this doesn't say so,
     *     since neither side's binder is renamed to line up with the other's before the parameters and return type
     *     are compared -- both would need the same name for `isSubtypeOf()` to reach true here. Left open.
     */
    #[Override]
    public function isSubtypeOf(ComparableShape $supertype): bool
    {
        if (!$supertype instanceof self) {
            return false;
        }
        if ($this->hasOwnBinder() !== $supertype->hasOwnBinder()) {
            return false;
        }
        if (!$this->returnType->isSubtypeOf($supertype->returnType)) {
            return false;
        }
        foreach ($this->parameters as $index => $parameter) {
            $otherParameter = $supertype->parameters[$index] ?? null;
            if ($otherParameter === null || !$otherParameter->isSubtypeOf($parameter)) {
                return false;
            }
        }
        return true;
    }

    /**
     * $this with its own binder fixed as far as this walk is concerned -- see {@see self::hasOwnBinder()}.
     *
     * Otherwise: the return type always binds, and a parameter binds unless $actual's own parameter in that position
     * is `any`, which is every {@see Lambda} parameter -- see {@see Type::bind()}'s own docblock for why that's the
     * rule rather than a position. Parameters are walked before the return type, the same order
     * {@see self::collectVariables()} walks a function type's own parts in, so a variable used both directly and
     * through a nested function type is decided in the same place either way. $actual's shape may be any shape, not
     * just this one's own class; there is nothing to learn if it isn't.
     *
     * @param array<string, Type> $bindings
     * @return array<string, Type>
     */
    #[Override]
    public function bind(Type $actual, array $bindings): array
    {
        $actualShape = $actual->shape();
        if (!$actualShape instanceof self) {
            return $bindings;
        }
        if ($this->hasOwnBinder()) {
            return $bindings;
        }
        foreach ($this->parameters as $index => $parameter) {
            $otherParameter = $actualShape->parameters[$index] ?? null;
            if ($otherParameter === null || $otherParameter->isAny()) {
                continue;
            }
            $bindings = $parameter->bind($otherParameter, $bindings);
        }
        return $this->returnType->bind($actualShape->returnType, $bindings);
    }

    #[Override]
    public function accepts(mixed $value): bool
    {
        // Host callable signatures come from declarations, not PHP reflection.
        return is_callable($value);
    }

    #[Override]
    public function refine(Type $actual): Type
    {
        $shape = $actual->shape();
        if (!$shape instanceof self || $this->hasOwnBinder() || $shape->hasOwnBinder()) {
            return $this->toType();
        }
        // Widening a parameter would narrow the function type, invalidating the earlier binding.
        return Type::func($this->returnType->refine($shape->returnType), $this->parameters);
    }
}
