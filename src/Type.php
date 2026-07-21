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
use function implode;
use function is_array;
use function is_string;
use function sprintf;

/**
 * @api
 */
final class Type implements Stringable
{
    /**
     * @param list<self> $args
     * @param array<string, Type> $fields
     */
    private function __construct(
        public readonly string $name,
        public readonly array $args = [],
        public readonly self|null $aliasFor = null,
        public readonly array $fields = [],
        private readonly TypeKind $kind = TypeKind::Named,
        private readonly Signature|null $signature = null,
    ) {
    }

    public static function string(): self
    {
        return new self('string');
    }

    public static function int(): self
    {
        return new self('int');
    }

    public static function float(): self
    {
        return new self('float');
    }

    public static function bool(): self
    {
        return new self('bool');
    }

    public static function listOf(self $item): self
    {
        return new self('list', [$item]);
    }

    public static function mapOf(self $keys, self $values): self
    {
        return new self('map', [$keys, $values]);
    }

    /**
     * An alias is a name for one complete type, and takes no arguments of its own: the arguments of the type it stands
     * for belong to that type, not to the name. Copying them here would make the alias print as `Bag<string>`, which
     * reads back as arguments applied to Bag and is rejected. Everything that needs to see through the name calls
     * {@see self::canonical()}.
     *
     * $name is never checked against what $type itself is a {@see TypeKind} of: an alias's own kind is always
     * {@see TypeKind::Named} regardless, since it prints as its own name ({@see self::toString()}) rather than as
     * whatever it stands for, so `self::alias('Struct', ...)` prints as `Struct` and `self::alias('fn', ...)` prints
     * as `fn`, neither one reachable through the checks that read {@see TypeKind::Struct} or a non-null
     * {@see self::$signature} to mean the thing itself.
     */
    public static function alias(string $name, self $type): self
    {
        return new self($name, aliasFor: $type);
    }

    public static function any(): self
    {
        return new self('any');
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
        return new self($name, kind: TypeKind::Variable);
    }

    /**
     * A function type keeps its return type, its parameters and its own `fn<...>` binder in one {@see Signature},
     * which is also the only thing {@see self::asFunction()} ever returns: nothing outside this class reads a
     * function type any other way. The binder itself is never taken as an argument here -- rank-1 polymorphism means
     * every {@see Type::var()} reachable from $return or $parameters, at any depth, belongs to this signature and no
     * other, so {@see self::asFunction()} derives the binder from where those variables turn out to be, rather than
     * this constructor validating a declared list against them; see that method's own docblock.
     *
     * @param list<Type> $parameters The types the PHP callable receives, in order. A function that is called as a
     *     receiver function -- `foo:string.substr:string(0, 3)` -- receives the expression it's called on as the first
     *     of them; see {@see Signature::receiverType()} and {@see Signature::argumentTypes()}.
     */
    public static function func(self $return, array $parameters = []): self
    {
        return new self('fn', kind: TypeKind::Func, signature: new Signature($return, $parameters));
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
        return new self('Option', [$some]);
    }

    public static function some(self $some): self
    {
        return $some;
    }

    public static function none(): self
    {
        return new self('None');
    }

    /**
     * @param array<string, self> $fields
     */
    public static function struct(array $fields): self
    {
        return new self('Struct', fields: $fields, kind: TypeKind::Struct);
    }

    /**
     * The message {@see Parser\TypeResolution::resolveSignature()} rejects a function type nested inside another
     * one's parameters or return type with -- the one place that rule is enforced: a nested `fn<...>` in written
     * syntax is rejected before it is ever resolved to a {@see Type}, and {@see self::func()} has nothing of its own
     * left to reject the same shape with, since it no longer takes a binder a caller could nest one into. Worded once
     * here rather than inline in the parser so a future second enforcement site, should one exist, can't drift from
     * this one.
     *
     * @internal
     * @psalm-internal Eventjet\Ausdruck
     */
    public static function nestedBinderMessage(): string
    {
        return 'A function type nested inside another one can\'t bind type variables of its own: a variable is '
            . 'quantified once, by whichever function type encloses it';
    }

    private static function never(): self
    {
        return new self('never');
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
     * The names {@see self::var()} contributes to $signature's return type and parameters, receiver first then the
     * rest of the parameters then the return type -- the same order {@see self::instantiateForCall()} decides them in
     * -- kept in first-found order and without a duplicate, since one variable used twice is still one name.
     *
     * @return list<string>
     */
    private static function freeVariables(Signature $signature): array
    {
        $found = [];
        foreach ($signature->parameters as $parameter) {
            $found = self::collectVariables($parameter, $found);
        }
        $found = self::collectVariables($signature->returnType, $found);
        return array_keys($found);
    }

    /**
     * @param array<string, true> $found
     * @return array<string, true>
     */
    private static function collectVariables(self $type, array $found): array
    {
        if ($type->kind === TypeKind::Variable) {
            $found[$type->name] = true;
            return $found;
        }
        if ($type->signature !== null) {
            $found = self::collectVariables($type->signature->returnType, $found);
            foreach ($type->signature->parameters as $parameter) {
                $found = self::collectVariables($parameter, $found);
            }
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
     * @param list<string> $typeVariables
     */
    private static function binderString(array $typeVariables): string
    {
        return $typeVariables === [] ? '' : sprintf('<%s>', implode(', ', $typeVariables));
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
        $canonical = $this->canonical();
        return $canonical->kind === TypeKind::Named && $canonical->name === 'Option';
    }

    public function isSubtypeOf(self $other): bool
    {
        $self = $this->canonical();
        $other = $other->canonical();
        if ($self->name === 'None') {
            return $other->name === 'None' || $other->name === 'Option';
        }
        if ($other->name === 'Option' && $self->name !== 'Option') {
            return $self->isSubtypeOf($other->args[0]);
        }
        if ($self->name === 'never') {
            return true;
        }
        if ($other->name === 'any') {
            return true;
        }
        if ($self->name === 'Option') {
            return $other->name === 'Option' && $self->args[0]->isSubtypeOf($other->args[0]);
        }
        if ($self->name === 'list' && $self->args[0]->isNever() && $other->name === 'map') {
            return true;
        }
        if ($self->name !== $other->name) {
            return false;
        }
        if ($self->name === 'list') {
            return $self->args[0]->isSubtypeOf($other->args[0]);
        }
        // Equivalent to `$self->kind === TypeKind::Func`, which {@see self::func()} always sets together with a
        // non-null signature -- checking the signature directly narrows it for the type checker without an assert.
        if ($self->signature !== null) {
            $otherSignature = $other->signature;
            if ($otherSignature === null) {
                // Same name, but $other isn't actually a function type -- e.g. a Type::var('fn') the caller built
                // directly, bypassing the parser. Not a function type of any kind, so not a matching one either.
                return false;
            }
            if (!$self->signature->returnType->isSubtypeOf($otherSignature->returnType)) {
                return false;
            }
            foreach ($self->signature->parameters as $i => $param) {
                $otherParam = $otherSignature->parameters[$i] ?? null;
                if ($otherParam === null) {
                    return false;
                }
                if (!$otherParam->isSubtypeOf($param)) {
                    return false;
                }
            }
        }
        if ($self->kind === TypeKind::Struct) {
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
     * This type as a function's signature, or null if it isn't one: only {@see self::func()} builds a type with a
     * {@see Signature} to return here. The binder is derived, not read back: every {@see self::var()} reachable from
     * the return type or a parameter, at any depth, is quantified by this signature and no other -- rank-1
     * polymorphism leaves nowhere else for it to belong, since a function type nested inside this one's return type or
     * parameters can never carry a binder of its own (rejected in written syntax by
     * {@see Parser\TypeResolution::resolveSignature()}, and unrepresentable through this API in the first place, since
     * {@see self::func()} takes no binder a caller could nest one into). So the free variables of the return type and
     * the parameters, walked in that order and kept in the order first found, are exactly the binder -- nothing to
     * validate, only to collect.
     */
    public function asFunction(): Signature|null
    {
        $signature = $this->canonical()->signature;
        if ($signature === null) {
            return null;
        }
        return new Signature($signature->returnType, $signature->parameters, self::freeVariables($signature));
    }

    public function isStruct(): bool
    {
        return $this->canonical()->kind === TypeKind::Struct;
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
     * of which side names the alias and which spells the type out.
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
     * case itself is {@see self::bindFunction()}.
     *
     * @internal
     * @psalm-internal Eventjet\Ausdruck
     *
     * @param array<string, self> $bindings
     * @return array<string, self>
     */
    public function bind(self $actual, array $bindings): array
    {
        if ($this->kind === TypeKind::Variable) {
            return array_key_exists($this->name, $bindings) ? $bindings : [...$bindings, $this->name => $actual];
        }
        $self = $this->canonical();
        $actual = $actual->canonical();
        if ($self->name === 'Option' && !$actual->isOption() && !$actual->isNone()) {
            return $self->args[0]->bind($actual, $bindings);
        }
        if ($self->name !== $actual->name) {
            return $bindings;
        }
        // Equivalent to `$self->kind === TypeKind::Func`; see the same check in {@see self::isSubtypeOf()}.
        if ($self->signature !== null) {
            return $self->bindFunction($actual, $bindings);
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
     * `any`. A function type's own binder is never carried on {@see self::$signature} in the first place -- only a
     * derived {@see Signature}, returned by {@see self::asFunction()} or built while printing, ever has one -- so
     * there is nothing here to drop it from.
     *
     * @internal
     * @psalm-internal Eventjet\Ausdruck
     *
     * @param array<string, self> $bindings
     */
    public function substitute(array $bindings): self
    {
        if ($this->kind === TypeKind::Variable) {
            return $bindings[$this->name] ?? self::any();
        }
        if ($this->signature !== null) {
            return new self(
                $this->name,
                aliasFor: $this->aliasFor?->substitute($bindings),
                kind: TypeKind::Func,
                signature: new Signature(
                    $this->signature->returnType->substitute($bindings),
                    array_map(
                        static fn(self $parameter): self => $parameter->substitute($bindings),
                        $this->signature->parameters,
                    ),
                ),
            );
        }
        return new self(
            $this->name,
            array_map(static fn(self $arg): self => $arg->substitute($bindings), $this->args),
            $this->aliasFor?->substitute($bindings),
            array_map(static fn(self $field): self => $field->substitute($bindings), $this->fields),
            $this->kind,
        );
    }

    /**
     * {@see self::bind()}'s Func case: the return type always binds, and a parameter binds unless $actual's own
     * parameter in that position is `any`, which is every {@see Lambda} parameter -- see {@see self::bind()}'s own
     * docblock for why that's the rule rather than a position. $this must already be canonical and have a non-null
     * {@see self::$signature}; call {@see self::bind()} instead.
     *
     * @param array<string, self> $bindings
     * @return array<string, self>
     */
    private function bindFunction(self $actual, array $bindings): array
    {
        $signature = $this->signature;
        $actualSignature = $actual->signature;
        if ($signature === null || $actualSignature === null) {
            return $bindings;
        }
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

    private function toString(bool $insideSignatureScope): string
    {
        if ($this->kind === TypeKind::Struct) {
            if ($this->fields === []) {
                return '{}';
            }
            $fields = [];
            foreach ($this->fields as $name => $fieldType) {
                $fields[] = $name . ': ' . $fieldType->toString($insideSignatureScope);
            }
            return '{ ' . implode(', ', $fields) . ' }';
        }
        // Equivalent to `$this->kind === TypeKind::Func`; see the same check in {@see self::isSubtypeOf()}.
        if ($this->signature !== null) {
            $signature = $this->signature;
            // A function type already inside another one's own return type or parameters can't have a binder of its
            // own -- see {@see self::asFunction()} -- so there is nothing of its own left to derive or print here;
            // its variables print bare, referring to whichever binder encloses this whole printout instead.
            $binder = $insideSignatureScope
                ? ''
                : self::binderString(self::freeVariables($signature));
            $params = array_map(static fn(self $arg): string => $arg->toString(true), $signature->parameters);
            return sprintf('fn%s(%s) -> %s', $binder, implode(', ', $params), $signature->returnType->toString(true));
        }
        if ($this->args === []) {
            return $this->name;
        }
        $args = array_map(static fn(self $arg): string => $arg->toString($insideSignatureScope), $this->args);
        return sprintf('%s<%s>', $this->name, implode(', ', $args));
    }

    private function canonical(): self
    {
        return $this->aliasFor ?? $this;
    }

    private function isNone(): bool
    {
        return $this->name === 'None';
    }

    private function isNever(): bool
    {
        return $this->name === 'never';
    }

    private function isAny(): bool
    {
        return $this->name === 'any';
    }
}
