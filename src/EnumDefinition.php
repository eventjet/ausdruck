<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use InvalidArgumentException;

use function array_diff;
use function array_keys;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function preg_match;
use function sprintf;

/** A nominal sum type. Variants carry zero or more positional fields.
 * @api
 */
final class EnumDefinition
{
    /**
     * @param list<string> $parameters
     * @param array<string, list<Type>> $variants
     */
    public function __construct(
        public readonly string $name,
        public readonly array $parameters,
        public readonly array $variants,
    ) {
        if ($variants === [] || count(array_unique($parameters)) !== count($parameters)) {
            throw new InvalidArgumentException('An enum needs variants and distinct type parameters');
        }
        foreach ([$name, ...$parameters, ...array_keys($variants)] as $identifier) {
            if (preg_match('/^[A-Za-z_][A-Za-z_0-9]*$/D', $identifier) !== 1) {
                throw new InvalidArgumentException('Invalid enum identifier: ' . $identifier);
            }
        }
        foreach ($parameters as $parameter) {
            Type::var($parameter);
            if ($parameter === $name) {
                throw new InvalidArgumentException('Enum parameter shadows its type name');
            }
        }
        $used = [];
        foreach ($variants as $fields) {
            foreach ($fields as $field) {
                $used = $field->collectVariables($used);
                if (array_diff(array_keys($field->collectVariables([])), $parameters) !== []) {
                    throw new InvalidArgumentException('Enum field contains an undeclared type parameter');
                }
            }
        }
        if (array_diff($parameters, array_keys($used)) !== []) {
            throw new InvalidArgumentException('Enum contains an unused type parameter');
        }
    }

    public function type(Type ...$arguments): Type
    {
        if (count($arguments) !== count($this->parameters)) {
            throw new InvalidArgumentException('Wrong type argument count for ' . $this->name);
        }
        return Type::of(new EnumShape($this, array_values($arguments)));
    }

    /** @param list<Type> $fields */
    public function infer(string $variant, array $fields): Type
    {
        $expected = $this->variants[$variant] ?? throw new InvalidArgumentException('Unknown variant ' . $variant);
        if (count($expected) !== count($fields)) {
            throw new InvalidArgumentException('Wrong field count for ' . $variant);
        }
        $bindings = [];
        foreach ($expected as $index => $field) {
            $bindings = $field->bind($fields[$index], $bindings);
        }
        foreach ($this->parameters as $parameter) {
            $bindings[$parameter] ??= Type::never();
        }
        foreach ($expected as $index => $field) {
            if (!$fields[$index]->isSubtypeOf($field->substitute($bindings))) {
                throw new InvalidArgumentException(sprintf('Invalid field %d of %s', $index + 1, $variant));
            }
        }
        return $this->type(...array_map(static fn(string $p): Type => $bindings[$p], $this->parameters));
    }

    public function value(string $variant, mixed ...$fields): EnumValue
    {
        $fields = array_values($fields);
        return new EnumValue($this->infer($variant, array_map(Type::fromValue(...), $fields)), $variant, $fields);
    }
}
