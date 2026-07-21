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
use function array_map;
use function array_slice;
use function assert;
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
        private readonly bool $isVariable = false,
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
     * $name is rejected on the same terms {@see self::var()} rejects one, for a stronger reason than that method has:
     * an alias's own $name -- not what it stands for -- is what a few checks read directly, without going through
     * {@see self::canonical()} first, because printing an alias by its target's kind instead of its own name is
     * exactly the bug those checks exist to avoid; see {@see self::toString()}'s `Struct` and `fn` cases. A name a
     * type constructor already spells collides with exactly the check meant to tell the two apart, so
     * `self::alias('Struct', ...)` printed as `{}` and `self::alias('fn', ...)` crashed before this rejected either
     * at the door.
     *
     * @throws InvalidArgumentException if $name is a type the language spells itself.
     */
    public static function alias(string $name, self $type): self
    {
        if (TypeConstructor::isReservedName($name)) {
            throw new InvalidArgumentException(TypeConstructor::reservedNameMessage($name, 'an alias'));
        }
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
     * variables of the same name in one signature are the same variable. A name a type constructor already spells,
     * like `int` or `list`, is not a variable name: {@see Parser\TypeResolution::checkTypeVariable()} rejects it where
     * a signature is written as a type string, and this rejects it here too, so the same rule holds for a signature
     * built directly through this API. Naming a variable that no enclosing {@see self::func()} declares is rejected
     * too, but not here -- see {@see self::asFunction()}, which is where the enclosing binder is finally known.
     *
     * @throws InvalidArgumentException if $name is a type the language spells itself.
     */
    public static function var(string $name): self
    {
        if (TypeConstructor::isReservedName($name)) {
            throw new InvalidArgumentException(TypeConstructor::reservedNameMessage($name));
        }
        return new self($name, isVariable: true);
    }

    /**
     * A function type keeps its return type, its parameters and its own `fn<...>` binder in one {@see Signature},
     * which is also the only thing {@see self::asFunction()} ever returns: nothing outside this class reads a
     * function type any other way.
     *
     * @param list<Type> $parameters The types the PHP callable receives, in order. A function that is called as a
     *     receiver function -- `foo:string.substr:string(0, 3)` -- receives the expression it's called on as the first
     *     of them; see {@see Signature::receiverType()} and {@see Signature::argumentTypes()}.
     * @param list<string> $typeVariables The names this function type's own `fn<...>` binder declares, in the order
     *     written -- what {@see self::toString()} prints back, and what {@see Signature::instantiateForCall()} has
     *     something to substitute for. A variable named inside $return or $parameters that isn't listed here is one
     *     no binder of this type's own quantifies, which {@see self::asFunction()} rejects once the binder that
     *     would have to quantify it is known -- see that method's own docblock for why not here.
     * @throws InvalidArgumentException if $return or a parameter contains, at any depth, a function type that
     *     declares a binder of its own -- the same rank-1 rule {@see Parser\TypeResolution::resolveSignature()}
     *     enforces when a signature is written as a type string, enforced here too so a signature built directly
     *     through this API can't nest a binder the parser would reject. Left unenforced, {@see self::substitute()}
     *     would walk straight through the inner binder's own scope when the outer signature is instantiated,
     *     replacing that binder's variables with whatever the outer call decided instead of leaving them for a call
     *     on the inner function to decide -- corrupting the inner signature rather than rejecting the type.
     */
    public static function func(self $return, array $parameters = [], array $typeVariables = []): self
    {
        self::checkBinderScope($return, null);
        foreach ($parameters as $parameter) {
            self::checkBinderScope($parameter, null);
        }
        return new self('fn', signature: new Signature($return, $parameters, $typeVariables));
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
        return new self('Struct', fields: $fields);
    }

    /**
     * The message {@see self::checkBinderScope()} rejects a function type nested inside another one's parameters or
     * return type with -- worded once here, rather than separately by {@see self} and
     * {@see Parser\TypeResolution::resolveSignature()}, the two places a signature can be built and so the two
     * places this rule is enforced.
     *
     * @internal
     * @psalm-internal Eventjet\Ausdruck
     */
    public static function nestedBinderMessage(): string
    {
        return 'A function type nested inside another one can\'t bind type variables of its own: a variable is '
            . 'quantified once, by whichever function type encloses it';
    }

    /**
     * {@see self::func()}'s two checks, one traversal: a function type reachable from $type's own args, fields, or
     * alias target -- however deep -- can't declare a binder of its own, which is checkable the moment $type exists,
     * and every variable reachable the same way has to be one $declaredVariables lists, which isn't checkable until
     * the signature's own binder is known. $declaredVariables is null while {@see self::func()} is still building
     * that signature -- there is no complete binder to check a variable against yet, since a variable with no binder
     * of its own, like the `T` in `fn(T) -> bool` nested inside `fn<T>(list<T>, fn(T) -> bool) -> list<T>`, is
     * legitimately deferring to whichever binder ends up enclosing it -- so only the nested-binder rule runs; see
     * {@see self::asFunction()}, which walks again with the enclosing binder's own names once the whole signature
     * exists and there is no "later" left to defer to.
     *
     * @param array<string, true>|null $declaredVariables
     */
    private static function checkBinderScope(self $type, array|null $declaredVariables): void
    {
        if ($type->isVariable) {
            if ($declaredVariables !== null && !array_key_exists($type->name, $declaredVariables)) {
                throw new InvalidArgumentException(
                    sprintf('%s isn\'t declared by this function type\'s own binder, so nothing quantifies it', $type->name),
                );
            }
            return;
        }
        if ($type->signature !== null) {
            if ($type->signature->typeVariables !== []) {
                throw new InvalidArgumentException(self::nestedBinderMessage());
            }
            self::checkBinderScope($type->signature->returnType, $declaredVariables);
            foreach ($type->signature->parameters as $parameter) {
                self::checkBinderScope($parameter, $declaredVariables);
            }
        }
        foreach ($type->args as $arg) {
            self::checkBinderScope($arg, $declaredVariables);
        }
        foreach ($type->fields as $field) {
            self::checkBinderScope($field, $declaredVariables);
        }
        if ($type->aliasFor !== null) {
            self::checkBinderScope($type->aliasFor, $declaredVariables);
        }
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

    #[Override]
    public function __toString(): string
    {
        return $this->toString();
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
        return $this->canonical()->name === 'Option';
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
        if ($self->name === 'fn') {
            $signature = $self->signature;
            $otherSignature = $other->signature;
            assert($signature !== null && $otherSignature !== null);
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
        if ($self->name === 'Struct') {
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
     * {@see Signature} to return here. This is also where a variable {@see self::func()} left unchecked -- one with
     * no binder of its own to defer to, reachable from the return type or a parameter -- is finally checked against
     * the one binder that could quantify it, the signature's own: see {@see self::checkBinderScope()}'s docblock for
     * why this is the first point that check can run, and {@see self::var()}'s for why it can't run any earlier.
     *
     * @throws InvalidArgumentException if a variable reachable from the return type or a parameter isn't one this
     *     signature's own binder declares.
     */
    public function asFunction(): Signature|null
    {
        $signature = $this->canonical()->signature;
        if ($signature === null) {
            return null;
        }
        $declaredVariables = array_fill_keys($signature->typeVariables, true);
        self::checkBinderScope($signature->returnType, $declaredVariables);
        foreach ($signature->parameters as $parameter) {
            self::checkBinderScope($parameter, $declaredVariables);
        }
        return $signature;
    }

    public function isStruct(): bool
    {
        return $this->canonical()->name === 'Struct';
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
        if ($this->isVariable) {
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
        if ($self->name === 'fn') {
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
     * `any`. A function type's own binder is dropped in the same step, explicitly, rather than as a side effect of
     * rebuilding {@see self} without it: {@see Signature::instantiateForCall()} is the only caller that reaches a
     * function type here, and it only ever does so once the binder's variables have already been substituted for
     * what the call decided, which is what makes the binder empty rather than merely unread from this point on.
     *
     * @internal
     * @psalm-internal Eventjet\Ausdruck
     *
     * @param array<string, self> $bindings
     */
    public function substitute(array $bindings): self
    {
        if ($this->isVariable) {
            return $bindings[$this->name] ?? self::any();
        }
        if ($this->signature !== null) {
            return new self(
                $this->name,
                aliasFor: $this->aliasFor?->substitute($bindings),
                signature: new Signature(
                    $this->signature->returnType->substitute($bindings),
                    array_map(
                        static fn(self $parameter): self => $parameter->substitute($bindings),
                        $this->signature->parameters,
                    ),
                    $this->signature->typeVariables,
                ),
            );
        }
        return new self(
            $this->name,
            array_map(static fn(self $arg): self => $arg->substitute($bindings), $this->args),
            $this->aliasFor?->substitute($bindings),
            array_map(static fn(self $field): self => $field->substitute($bindings), $this->fields),
        );
    }

    /**
     * {@see self::bind()}'s Func case: the return type always binds, and a parameter binds unless $actual's own
     * parameter in that position is `any`, which is every {@see Lambda} parameter -- see {@see self::bind()}'s own
     * docblock for why that's the rule rather than a position. $this must already be canonical and named 'fn'; call
     * {@see self::bind()} instead.
     *
     * @param array<string, self> $bindings
     * @return array<string, self>
     */
    private function bindFunction(self $actual, array $bindings): array
    {
        $signature = $this->signature;
        $actualSignature = $actual->signature;
        assert($signature !== null && $actualSignature !== null);
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

    private function toString(): string
    {
        if ($this->name === 'Struct') {
            if ($this->fields === []) {
                return '{}';
            }
            $fields = [];
            foreach ($this->fields as $name => $fieldType) {
                $fields[] = $name . ': ' . $fieldType->toString();
            }
            return '{ ' . implode(', ', $fields) . ' }';
        }
        if ($this->name === 'fn') {
            $signature = $this->signature;
            assert($signature !== null);
            $binder = $signature->typeVariables === [] ? '' : sprintf('<%s>', implode(', ', $signature->typeVariables));
            $params = array_map(static fn(self $arg): string => $arg->toString(), $signature->parameters);
            return sprintf('fn%s(%s) -> %s', $binder, implode(', ', $params), $signature->returnType->toString());
        }
        if ($this->args === []) {
            return $this->name;
        }
        $args = array_map(static fn(self $arg): string => $arg->toString(), $this->args);
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
