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
use function array_map;
use function array_shift;
use function array_slice;
use function array_values;
use function assert;
use function count;
use function get_object_vars;
use function gettype;
use function implode;
use function in_array;
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
     * variables of the same name in one signature are the same variable. A name a type constructor already spells,
     * like `int` or `list`, is not a variable name: {@see Parser\TypeResolution::checkTypeVariable()} rejects it where
     * a signature is written as a type string, and this rejects it here too, so the same rule holds for a signature
     * built directly through this API.
     *
     * @throws InvalidArgumentException if $name is a type the language spells itself.
     */
    public static function var(string $name): self
    {
        if (TypeConstructor::tryFrom($name) !== null) {
            throw new InvalidArgumentException(TypeConstructor::reservedNameMessage($name));
        }
        return new self($name, isVariable: true);
    }

    /**
     * A function type keeps its return type and its parameters in one list of args: args[0] is the return type, and
     * everything after it is a parameter. {@see Signature} is the only thing that knows that layout; call
     * {@see self::asFunction()} to read a function type instead of reaching into its args.
     *
     * @param list<Type> $parameters The types the PHP callable receives, in order. A function that is called as a
     *     receiver function -- `foo:string.substr:string(0, 3)` -- receives the expression it's called on as the first
     *     of them; see {@see Signature::receiverType()} and {@see Signature::argumentTypes()}.
     */
    public static function func(self $return, array $parameters = []): self
    {
        return new self('Func', [$return, ...$parameters]);
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
     * The `<T, U>` a function type with free variables prints itself with, one place for every name it's ever
     * referred to by within this signature, in the order each is first read left to right -- parameters before the
     * return type, the order the signature itself is written in. Empty when there is nothing to quantify, which is
     * every function type once {@see Signature::instantiateForCall()} has substituted it.
     */
    private static function binder(self $func): string
    {
        // args[0] is the return type and the rest are parameters -- see {@see self::func()} -- but the signature is
        // written parameters first, so that's the order first occurrence is read in too.
        $returnType = $func->args[0];
        $parameters = array_slice($func->args, 1);
        $names = [];
        foreach ([...$parameters, $returnType] as $node) {
            foreach ($node->freeVariableNames() as $name) {
                if (!in_array($name, $names, true)) {
                    $names[] = $name;
                }
            }
        }
        return $names === [] ? '' : sprintf('<%s>', implode(', ', $names));
    }

    #[Override]
    public function __toString(): string
    {
        return $this->toString(true);
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
        return $this->name === 'Option';
    }

    public function isSubtypeOf(self $other): bool
    {
        $self = $this->canonical();
        $other = $other->canonical();
        if ($self->isNone()) {
            return $other->isNone() || $other->isOption();
        }
        if ($other->isOption() && (!$self->isOption() && $self->name !== 'Some')) {
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
        if ($self->name === 'Func') {
            $signature = $self->asFunction();
            $otherSignature = $other->asFunction();
            assert($signature !== null && $otherSignature !== null);
            if (!$signature->returnType()->isSubtypeOf($otherSignature->returnType())) {
                return false;
            }
            $params = $signature->parameterTypes();
            $otherParams = $otherSignature->parameterTypes();
            foreach ($params as $i => $param) {
                $otherParam = $otherParams[$i] ?? null;
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
     * This type as a function's signature, or null if it isn't one: only {@see self::func()} builds a type
     * {@see Signature} accepts. Everything that reads a function type -- its return type, its receiver, the arguments
     * a call passes, generic instantiation -- goes through the {@see Signature} this returns rather than this type's
     * args directly. This is the one place that sees through an alias for {@see Signature}, which is why it, and not
     * {@see Signature::tryFrom()}, is the way to get one.
     */
    public function asFunction(): Signature|null
    {
        return Signature::tryFrom($this->canonical());
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
     * accepts whatever that variable turns out to be, which is exactly what {@see self::isSubtypeOf()}'s own Func case
     * already relies on when the *declared* parameter is `any`; here it's the value's parameter that is, and the
     * variable is left for a later, real parameter -- or the receiver, walked first below -- to decide. See
     * {@see Signature::instantiateForCall()} for why first-wins is the useful half of the two everywhere else.
     *
     * This is the recursive walk that applies to any type, not just a function's parameters, which is why it lives
     * here rather than on {@see Signature}; nothing outside the type system should call it directly.
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
        // Two types of the same shape can still be of different sizes: a lambda declares fewer parameters than the
        // signature asks for, and a struct is written with fewer fields than one reaches into. What the two have in
        // common is what there is to learn from.
        foreach (array_slice($self->args, 0, count($actual->args)) as $index => $arg) {
            $actualArg = $actual->args[$index];
            if ($self->name === 'Func' && $index > 0 && $actualArg->canonical()->name === 'any') {
                continue;
            }
            $bindings = $arg->bind($actualArg, $bindings);
        }
        foreach (array_intersect_key($self->fields, $actual->fields) as $name => $field) {
            $bindings = $field->bind($actual->fields[$name], $bindings);
        }
        return $bindings;
    }

    /**
     * This type with every variable replaced by what it was bound to, and every variable nothing bound replaced by
     * `any`.
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
        return new self(
            $this->name,
            array_map(static fn(self $arg): self => $arg->substitute($bindings), $this->args),
            $this->aliasFor?->substitute($bindings),
            array_map(static fn(self $field): self => $field->substitute($bindings), $this->fields),
        );
    }

    /**
     * $withBinder is false for every call {@see self::__toString()} doesn't make itself: a nested function type prints
     * without one because its variables were never its own to quantify -- they're quantified once, by whichever
     * enclosing signature's binder introduced them, per {@see self::var()}.
     */
    private function toString(bool $withBinder): string
    {
        if ($this->name === 'Struct') {
            $fields = [];
            foreach ($this->fields as $name => $fieldType) {
                $fields[] = $name . ': ' . $fieldType->toString(false);
            }
            return '{ ' . implode(', ', $fields) . ' }';
        }
        if ($this->name === 'Func') {
            assert(count($this->args) > 0);
            $args = $this->args;
            $returnType = array_shift($args);
            $binder = $withBinder ? self::binder($this) : '';
            $params = array_map(static fn(self $arg): string => $arg->toString(false), $args);
            return sprintf('fn%s(%s) -> %s', $binder, implode(', ', $params), $returnType->toString(false));
        }
        if ($this->args === []) {
            return $this->name;
        }
        $args = array_map(static fn(self $arg): string => $arg->toString(false), $this->args);
        return sprintf('%s<%s>', $this->name, implode(', ', $args));
    }

    /**
     * The names of every variable reachable from this type, in first-occurrence order. A struct's fields and an
     * alias's own arguments are walked the same way its args are; what the alias stands for is not, because a
     * variable can only be free within the one signature that quantifies it, and an alias is never written inside a
     * binder's scope -- see {@see self::substitute()} for the one place that does have to see through the alias.
     *
     * @return list<string>
     */
    private function freeVariableNames(): array
    {
        if ($this->isVariable) {
            return [$this->name];
        }
        $names = [];
        foreach ([...$this->args, ...array_values($this->fields)] as $child) {
            foreach ($child->freeVariableNames() as $name) {
                if (!in_array($name, $names, true)) {
                    $names[] = $name;
                }
            }
        }
        return $names;
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
}
