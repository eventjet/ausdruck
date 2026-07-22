<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use InvalidArgumentException;
use Override;
use Stringable;

use function array_is_list;
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
     * Wraps an already-built {@see TypeShape} as a {@see self} -- the door {@see self::__construct()}'s own privacy
     * requires, since no shape class can call it directly. Every {@see TypeShape::substitute()} implementation uses
     * it to hand its result back, and so does {@see Signature::toType()}, for the same reason: neither lives where
     * {@see self::__construct()} does.
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
     * $type is quantified first if it's a function type -- {@see Signature::quantified()} -- since aliasing is,
     * alongside a declaration ({@see Parser\Declarations}), the other place a signature is promoted from bare
     * structure to a complete, self-contained one: `Type::alias('Mapper', Type::func(Type::var('T'), [Type::var('T')]))`
     * derives `Mapper`'s own `fn<T>(T) -> T` binder the same way declaring a function does. Anything $type reaches
     * that isn't a function and still isn't captured that way -- {@see self::hasFreeVariables()} -- is rejected
     * instead: a bare {@see self::var()}, or one nested inside a list, an `Option`, or a struct field, would
     * otherwise resolve every reference to this alias with a variable no written `fn<...>` binder could ever declare.
     *
     * $name is never checked against what $type itself is a shape of: an alias's own shape is always an
     * {@see AliasShape}, since it prints as its own name ({@see AliasShape::toString()}) rather than as whatever it
     * stands for -- so `self::alias('Struct', ...)` prints as `Struct`, reachable through neither the check that
     * looks for a {@see StructShape} nor the one that looks for a {@see FuncShape}.
     *
     * $name is rejected when {@see TypeConstructor::isReservedName()} says so -- every name a
     * {@see TypeConstructor} case already spells, plus `fn`: {@see self::__toString()} turns this alias back into
     * written syntax, and this project treats `parse(str($type)) === $type` as a hard invariant, so a name the parser
     * could never read back as an alias reference the way it was built isn't a name this door hands out either.
     *
     * @throws InvalidArgumentException if $name is reserved, or if $type isn't a function type and reaches a type
     *     variable nothing captures.
     */
    public static function alias(string $name, self $type): self
    {
        if (TypeConstructor::isReservedName($name)) {
            throw new InvalidArgumentException(TypeConstructor::reservedNameMessage($name, 'an alias'));
        }
        $signature = Signature::quantified($type);
        if ($signature !== null) {
            $type = new self(new FuncShape($signature));
        } elseif ($type->hasFreeVariables()) {
            throw new InvalidArgumentException(sprintf(
                '%s is declared as %s, which reaches a type variable nothing captures -- aliasing only derives a '
                    . 'binder for a function type, and this isn\'t one',
                $name,
                $type,
            ));
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
     * variables of the same name in one signature are the same variable -- {@see Signature::quantified()} is what
     * derives a signature's own binder from wherever this turns out to be used, once the whole signature exists to
     * look at.
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
     * A function type: its return type, its parameters, and no binder of its own -- see {@see Signature::quantified()}
     * for the door that derives one, once something promotes $return and $parameters to a complete signature.
     *
     * This is the door for every function type that commits to no binder of its own, whichever position it ends up
     * in: the outermost signature of a declaration or an alias target, before it's promoted, or a fixed parameter
     * nested inside another one's own return type or parameters -- a generic function type used as a fixed parameter
     * or a list's element type, or a lambda parameter like `filter`'s or `map`'s own. Nothing about $return or
     * $parameters, or about which position this ends up in, has anything to disagree about, since a type built here
     * shares whatever variables it reaches with whichever signature, if any, goes on to quantify it, rather than
     * claiming any for itself. The one signature position that does commit to a binder as it's built -- a written
     * `fn<...>`, resolved by {@see Parser\TypeResolution::resolveSignature()} -- builds its {@see Signature} directly
     * with that binder instead, since it's the author's own, not something derived after the fact the way
     * {@see Signature::quantified()} derives one.
     *
     * @param list<Type> $parameters The types the PHP callable receives, in order. A function that is called as a
     *     receiver function -- `foo:string.substr:string(0, 3)` -- receives the expression it's called on as the first
     *     of them; see {@see Signature::receiverType()} and {@see Signature::argumentTypes()}.
     */
    public static function func(self $return, array $parameters = []): self
    {
        return new self(new FuncShape(new Signature($return, $parameters)));
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
     * $this's own {@see TypeShape}, not seen through {@see self::canonical()} first -- the reverse of {@see self::of()},
     * for the same reason: {@see self::$shape} is private and no shape class can read it directly. Every
     * {@see TypeShape::bind()} implementation that needs to know whether $actual -- the {@see Type} it was handed,
     * already canonicalized by {@see self::bind()} before it ever reaches a shape -- is its own kind of shape uses
     * this to reach it, rather than {@see self::bind()} unwrapping $actual on every implementation's behalf.
     *
     * @internal
     * @psalm-internal Eventjet\Ausdruck
     */
    public function shape(): TypeShape
    {
        return $this->shape;
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
        $selfList = $self->listArg();
        if ($selfList !== null && $selfList->isNamed('never') && $other->isNamed('map')) {
            return true;
        }
        // The rest of the comparison -- name, args, signature, fields, and whether $other is even the same kind of
        // shape at all -- is {@see TypeShape::isSubtypeOf()}'s own to make. A {@see AliasShape} never reaches here,
        // having already been seen through by {@see self::canonical()} above -- this is also why `Option<X>` needs
        // no case of its own above: once $self and $other are both Options, they're both ApplicationShapes of that
        // name, and the pairwise-argument comparison below is the same recursive `X.isSubtypeOf(Y)` a dedicated case
        // would run.
        return $self->shape->isSubtypeOf($other->shape);
    }

    /**
     * This type as a function's signature, or null if it isn't one. {@see Signature::binder()} answers back whatever
     * this type was actually built with -- see {@see Signature::hasOwnBinder()} for what an empty one means.
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
     * through their aliases first, the same as {@see self::isSubtypeOf()}, so a `Numbers` standing for `list<int>`
     * still binds `T` in a `list<T>` on either side, and so does a variable standing directly behind an alias.
     * Canonicalizing $this never actually uncovers a free variable an alias was hiding: every alias reachable from a
     * declared parameter or return type is built through {@see self::alias()}, which never lets one reach a free
     * variable except by quantifying it as a function's own binder -- opaque to this walk regardless, the same as
     * any other self-contained generic function type, see {@see Signature::hasOwnBinder()}. Doing it anyway costs
     * nothing and keeps {@see AliasShape::bind()} genuinely unreachable rather than merely unexercised.
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
     * A parameter position that is itself a self-contained generic function type is fixed as far as this walk is
     * concerned, the same way a concrete, non-variable type is -- see {@see Signature::hasOwnBinder()}.
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
        $selfOption = $self->optionArg();
        if ($selfOption !== null && !$actual->isNamed('Option') && !$actual->isNamed('None')) {
            return $selfOption->bind($actual, $bindings);
        }
        // What's left, or nothing to learn if $actual isn't even the same kind of shape -- {@see TypeShape::bind()}'s
        // own to decide, $actual (still a {@see self}, not unwrapped here) and all; see {@see self::isSubtypeOf()}
        // for the same question asked there. {@see VariableShape::bind()} is what actually records a binding -- this
        // method no longer special-cases it before dispatching.
        return $self->shape->bind($actual, $bindings);
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
     * used on its own, for a function type built through {@see self::func()} that nothing has quantified, and for
     * either one reached through a list, an `Option`, a struct field, or an alias, since {@see self::collectVariables()}
     * -- the walk this delegates to, starting from nothing already found -- crosses all of those the same way
     * {@see Signature::freeVariables()} crosses a signature's own parameters and return type.
     *
     * This is the question {@see Parser\Declarations::checkVariableIsSelfContained()} asks of a declared variable's
     * type before trusting it: a declared variable has no `fn<...>` of its own to quantify a variable with, the way
     * a declared function or an alias target does -- see {@see Signature::quantified()} for the two doors that do,
     * and {@see self::alias()} for the other place this same question is asked, of an alias target that isn't a
     * function type.
     *
     * @internal
     * @psalm-internal Eventjet\Ausdruck
     */
    public function hasFreeVariables(): bool
    {
        return $this->collectVariables([]) !== [];
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
