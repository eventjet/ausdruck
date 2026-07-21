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
     * call site rather than by the declaration. `head` is declared as `func(Option<T>, [list<T>])`, and a call on a
     * `list<string>` is checked against `func(Option<string>, [list<string>])`; see {@see self::instantiate()}.
     *
     * Variables are quantified at the top of the signature they appear in, so there is no binder to build here and two
     * variables of the same name in one signature are the same variable. A name a type constructor already spells,
     * like `int` or `list`, is not a variable name; nothing checks that here, but {@see Parser\Types} rejects it where
     * a signature is written as a type string.
     */
    public static function var(string $name): self
    {
        return new self($name, isVariable: true);
    }

    /**
     * A function type keeps its return type and its parameters in one list of args: args[0] is the return type, and
     * everything after it is a parameter. Only returnType() and parameterTypes() know that layout, and everything else,
     * in this class and outside it, goes through them.
     *
     * @param list<Type> $parameters The types the PHP callable receives, in order. A function that is called as a
     *     receiver function -- `foo:string.substr:string(0, 3)` -- receives the expression it's called on as the first
     *     of them; see receiverType() and argumentTypes().
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

    #[Override]
    public function __toString(): string
    {
        if ($this->name === 'Struct') {
            $fields = [];
            foreach ($this->fields as $name => $fieldType) {
                /** @psalm-suppress ImplicitToStringCast */
                $fields[] = $name . ': ' . $fieldType;
            }
            return '{ ' . implode(', ', $fields) . ' }';
        }
        if ($this->name === 'Func') {
            assert(count($this->args) > 0);
            $args = $this->args;
            $returnType = array_shift($args);
            return sprintf('func(%s): %s', implode(', ', $args), $returnType);
        }
        return $this->name . ($this->args === [] ? '' : sprintf('<%s>', implode(', ', $this->args)));
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
            if (!$self->returnType()->isSubtypeOf($other->returnType())) {
                return false;
            }
            $params = $self->parameterTypes();
            $otherParams = $other->parameterTypes();
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
     * Returns the return type of a function type.
     *
     * This should only be called on function types. The behavior is undefined for other types.
     */
    public function returnType(): self
    {
        return $this->canonical()->args[0];
    }

    /**
     * The type a receiver function is called on: `substr` is declared as func(string, [string, int, int]) and called as
     * `foo:string.substr:string(0, 3)`, so its receiver type is string. Null if the function declares no parameters at
     * all, which is what makes it unusable as a receiver function.
     *
     * This should only be called on function types. The behavior is undefined for other types.
     */
    public function receiverType(): self|null
    {
        return $this->parameterTypes()[0] ?? null;
    }

    /**
     * The types of the arguments a call passes in parentheses, which are the parameters the receiver doesn't take up.
     *
     * This should only be called on function types. The behavior is undefined for other types.
     *
     * @return list<self>
     */
    public function argumentTypes(): array
    {
        return array_slice($this->parameterTypes(), 1);
    }

    /**
     * This function type with its type variables resolved against the types a call applies it to: the signature the
     * call is actually checked against, with no variables left in it.
     *
     * The parameters are walked in order and a variable keeps the first type that lands on it, so the receiver decides
     * `T` for `func(bool, [list<T>, T])` before the argument is looked at. That order is what makes a lambda argument
     * harmless: a lambda's parameters are typed `any` and would decide nothing useful, but by then the receiver has
     * already decided. A variable no parameter reaches becomes `any`—nothing constrained it, so nothing about the call
     * should be rejected on its account, and an argument that isn't there is reported as the missing argument it is.
     *
     * Nothing here is an error. Where a variable's binding and a later parameter disagree, the substituted signature
     * says what was expected and {@see Expr::call()}'s receiver and argument checks report it, pointing at the
     * expression that's wrong.
     *
     * This should only be called on function types. The behavior is undefined for other types.
     *
     * @param list<self> $arguments The types the call applies, receiver first, in the order {@see self::parameterTypes()}
     *     lists the parameters.
     */
    public function instantiate(array $arguments): self
    {
        $bindings = [];
        // Only the parameters an argument faces have anything to say. A call with the wrong number of arguments is
        // still instantiated, from the ones it does have, so that Expr::call() can report the count against a
        // signature that reads the way the rest of the call decided it should.
        foreach (array_slice($this->parameterTypes(), 0, count($arguments)) as $index => $parameter) {
            $bindings = $parameter->bind($arguments[$index], $bindings);
        }
        return $this->substitute($bindings);
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
     * Every parameter of a function type, receiver included, in the order the PHP callable receives them. Two function
     * types are compared parameter by parameter, so this is the list that matters for subtyping; the split into a
     * receiver and the arguments only matters at a call site.
     *
     * @return list<self>
     */
    private function parameterTypes(): array
    {
        return array_slice($this->canonical()->args, 1);
    }

    /**
     * What $actual tells us about the variables in this type, added to what is already known. Matching is structural
     * and one-way: where the two types have the same shape, the variables on this side take the types facing them,
     * and where they don't, there is nothing to learn and the bindings come back unchanged. $actual is seen through
     * its aliases so that a `Numbers` standing for `list<int>` still binds `T` in a `list<T>`; this side doesn't need
     * the same treatment, because an alias names a complete type and so has no variables under it.
     *
     * The first binding for a variable is the one that's kept. See {@see self::instantiate()} for why that is the
     * useful half of the two.
     *
     * @param array<string, self> $bindings
     * @return array<string, self>
     */
    private function bind(self $actual, array $bindings): array
    {
        if ($this->isVariable) {
            return array_key_exists($this->name, $bindings) ? $bindings : [...$bindings, $this->name => $actual];
        }
        $actual = $actual->canonical();
        if ($this->name !== $actual->name) {
            return $bindings;
        }
        // Two types of the same shape can still be of different sizes: a lambda declares fewer parameters than the
        // signature asks for, and a struct is written with fewer fields than one reaches into. What the two have in
        // common is what there is to learn from.
        foreach (array_slice($this->args, 0, count($actual->args)) as $index => $arg) {
            $bindings = $arg->bind($actual->args[$index], $bindings);
        }
        foreach (array_intersect_key($this->fields, $actual->fields) as $name => $field) {
            $bindings = $field->bind($actual->fields[$name], $bindings);
        }
        return $bindings;
    }

    /**
     * This type with every variable replaced by what it was bound to, and every variable nothing bound replaced by
     * `any`.
     *
     * @param array<string, self> $bindings
     */
    private function substitute(array $bindings): self
    {
        if ($this->isVariable) {
            return $bindings[$this->name] ?? self::any();
        }
        return new self(
            $this->name,
            array_map(static fn(self $arg): self => $arg->substitute($bindings), $this->args),
            $this->aliasFor,
            array_map(static fn(self $field): self => $field->substitute($bindings), $this->fields),
        );
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
