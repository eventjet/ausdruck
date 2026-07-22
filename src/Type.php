<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use InvalidArgumentException;
use Override;
use Stringable;

use function array_fill_keys;
use function array_intersect_key;
use function array_is_list;
use function array_key_exists;
use function array_key_first;
use function array_keys;
use function array_map;
use function array_slice;
use function count;
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
    /**
     * The name a {@see Type} built from $shape carries -- e.g. `list` in `list<T>`, or `T` for a variable. The one
     * field {@see self::$shape} still surfaces directly, since every caller that reads it already needs
     * {@see self::isNamed()}, {@see self::optionArg()} or {@see self::listArg()} to know what the name is a name
     * *of* -- unlike $args, $fields and $aliasFor, which used to sit here the same way before nothing left read them
     * off {@see self} instead of off the shape that actually carries them.
     */
    public readonly string $name;

    private function __construct(
        private readonly TypeShape $shape,
    ) {
        $this->name = $shape->name();
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
     * for -- so `self::alias('Struct', ...)` prints as `Struct` and `self::alias('fn', ...)` prints as `fn`, neither
     * one reachable through the checks that look for a {@see StructShape} or a {@see FuncShape}.
     */
    public static function alias(string $name, self $type): self
    {
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
     * A name a type constructor already spells, like `int` or `list`, is rejected as a type variable when a signature
     * is written as a type string -- see {@see Parser\TypeResolution::checkTypeVariable()} -- because within that
     * signature's own text, the bare word could no longer mean the type once it also names the variable. That
     * ambiguity is about written syntax, not about this constructor: `Type::var('int')` and `Type::int()` are two
     * distinct, unambiguous PHP calls, so nothing here rejects the name `int` -- or any other -- the way the parser
     * does.
     */
    public static function var(string $name): self
    {
        return new self(new VariableShape($name));
    }

    /**
     * A function type keeps its return type, its parameters and its own `fn<...>` binder in one {@see Signature}, which
     * is also the only thing {@see self::asFunction()} ever returns: nothing outside this class reads a function type
     * any other way.
     *
     * This is the door for a caller that IS declaring a complete, self-contained signature -- the outermost one of a
     * declaration, never one nested inside another's own parameters or return type: it commits to that by deriving
     * $return and $parameters' own binder ({@see Signature::freeVariables()}) and storing it, rather than leaving it
     * to be guessed later from wherever the result ends up sitting in a {@see Type} tree, which is what made a
     * generic function type used as a fixed parameter type or a list's element type indistinguishable, on paper,
     * from the top-level declaration it was nested inside. {@see self::nestedFunc()} is the other door, for a
     * function type that is that nested position; {@see Parser\TypeResolution::resolveSignature()} picks between the
     * two once it knows, from where in the source $node was written, which one this is. {@see self::genericFunc()}
     * is a third, for a caller that wants the derived binder checked against one written by hand.
     *
     * @param list<Type> $parameters The types the PHP callable receives, in order. A function that is called as a
     *     receiver function -- `foo:string.substr:string(0, 3)` -- receives the expression it's called on as the first
     *     of them; see {@see Signature::receiverType()} and {@see Signature::argumentTypes()}.
     */
    public static function func(self $return, array $parameters = []): self
    {
        $binder = Signature::freeVariables($return, $parameters);
        return new self(new FuncShape(new Signature($return, $parameters, $binder)));
    }

    /**
     * A function type that defers every variable it reaches to whichever signature encloses it, because it IS that
     * enclosed position: nested inside a list, an Option, a struct field, or standing behind an alias used as a
     * parameter, rather than a complete declaration of its own -- the opposite commitment from {@see self::func()},
     * for the other of the two positions that constructor's own docblock can't tell apart by itself.
     * {@see Parser\TypeResolution::resolveSignature()}, for every signature it resolves inside another one's own
     * parameters or return type, is the one caller that matters in practice; {@see BuiltinFunctions} is the other,
     * for a lambda parameter like `filter`'s or `map`'s own, whose variables are the enclosing built-in's.
     *
     * @param list<Type> $parameters
     *
     * @internal
     * @psalm-internal Eventjet\Ausdruck
     */
    public static function nestedFunc(self $return, array $parameters = []): self
    {
        return new self(new FuncShape(new Signature($return, $parameters)));
    }

    /**
     * A function type that owns its own binder, the same commitment {@see self::func()} makes, but with the binder
     * given rather than derived: this is the door for a caller that wants it checked against the variables $return
     * and $parameters actually reach, both ways -- a name declared here that isn't reachable is dead, and one
     * reachable that isn't declared is the exact footgun a signature built by hand used to leave open before this
     * constructor started validating -- silently turning the missing name into `any` at the call site instead of
     * failing where the mistake was made. {@see Parser\TypeResolution} already checks both directions itself, with a
     * message that points at the written source, so this validation is never expected to trigger through the
     * parser; it exists for a caller building a signature directly.
     *
     * @param list<string> $binder
     * @param list<Type> $parameters
     */
    public static function genericFunc(array $binder, self $return, array $parameters = []): self
    {
        $free = array_fill_keys(Signature::freeVariables($return, $parameters), true);
        foreach ($binder as $name) {
            if (!array_key_exists($name, $free)) {
                throw new InvalidArgumentException(
                    sprintf('%s is declared but doesn\'t appear in the parameters or the return type', $name),
                );
            }
        }
        $declared = array_fill_keys($binder, true);
        foreach (array_keys($free) as $name) {
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

    /**
     * {@see self::bind()}'s function case: the return type always binds, and a parameter binds unless $actual's own
     * parameter in that position is `any`, which is every {@see Lambda} parameter -- see {@see self::bind()}'s own
     * docblock for why that's the rule rather than a position. Parameters are walked before the return type, the same
     * order {@see Type::collectVariables()} walks a function type's own parts in, so a variable used both directly
     * and through a nested function type is decided in the same place either way. Takes both signatures directly,
     * rather than the two {@see Type}s they came from: {@see self::bind()} only ever calls this once it has already
     * established, from $self's and $actual's own shapes, that both are function types, so there is nothing left here
     * to guard against.
     *
     * @param array<string, self> $bindings
     * @return array<string, self>
     */
    private static function bindSignatures(Signature $signature, Signature $actualSignature, array $bindings): array
    {
        foreach ($signature->parameters as $index => $parameter) {
            $actualParameter = $actualSignature->parameters[$index] ?? null;
            if ($actualParameter === null || $actualParameter->canonical()->isAny()) {
                continue;
            }
            $bindings = $parameter->bind($actualParameter, $bindings);
        }
        return $signature->returnType->bind($actualSignature->returnType, $bindings);
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
        if ($selfList !== null && $selfList->isNever() && $other->isNamed('map')) {
            return true;
        }
        $selfShape = $self->shape;
        $otherShape = $other->shape;
        // A name alone isn't enough: {@see self::var()} takes any name without complaint, so a variable can share a
        // name with a type it isn't -- `Type::var('list')` is not a `list<T>`, no matter that both are named `list`.
        // $shape is what actually tells them apart; see {@see self::isNamed()}.
        if ($selfShape::class !== $otherShape::class || $self->name !== $other->name) {
            return false;
        }
        $otherList = $other->listArg();
        if ($selfList !== null && $otherList !== null) {
            return $selfList->isSubtypeOf($otherList);
        }
        if ($selfShape instanceof FuncShape && $otherShape instanceof FuncShape) {
            $signature = $selfShape->signature;
            $otherSignature = $otherShape->signature;
            if (!$signature->returnType->isSubtypeOf($otherSignature->returnType)) {
                return false;
            }
            foreach ($signature->parameters as $i => $param) {
                $otherParam = $otherSignature->parameters[$i] ?? null;
                if ($otherParam === null) {
                    return false;
                }
                if (!$otherParam->isSubtypeOf($param)) {
                    return false;
                }
            }
        }
        if ($selfShape instanceof StructShape && $otherShape instanceof StructShape) {
            foreach ($otherShape->fields as $name => $fieldType) {
                if (!array_key_exists($name, $selfShape->fields)) {
                    return false;
                }
                if (!$selfShape->fields[$name]->isSubtypeOf($fieldType)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * This type as a function's signature, or null if it isn't one. The {@see Signature} returned here is always
     * built through {@see self::func()} or {@see self::genericFunc()} -- a signature nested inside another one's own
     * return type or parameters is only ever reached through {@see self::nestedFunc()}, never handed back on its
     * own -- so {@see Signature::binder()} always reads the signature's own binder here, the same names it was
     * declared with, never a deferral to something enclosing it.
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
     * case itself is {@see self::bindSignatures()}.
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
            return array_key_exists($self->name, $bindings) ? $bindings : [...$bindings, $self->name => $actual];
        }
        $selfOption = $self->optionArg();
        if ($selfOption !== null && !$actual->isNamed('Option') && !$actual->isNone()) {
            return $selfOption->bind($actual, $bindings);
        }
        if ($selfShape::class !== $actualShape::class || $self->name !== $actual->name) {
            return $bindings;
        }
        if ($selfShape instanceof FuncShape && $actualShape instanceof FuncShape) {
            if ($selfShape->signature->hasOwnBinder()) {
                return $bindings;
            }
            return self::bindSignatures($selfShape->signature, $actualShape->signature, $bindings);
        }
        if ($selfShape instanceof StructShape && $actualShape instanceof StructShape) {
            // A struct can be written with fewer fields than one reaches into. What the two have in common is what
            // there is to learn from.
            foreach (array_intersect_key($selfShape->fields, $actualShape->fields) as $name => $field) {
                $bindings = $field->bind($actualShape->fields[$name], $bindings);
            }
            return $bindings;
        }
        if ($selfShape instanceof ApplicationShape && $actualShape instanceof ApplicationShape) {
            // Two types of the same shape can still be of different sizes: a lambda declares fewer parameters than
            // the signature asks for. What the two have in common is what there is to learn from.
            foreach (array_slice($selfShape->args, 0, count($actualShape->args)) as $index => $arg) {
                $bindings = $arg->bind($actualShape->args[$index], $bindings);
            }
            return $bindings;
        }
        return $bindings;
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
     * needs that calls {@see self::canonical()} itself. Replaces what used to be a repeated
     * `$shape instanceof ApplicationShape && $this->name === 'Option'` check, followed by an unguarded
     * `$shape->args[0]`, at every one of the five places {@see self::isSubtypeOf()} and {@see self::bind()} needed to
     * ask the same question.
     */
    private function optionArg(): self|null
    {
        $shape = $this->shape;
        return $shape instanceof ApplicationShape && $this->name === 'Option' ? $shape->args[0] : null;
    }

    /**
     * $this's own element type, if $this is a `list<...>` -- the same shortcut as {@see self::optionArg()}, for
     * `list` instead of `Option`.
     */
    private function listArg(): self|null
    {
        $shape = $this->shape;
        return $shape instanceof ApplicationShape && $this->name === 'list' ? $shape->args[0] : null;
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
        return $this->shape instanceof ApplicationShape && $this->name === $name;
    }

    private function isNone(): bool
    {
        return $this->isNamed('None');
    }

    private function isNever(): bool
    {
        return $this->isNamed('never');
    }

    private function isAny(): bool
    {
        return $this->isNamed('any');
    }
}
