<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use InvalidArgumentException;
use Override;
use Stringable;

use function array_fill_keys;
use function array_is_list;
use function array_key_exists;
use function array_key_first;
use function array_map;
use function get_object_vars;
use function gettype;
use function is_array;
use function is_string;
use function sprintf;

/**
 * @api
 */
final class Type implements Stringable
{
    private function __construct(
        private readonly TypeShape $shape,
    ) {
    }

    /**
     * Wraps an already-built {@see TypeShape} as a {@see self} -- the door {@see TypeShape::substitute()}
     * implementations use to hand one back, since {@see self::__construct()} is private and no shape class can call
     * it directly.
     *
     * @internal
     * @psalm-internal Eventjet\Ausdruck
     */
    public static function of(TypeShape $shape): self
    {
        return new self($shape);
    }

    public static function string(): self
    {
        return new self(new ApplicationShape('string'));
    }

    public static function int(): self
    {
        return new self(new ApplicationShape('int'));
    }

    public static function float(): self
    {
        return new self(new ApplicationShape('float'));
    }

    public static function bool(): self
    {
        return new self(new ApplicationShape('bool'));
    }

    public static function listOf(self $item): self
    {
        return new self(new ApplicationShape('list', [$item]));
    }

    public static function mapOf(self $keys, self $values): self
    {
        return new self(new ApplicationShape('map', [$keys, $values]));
    }

    /**
     * An alias is a name for one complete type, and takes no arguments of its own: the arguments of the type it stands
     * for belong to that type, not to the name. Copying them here would make the alias print as `Bag<string>`, which
     * reads back as arguments applied to Bag and is rejected. Everything that needs to see through the name calls
     * {@see self::canonical()}.
     *
     * $name is never checked against what $type itself is a shape of: an alias's own shape is always an
     * {@see AliasShape}, since it prints as its own name ({@see self::toString()}) rather than as whatever it stands
     * for -- so `self::alias('Struct', ...)` prints as `Struct`, reachable through neither the check that looks for a
     * {@see StructShape} nor the one that looks for a {@see FuncShape}.
     *
     * $name is rejected when {@see TypeConstructor::isReservedName()} says so -- every name a
     * {@see TypeConstructor} case already spells, plus `fn`: {@see self::__toString()} turns this alias back into
     * written syntax, and this project treats `parse(str($type)) === $type` as a hard invariant, so a name the parser
     * could never read back as an alias reference the way it was built isn't a name this door hands out either.
     *
     * @throws InvalidArgumentException if $name is reserved.
     */
    public static function alias(string $name, self $type): self
    {
        if (TypeConstructor::isReservedName($name)) {
            throw new InvalidArgumentException(TypeConstructor::reservedNameMessage($name, 'an alias'));
        }
        return new self(new AliasShape($name, $type));
    }

    public static function any(): self
    {
        return new self(new ApplicationShape('any'));
    }

    /**
     * A type variable: the placeholder a generic function's signature writes where the concrete type is decided by the
     * call site rather than by the declaration. `head` is declared as `fn<T>(list<T>) -> Option<T>`, and a call on a
     * `list<string>` is checked against `fn(list<string>) -> Option<string>`; see {@see Signature::instantiateForCall()}.
     *
     * Variables are quantified at the top of the signature they appear in, so there is no binder to build here and two
     * variables of the same name in one signature are the same variable -- {@see self::func()} and
     * {@see self::genericFunc()} are the two doors that store a signature's own binder, the former deriving one from
     * where the variable turns out to be used once the whole signature exists to look at, the latter taking one
     * written by hand and checking it against the same thing; {@see self::nestedFunc()} is the third, for a function
     * type that owns no binder of its own at all.
     *
     * $name is rejected when {@see TypeConstructor::isReservedName()} says so -- the same names
     * {@see Parser\TypeResolution::checkTypeVariable()} rejects when a signature is written as a type string, because
     * within that signature's own text the bare word could no longer mean the type once it also names the variable.
     * `Type::var('int')` and `Type::int()` are two distinct, unambiguous PHP calls, but {@see self::__toString()}
     * turns this variable back into the written syntax the same parser reads, and this project treats
     * `parse(str($type)) === $type` as a hard invariant: a signature built with `Type::var('int')` would print as
     * `int` and read back as the type, not the variable, so the mismatch is rejected here instead of surfacing later
     * as a silent misparse.
     *
     * @throws InvalidArgumentException if $name is reserved.
     */
    public static function var(string $name): self
    {
        if (TypeConstructor::isReservedName($name)) {
            throw new InvalidArgumentException(TypeConstructor::reservedNameMessage($name));
        }
        return new self(new VariableShape($name));
    }

    /**
     * A function type keeps its return type, its parameters and its own `fn<...>` binder in one {@see Signature}, which
     * is also the only thing {@see self::asFunction()} ever returns: nothing outside this class reads a function type
     * any other way.
     *
     * This is the door for a caller declaring a complete, self-contained signature -- the outermost one of a
     * declaration, never one nested inside another's own parameters or return type: it commits to that by deriving
     * $return and $parameters' own binder ({@see Signature::freeVariables()}) and handing it to
     * {@see self::genericFunc()}, which is where the binder is actually stored and checked -- the derived one always
     * agrees with what it reaches, by construction, so nothing here is ever rejected on that account.
     * {@see self::nestedFunc()} is the other door, for a function type that IS that nested position -- a generic
     * function type used as a fixed parameter or a list's element type, or a lambda parameter like `filter`'s or
     * `map`'s own, whose variables belong to whatever encloses it rather than to itself. Nothing about $return or
     * $parameters tells the two positions apart; the caller says which one is meant by picking the constructor, the
     * same choice {@see Parser\TypeResolution::resolveSignature()} makes from where in the source a signature was
     * written -- except where $return or a parameter already answers that question for itself, by already being a
     * function type with a non-empty binder of its own: see {@see self::rejectNestedBinder()}.
     *
     * @param list<Type> $parameters The types the PHP callable receives, in order. A function that is called as a
     *     receiver function -- `foo:string.substr:string(0, 3)` -- receives the expression it's called on as the first
     *     of them; see {@see Signature::receiverType()} and {@see Signature::argumentTypes()}.
     *
     * @throws InvalidArgumentException {@see self::rejectNestedBinder()}
     */
    public static function func(self $return, array $parameters = []): self
    {
        return self::genericFunc(Signature::freeVariables($return, $parameters), $return, $parameters);
    }

    /**
     * A function type that defers every variable it reaches to whichever signature encloses it, because it IS that
     * enclosed position -- see {@see self::func()} for the two positions and how they're told apart. Every signature
     * {@see Parser\TypeResolution::resolveSignature()} resolves inside another one's own parameters or return type
     * is built through this door; a consumer's own generic higher-order function -- one whose parameter is itself a
     * generic function type, the way `map`'s lambda parameter is -- has to build that parameter through this door
     * too, since {@see self::func()} would otherwise commit it to a binder of its own that then shadows the
     * enclosing one instead of sharing its variables with it.
     *
     * @param list<Type> $parameters
     */
    public static function nestedFunc(self $return, array $parameters = []): self
    {
        return new self(new FuncShape(new Signature($return, $parameters)));
    }

    /**
     * A function type that owns its own binder, the same commitment {@see self::func()} makes -- indeed
     * {@see self::func()} is built on top of this door, handing it the binder it derived -- but here the binder is
     * given rather than derived: this is the door for a caller that wants it checked against the variables $return
     * and $parameters actually reach, both ways -- a name declared here that isn't reachable is dead, and one
     * reachable that isn't declared would silently turn the missing name into `any` at the call site instead of
     * being decided by the call, so both directions are rejected rather than left to fail later. A name declared
     * twice is rejected too, the same way
     * {@see Parser\TypeResolution::checkTypeVariable()} rejects one written twice in a `fn<...>` binder: two
     * parameters answering to the same name would make one of them unreachable, and which one never has an intended
     * answer.
     *
     * @param list<string> $binder
     * @param list<Type> $parameters
     *
     * @throws InvalidArgumentException if $binder names the same variable twice, disagrees with the variables
     *     $return and $parameters actually reach in either direction, or {@see self::rejectNestedBinder()}.
     */
    public static function genericFunc(array $binder, self $return, array $parameters = []): self
    {
        self::rejectNestedBinder($return);
        foreach ($parameters as $parameter) {
            self::rejectNestedBinder($parameter);
        }
        $declared = [];
        foreach ($binder as $name) {
            if (array_key_exists($name, $declared)) {
                throw new InvalidArgumentException(sprintf('Type variable %s is already declared', $name));
            }
            $declared[$name] = true;
        }
        $free = Signature::freeVariables($return, $parameters);
        $freeSet = array_fill_keys($free, true);
        foreach ($binder as $name) {
            if (!array_key_exists($name, $freeSet)) {
                throw new InvalidArgumentException(
                    sprintf('%s is declared but doesn\'t appear in the parameters or the return type', $name),
                );
            }
        }
        foreach ($free as $name) {
            if (!array_key_exists($name, $declared)) {
                throw new InvalidArgumentException(
                    sprintf('%s appears in the parameters or the return type but isn\'t declared', $name),
                );
            }
        }
        return new self(new FuncShape(new Signature($return, $parameters, $binder)));
    }

    public static function fromValue(mixed $value): self
    {
        if (is_array($value)) {
            [$keyType, $valueType] = self::keyAndValueTypeFromArray($value);
            return array_is_list($value) ? self::listOf($valueType) : self::mapOf($keyType, $valueType);
        }
        if ($value === null) {
            return self::none();
        }
        return match (gettype($value)) {
            'string' => self::string(),
            'integer' => self::int(),
            'boolean' => self::bool(),
            'double' => self::float(),
            'object' => self::struct(self::fieldsFromObject($value)),
            default => throw new InvalidArgumentException(sprintf('Unsupported type %s', gettype($value))),
        };
    }

    public static function option(self $some): self
    {
        return new self(new ApplicationShape('Option', [$some]));
    }

    public static function some(self $some): self
    {
        return $some;
    }

    public static function none(): self
    {
        return new self(new ApplicationShape('None'));
    }

    /**
     * @param array<string, self> $fields
     */
    public static function struct(array $fields): self
    {
        return new self(new StructShape($fields));
    }

    /**
     * {@see self::func()}'s and {@see self::genericFunc()}'s own guard: both doors mean "I am a complete,
     * self-contained signature", so nothing reachable from $type -- however many lists, Options, or struct fields
     * deep -- may itself already be a function type carrying a non-empty binder of its own. Left unchecked, such a
     * $type would silently shadow the enclosing signature's binder instead of sharing variables with it -- the exact
     * rank-1 rule {@see Parser\TypeResolution::resolveSignature()} enforces when a signature is written as a type
     * string, applied here so a signature built directly through this API can't nest what the parser would reject.
     * A caller that means to nest a function type this way wants {@see self::nestedFunc()} instead.
     *
     * The walk itself is {@see TypeShape::hasNestedBinder()}, one method per shape rather than an `instanceof` chain
     * repeated here: a new shape has to answer for itself to exist at all, the same reason {@see TypeShape} exists.
     * {@see AliasShape} answers false without looking inside the alias it wraps: a named alias is its own complete,
     * separately quantified signature -- `fn(Mapper) -> int` where `Mapper` stands for `fn<U>(U) -> U` is a fixed
     * parameter, not a nested binder -- and {@see Parser\TypeResolution::resolveAlias()} never re-checks one against
     * the position it's used in either, since it was already checked, if at all, when the alias was itself built.
     *
     * @throws InvalidArgumentException if $type reaches such a function type.
     */
    private static function rejectNestedBinder(self $type): void
    {
        if ($type->shape->hasNestedBinder()) {
            throw new InvalidArgumentException(
                'A function type nested inside another one can\'t bind type variables of its own: a variable is '
                    . 'quantified once, by whichever function type encloses it',
            );
        }
    }

    private static function never(): self
    {
        return new self(new ApplicationShape('never'));
    }

    /**
     * @return array<string, self>
     */
    private static function fieldsFromObject(object $value): array
    {
        return array_map(self::fromValue(...), self::stringKeys(get_object_vars($value)));
    }

    /**
     * PHP normalizes numeric property names like "1" to integer array keys. Such names are not valid identifiers, so
     * they can neither be declared in a struct type nor accessed in an expression. Drop them instead of pretending
     * they are fields.
     *
     * @template T
     * @param array<array-key, T> $items
     * @return array<string, T>
     */
    private static function stringKeys(array $items): array
    {
        $stringKeyed = [];
        foreach ($items as $key => $item) {
            if (!is_string($key)) {
                continue;
            }
            $stringKeyed[$key] = $item;
        }
        return $stringKeyed;
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array{Type, Type}
     */
    private static function keyAndValueTypeFromArray(array $value): array
    {
        if ($value === []) {
            return [self::never(), self::never()];
        }
        $firstKey = array_key_first($value);
        return [self::fromValue($firstKey), self::fromValue($value[$firstKey])];
    }

    #[Override]
    public function __toString(): string
    {
        return $this->shape->toString();
    }

    /**
     * @throws Parser\TypeError
     */
    public function assert(mixed $value): mixed
    {
        $valueType = self::fromValue($value);
        return $valueType->isSubtypeOf($this)
            ? $value
            : throw new Parser\TypeError(sprintf('Expected %s, got %s', $this, $valueType));
    }

    public function equals(self $type): bool
    {
        return $this->isSubtypeOf($type) && $type->isSubtypeOf($this);
    }

    public function isOption(): bool
    {
        return $this->canonical()->isNamed('Option');
    }

    /**
     * Whether this type is `any` -- the same kind of shortcut {@see self::isOption()} is, but exposed rather than
     * kept private, since {@see FuncShape::bind()} needs to ask it about the type actually facing a function's
     * parameter and {@see self::canonical()} and {@see self::isNamed()}, which it needs, are $this-bound private
     * methods no shape class can call directly -- the same reason {@see self::of()} exists for
     * {@see TypeShape::substitute()}.
     *
     * @internal
     * @psalm-internal Eventjet\Ausdruck
     */
    public function isAny(): bool
    {
        return $this->canonical()->isNamed('any');
    }

    public function isSubtypeOf(self $other): bool
    {
        $self = $this->canonical();
        $other = $other->canonical();
        if ($self->isNamed('None')) {
            return $other->isNamed('None') || $other->isNamed('Option');
        }
        $otherOption = $other->optionArg();
        if ($otherOption !== null && !$self->isNamed('Option')) {
            return $self->isSubtypeOf($otherOption);
        }
        if ($self->isNamed('never')) {
            return true;
        }
        if ($other->isNamed('any')) {
            return true;
        }
        $selfOption = $self->optionArg();
        if ($selfOption !== null) {
            return $otherOption !== null && $selfOption->isSubtypeOf($otherOption);
        }
        $selfList = $self->listArg();
        if ($selfList !== null && $selfList->isNamed('never') && $other->isNamed('map')) {
            return true;
        }
        $selfShape = $self->shape;
        $otherShape = $other->shape;
        // Each shape decides for itself, in {@see TypeShape::isSubtypeOf()}, whether $other is even its own kind
        // before comparing anything else -- two ApplicationShapes can be named differently, and two VariableShapes
        // can too, {@see self::var('T')} and {@see self::var('U')} aren't the same variable -- which is also where
        // the rest of the comparison -- args, signature, fields -- lives; a {@see AliasShape} never reaches here,
        // having already been seen through by {@see self::canonical()} above.
        return $selfShape->isSubtypeOf($otherShape);
    }

    /**
     * This type as a function's signature, or null if it isn't one. {@see Signature::binder()} answers back whatever
     * this type was actually built with: a signature built through {@see self::func()} or {@see self::genericFunc()}
     * reads back the binder it declared for itself, even an empty one, while one built through
     * {@see self::nestedFunc()} -- meant to be read only as a piece of some other signature's own parameters or
     * return type -- reads back none, whether or not a caller actually goes on to use it that way.
     */
    public function asFunction(): Signature|null
    {
        $shape = $this->canonical()->shape;
        return $shape instanceof FuncShape ? $shape->signature : null;
    }

    public function isStruct(): bool
    {
        return $this->canonical()->shape instanceof StructShape;
    }

    public function getFieldType(string $name): self|null
    {
        $shape = $this->canonical()->shape;
        return $shape instanceof StructShape ? ($shape->fields[$name] ?? null) : null;
    }

    /**
     * What $actual tells us about the variables in this type, added to what is already known. Matching is structural
     * and one-way: where the two types have the same shape, the variables on this side take the types facing them,
     * and where they don't, there is nothing to learn and the bindings come back unchanged. Both sides are seen
     * through their aliases first, so a `Numbers` standing for `list<int>` still binds `T` in a `list<T>` regardless
     * of which side names the alias and which spells the type out -- and so does a variable standing behind an alias
     * on either side, since canonicalizing happens before this type's own shape is even asked about.
     *
     * Shape, here, is the same shape {@see self::isSubtypeOf()} accepts as a match, not just equal names: $actual is
     * always the type of a value this type would have to accept, so wherever isSubtypeOf() would let $actual through
     * by a coercion rather than a plain name match, there is something to learn from that too. A value that isn't
     * itself an `Option` is still accepted where an `Option<X>` is expected as long as the value is a subtype of `X`,
     * so `X` faces the value directly. `None`, which isSubtypeOf() accepts into an `Option` unconditionally without
     * looking inside it, teaches nothing either way, which the ordinary name-mismatch case already gives for free.
     *
     * The first binding for a variable is the one that's kept, with one exception: a function's parameters, unlike
     * everywhere else a variable can appear, are contravariant, so a parameter actually typed `any` -- which is every
     * {@see Lambda} parameter, since a lambda's own parameter types are never known ahead of the call it's an argument
     * to -- doesn't decide the variable there no matter when it's walked. A function that accepts anything trivially
     * accepts whatever that variable turns out to be, which is exactly what {@see self::isSubtypeOf()}'s own function
     * case already relies on when the *declared* parameter is `any`; here it's the value's parameter that is, and the
     * variable is left for a later, real parameter -- or the receiver, one of {@see Signature::$parameters}, walked
     * before the arguments a call passes -- to decide instead. See {@see Signature::instantiateForCall()} for why
     * first-wins is the useful half of the two everywhere else.
     *
     * A parameter position that is itself a self-contained generic function type -- {@see Signature::hasOwnBinder()}
     * true -- is fixed as far as this signature is concerned, the same way a concrete, non-variable type is: there is
     * nothing to learn from matching into it, since none of its variables are free for this walk to bind. See
     * {@see FuncShape::collectVariables()} for the same rule applied to what a signature's own binder is derived from.
     *
     * This is the recursive walk that applies to any type, not just a function's parameters, which is why it lives
     * here rather than on {@see Signature}; nothing outside the type system should call it directly. The function
     * case itself is {@see FuncShape::bind()}.
     *
     * @internal
     * @psalm-internal Eventjet\Ausdruck
     *
     * @param array<string, self> $bindings
     * @return array<string, self>
     */
    public function bind(self $actual, array $bindings): array
    {
        $self = $this->canonical();
        $actual = $actual->canonical();
        $selfShape = $self->shape;
        $actualShape = $actual->shape;
        if ($selfShape instanceof VariableShape) {
            $name = $selfShape->name;
            return array_key_exists($name, $bindings) ? $bindings : [...$bindings, $name => $actual];
        }
        $selfOption = $self->optionArg();
        if ($selfOption !== null && !$actual->isNamed('Option') && !$actual->isNamed('None')) {
            return $selfOption->bind($actual, $bindings);
        }
        return $selfShape->bind($actualShape, $bindings);
    }

    /**
     * Every {@see self::var()} this type reaches, folded into $found in the order first seen -- delegated to
     * {@see TypeShape::collectVariables()}, which is where the rule differs per shape; see there for what "reaches"
     * means for a nested function type with its own binder.
     *
     * @internal
     * @psalm-internal Eventjet\Ausdruck
     *
     * @param array<string, true> $found
     * @return array<string, true>
     */
    public function collectVariables(array $found): array
    {
        return $this->shape->collectVariables($found);
    }

    /**
     * Whether $this reaches a {@see self::var()} with no enclosing binder to capture it -- true for a bare variable
     * used on its own, for a function type built through {@see self::nestedFunc()} used standalone rather than
     * nested inside whatever was supposed to enclose it, and for either one reached through a list, an `Option`, a
     * struct field, or an alias, since {@see self::collectVariables()} -- the walk this delegates to, starting from
     * nothing already found -- crosses all of those the same way {@see Signature::freeVariables()} crosses a
     * signature's own parameters and return type.
     *
     * This is the question every boundary where a consumer-supplied $type is promoted to a standalone type, with
     * nothing left to enclose it, has to ask before trusting it: {@see Parser\Types::__construct()} for an alias,
     * and {@see Parser\Declarations} for a declared function or variable. A $type built entirely through
     * {@see self::func()} and {@see self::genericFunc()} can never answer true, since each already derives or checks
     * its own binder against exactly what it reaches; true here means a {@see self::nestedFunc()} or a bare
     * {@see self::var()} ended up somewhere nothing encloses.
     *
     * @internal
     * @psalm-internal Eventjet\Ausdruck
     */
    public function hasFreeVariables(): bool
    {
        return $this->collectVariables([]) !== [];
    }

    /**
     * Whether $this is, or itself reaches, a function type with a binder of its own -- delegated to
     * {@see TypeShape::hasNestedBinder()}, which is where the rule differs per shape; see
     * {@see self::rejectNestedBinder()} for what asks.
     *
     * @internal
     * @psalm-internal Eventjet\Ausdruck
     */
    public function hasNestedBinder(): bool
    {
        return $this->shape->hasNestedBinder();
    }

    /**
     * This type with every variable replaced by what it was bound to, and every variable nothing bound replaced by
     * `any` -- delegated straight to {@see TypeShape::substitute()}, which is where the rule differs per shape:
     * {@see VariableShape::substitute()} answers its own binding directly, since what a variable substitutes to can
     * be any shape at all, not necessarily another variable; every other shape rebuilds its own kind with its
     * children substituted, including {@see FuncShape}, whose own implementation leaves a nested signature with its
     * own binder untouched rather than rewriting variables that belong to itself.
     *
     * @internal
     * @psalm-internal Eventjet\Ausdruck
     *
     * @param array<string, self> $bindings
     */
    public function substitute(array $bindings): self
    {
        return $this->shape->substitute($bindings);
    }

    /**
     * This type with every alias seen through, not just the first one: an alias can itself be built directly on top
     * of another alias -- `Type::alias('A', Type::alias('B', ...))`, or a `Types` registry resolved incrementally so
     * that one alias's own target is another alias -- and every alias-aware check needs the real, non-alias shape at
     * the bottom of that chain, not merely the one level down a single `?? $this` would stop at.
     */
    private function canonical(): self
    {
        $type = $this;
        while ($type->shape instanceof AliasShape) {
            $type = $type->shape->target;
        }
        return $type;
    }

    /**
     * $this's own type argument, if $this is an `Option<...>` -- not seen through an alias first, so a caller that
     * needs that calls {@see self::canonical()} itself. The one place {@see self::isSubtypeOf()} and {@see self::bind()}
     * ask whether a type is an `Option` and read its argument, rather than each checking
     * `$shape instanceof ApplicationShape && $shape->name === 'Option'` and indexing `$shape->args[0]` directly.
     */
    private function optionArg(): self|null
    {
        $shape = $this->shape;
        return $shape instanceof ApplicationShape && $shape->name === 'Option' ? $shape->args[0] : null;
    }

    /**
     * $this's own element type, if $this is a `list<...>` -- the same shortcut as {@see self::optionArg()}, for
     * `list` instead of `Option`.
     */
    private function listArg(): self|null
    {
        $shape = $this->shape;
        return $shape instanceof ApplicationShape && $shape->name === 'list' ? $shape->args[0] : null;
    }

    /**
     * Whether this type is $name, told apart from a variable or a struct that merely happens to share the name by
     * $shape rather than by $name alone -- $name is not unique on its own: {@see self::var()} takes any name without
     * checking it against what a real type of that name would be. Only meaningful for the handful of names
     * {@see TypeConstructor} spells and that this class also builds directly, like `None` and `never`; a plain
     * user-facing type is compared by identity through {@see self::isSubtypeOf()} instead, which needs more than a
     * name match.
     */
    private function isNamed(string $name): bool
    {
        $shape = $this->shape;
        return $shape instanceof ApplicationShape && $shape->name === $name;
    }
}
