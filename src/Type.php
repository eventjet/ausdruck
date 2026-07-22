<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use InvalidArgumentException;
use Override;
use Stringable;

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
     * The type arguments applied to {@see self::$name}, e.g. `T` and `U` in `map<T, U>` -- empty unless $shape is an
     * {@see ApplicationShape}.
     *
     * @var list<self>
     */
    public readonly array $args;

    /**
     * A struct's fields, keyed by name -- empty unless $shape is a {@see StructShape}; see {@see self::struct()}.
     *
     * @var array<string, self>
     */
    public readonly array $fields;

    /**
     * The type this one is an alias for -- null unless $shape is an {@see AliasShape}; see {@see self::alias()}.
     */
    public readonly self|null $aliasFor;

    private function __construct(
        public readonly string $name,
        private readonly TypeShape $shape,
    ) {
        $this->args = $shape instanceof ApplicationShape ? $shape->args : [];
        $this->fields = $shape instanceof StructShape ? $shape->fields : [];
        $this->aliasFor = $shape instanceof AliasShape ? $shape->target : null;
    }

    public static function string(): self
    {
        return new self('string', new ApplicationShape());
    }

    public static function int(): self
    {
        return new self('int', new ApplicationShape());
    }

    public static function float(): self
    {
        return new self('float', new ApplicationShape());
    }

    public static function bool(): self
    {
        return new self('bool', new ApplicationShape());
    }

    public static function listOf(self $item): self
    {
        return new self('list', new ApplicationShape([$item]));
    }

    public static function mapOf(self $keys, self $values): self
    {
        return new self('map', new ApplicationShape([$keys, $values]));
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
        return new self($name, new AliasShape($type));
    }

    public static function any(): self
    {
        return new self('any', new ApplicationShape());
    }

    /**
     * A type variable: the placeholder a generic function's signature writes where the concrete type is decided by the
     * call site rather than by the declaration. `head` is declared as `fn<T>(list<T>) -> Option<T>`, and a call on a
     * `list<string>` is checked against `fn(list<string>) -> Option<string>`; see {@see Signature::instantiateForCall()}.
     *
     * Variables are quantified at the top of the signature they appear in, so there is no binder to build here and two
     * variables of the same name in one signature are the same variable -- {@see self::asFunction()} derives that
     * binder from where the variable turns out to be used, once the whole signature exists to look at.
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
        return new self($name, new VariableShape());
    }

    /**
     * A function type keeps its return type, its parameters and its own `fn<...>` binder in one {@see Signature},
     * which is also the only thing {@see self::asFunction()} ever returns: nothing outside this class reads a
     * function type any other way. The binder itself is never taken as an argument here -- rank-1 polymorphism means
     * every {@see Type::var()} reachable from $return or $parameters, at any depth, belongs to this signature and no
     * other, so {@see self::asFunction()} derives the binder from where those variables turn out to be, rather than
     * this constructor validating a declared list against them.
     *
     * The {@see Signature} stored here carries no binder of its own -- see {@see FuncShape}'s own docblock for why
     * that can't be decided yet at this point.
     *
     * @param list<Type> $parameters The types the PHP callable receives, in order. A function that is called as a
     *     receiver function -- `foo:string.substr:string(0, 3)` -- receives the expression it's called on as the first
     *     of them; see {@see Signature::receiverType()} and {@see Signature::argumentTypes()}.
     */
    public static function func(self $return, array $parameters = []): self
    {
        return new self('fn', new FuncShape(new Signature($return, $parameters)));
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
        return new self('Option', new ApplicationShape([$some]));
    }

    public static function some(self $some): self
    {
        return $some;
    }

    public static function none(): self
    {
        return new self('None', new ApplicationShape());
    }

    /**
     * @param array<string, self> $fields
     */
    public static function struct(array $fields): self
    {
        return new self('Struct', new StructShape($fields));
    }

    private static function never(): self
    {
        return new self('never', new ApplicationShape());
    }

    /**
     * Every {@see self::var()} reachable from $type, at any depth, folded into $found in the order first seen. A
     * nested function type's own parameters and return type are walked parameters-first-then-return, same order
     * {@see self::deriveTypeVariables()} walks its own -- one order, so a variable used both directly and through a
     * nested function type is still found in the same place either way.
     *
     * @param array<string, true> $found
     * @return array<string, true>
     */
    private static function collectVariables(self $type, array $found): array
    {
        $shape = $type->shape;
        if ($shape instanceof VariableShape) {
            $found[$type->name] = true;
            return $found;
        }
        if ($shape instanceof FuncShape) {
            foreach ($shape->signature->parameters as $parameter) {
                $found = self::collectVariables($parameter, $found);
            }
            $found = self::collectVariables($shape->signature->returnType, $found);
        }
        foreach ($type->args as $arg) {
            $found = self::collectVariables($arg, $found);
        }
        foreach ($type->fields as $field) {
            $found = self::collectVariables($field, $found);
        }
        if ($type->aliasFor !== null) {
            $found = self::collectVariables($type->aliasFor, $found);
        }
        return $found;
    }

    /**
     * The names $signature's own `fn<...>` binder declares, in the order first found walking its parameters then its
     * return type -- receiver first, then the rest of the parameters, then the return type, the same order
     * {@see Signature::instantiateForCall()} decides them in -- without a duplicate, since one variable used twice is
     * still one name.
     *
     * Only ever called on a signature being read as a complete top-level declaration -- {@see self::asFunction()} and
     * the outermost function type in a {@see self::toString()} printout -- since a signature reached through another
     * one's own parameters or return type, however many lists, Options or struct fields deep, owns none of the
     * variables reachable inside it; whichever signature encloses it does.
     *
     * @return list<string>
     */
    private static function deriveTypeVariables(Signature $signature): array
    {
        $found = [];
        foreach ($signature->parameters as $parameter) {
            $found = self::collectVariables($parameter, $found);
        }
        $found = self::collectVariables($signature->returnType, $found);
        return array_keys($found);
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
     * docblock for why that's the rule rather than a position. Takes both signatures directly, rather than the two
     * {@see Type}s they came from: {@see self::bind()} only ever calls this once it has already established, from
     * $self's and $actual's own shapes, that both are function types, so there is nothing left here to guard against.
     *
     * @param array<string, self> $bindings
     * @return array<string, self>
     */
    private static function bindSignatures(Signature $signature, Signature $actualSignature, array $bindings): array
    {
        $bindings = $signature->returnType->bind($actualSignature->returnType, $bindings);
        foreach ($signature->parameters as $index => $parameter) {
            $actualParameter = $actualSignature->parameters[$index] ?? null;
            if ($actualParameter === null || $actualParameter->canonical()->isAny()) {
                continue;
            }
            $bindings = $parameter->bind($actualParameter, $bindings);
        }
        return $bindings;
    }

    #[Override]
    public function __toString(): string
    {
        return $this->toString(false);
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
        if ($other->isNamed('Option') && !$self->isNamed('Option')) {
            return $self->isSubtypeOf($other->args[0]);
        }
        if ($self->isNamed('never')) {
            return true;
        }
        if ($other->isNamed('any')) {
            return true;
        }
        if ($self->isNamed('Option')) {
            return $other->isNamed('Option') && $self->args[0]->isSubtypeOf($other->args[0]);
        }
        if ($self->isNamed('list') && $self->args[0]->isNever() && $other->isNamed('map')) {
            return true;
        }
        // A name alone isn't enough: {@see self::var()} takes any name without complaint, so a variable can share a
        // name with a type it isn't -- `Type::var('list')` is not a `list<T>`, no matter that both are named `list`.
        // $shape is what actually tells them apart; see {@see self::isNamed()}.
        if ($self->shape::class !== $other->shape::class || $self->name !== $other->name) {
            return false;
        }
        if ($self->isNamed('list')) {
            return $self->args[0]->isSubtypeOf($other->args[0]);
        }
        if ($self->shape instanceof FuncShape && $other->shape instanceof FuncShape) {
            $signature = $self->shape->signature;
            $otherSignature = $other->shape->signature;
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
        if ($self->shape instanceof StructShape) {
            foreach ($other->fields as $name => $fieldType) {
                if (!array_key_exists($name, $self->fields)) {
                    return false;
                }
                if (!$self->fields[$name]->isSubtypeOf($fieldType)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * This type as a function's signature, or null if it isn't one, with its own `fn<...>` binder derived and
     * attached: rank-1 polymorphism means every {@see self::var()} reachable from the return type or a parameter, at
     * any depth, is quantified by this signature and no other, since a function type nested inside this one's return
     * type or parameters can never carry a binder of its own (rejected in written syntax by
     * {@see Parser\TypeResolution::resolveSignature()}, and unrepresentable through this API in the first place,
     * since {@see self::func()} takes no binder a caller could nest one into) -- which is exactly why the
     * {@see Signature} {@see self::func()} stores has no binder of its own; see {@see FuncShape}. Deriving one is
     * what this method is for.
     */
    public function asFunction(): Signature|null
    {
        $shape = $this->canonical()->shape;
        if (!$shape instanceof FuncShape) {
            return null;
        }
        $signature = $shape->signature;
        return new Signature($signature->returnType, $signature->parameters, self::deriveTypeVariables($signature));
    }

    public function isStruct(): bool
    {
        return $this->canonical()->shape instanceof StructShape;
    }

    public function getFieldType(string $name): self|null
    {
        return $this->canonical()->fields[$name] ?? null;
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
        if ($self->shape instanceof VariableShape) {
            return array_key_exists($self->name, $bindings) ? $bindings : [...$bindings, $self->name => $actual];
        }
        if ($self->name === 'Option' && !$actual->isOption() && !$actual->isNone()) {
            return $self->args[0]->bind($actual, $bindings);
        }
        if ($self->shape::class !== $actual->shape::class || $self->name !== $actual->name) {
            return $bindings;
        }
        if ($self->shape instanceof FuncShape && $actual->shape instanceof FuncShape) {
            return self::bindSignatures($self->shape->signature, $actual->shape->signature, $bindings);
        }
        // Two types of the same shape can still be of different sizes: a lambda declares fewer parameters than the
        // signature asks for, and a struct is written with fewer fields than one reaches into. What the two have in
        // common is what there is to learn from.
        foreach (array_slice($self->args, 0, count($actual->args)) as $index => $arg) {
            $bindings = $arg->bind($actual->args[$index], $bindings);
        }
        foreach (array_intersect_key($self->fields, $actual->fields) as $name => $field) {
            $bindings = $field->bind($actual->fields[$name], $bindings);
        }
        return $bindings;
    }

    /**
     * This type with every variable replaced by what it was bound to, and every variable nothing bound replaced by
     * `any`. A function type is rebuilt with a fresh, binder-less {@see Signature} over the substituted return type
     * and parameters -- the same starting point {@see self::func()} itself builds from, see {@see FuncShape} -- so a
     * substituted function type's binder is derived again from scratch if it's ever read through
     * {@see self::asFunction()}, and comes back empty, since substitution replaces every variable, bound or not,
     * leaving none for {@see self::deriveTypeVariables()} to find.
     *
     * @internal
     * @psalm-internal Eventjet\Ausdruck
     *
     * @param array<string, self> $bindings
     */
    public function substitute(array $bindings): self
    {
        $shape = $this->shape;
        if ($shape instanceof VariableShape) {
            return $bindings[$this->name] ?? self::any();
        }
        if ($shape instanceof FuncShape) {
            $signature = $shape->signature;
            return new self(
                $this->name,
                new FuncShape(new Signature(
                    $signature->returnType->substitute($bindings),
                    array_map(
                        static fn(self $parameter): self => $parameter->substitute($bindings),
                        $signature->parameters,
                    ),
                )),
            );
        }
        if ($shape instanceof AliasShape) {
            return new self($this->name, new AliasShape($shape->target->substitute($bindings)));
        }
        if ($shape instanceof StructShape) {
            return new self(
                $this->name,
                new StructShape(
                    array_map(static fn(self $field): self => $field->substitute($bindings), $shape->fields),
                ),
            );
        }
        return new self(
            $this->name,
            new ApplicationShape(array_map(static fn(self $arg): self => $arg->substitute($bindings), $this->args)),
        );
    }

    private function toString(bool $insideSignatureScope): string
    {
        $shape = $this->shape;
        if ($shape instanceof StructShape) {
            $fields = [];
            foreach ($this->fields as $name => $fieldType) {
                $fields[] = $name . ': ' . $fieldType->toString($insideSignatureScope);
            }
            return TypeSyntax::struct($fields);
        }
        if ($shape instanceof FuncShape) {
            $signature = $shape->signature;
            // A function type already inside another one's own return type or parameters can't have a binder of its
            // own -- see {@see self::asFunction()} -- so there is nothing of its own left to print here; its
            // variables print bare, referring to whichever binder encloses this whole printout instead.
            $binder = $insideSignatureScope ? [] : self::deriveTypeVariables($signature);
            $params = array_map(static fn(self $arg): string => $arg->toString(true), $signature->parameters);
            return TypeSyntax::func($binder, $params, $signature->returnType->toString(true));
        }
        $args = array_map(static fn(self $arg): string => $arg->toString($insideSignatureScope), $this->args);
        return TypeSyntax::application($this->name, $args);
    }

    private function canonical(): self
    {
        return $this->aliasFor ?? $this;
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
