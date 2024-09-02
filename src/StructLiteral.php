<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Span;

use function array_key_exists;
use function array_map;
use function count;
use function implode;
use function sprintf;

/**
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class StructLiteral extends Expression
{
    /**
     * @param array<string, Expression> $fields
     */
    public function __construct(public readonly array $fields, public readonly Span $location)
    {
    }

    public function __toString(): string
    {
        $fieldStrings = [];
        foreach ($this->fields as $name => $value) {
            $fieldStrings[] = sprintf('%s: %s', $name, $value);
        }
        return sprintf('{%s}', implode(', ', $fieldStrings));
    }

    public function location(): Span
    {
        return $this->location;
    }

    public function evaluate(Scope $scope): mixed
    {
        return (object)array_map(static fn(Expression $value): mixed => $value->evaluate($scope), $this->fields);
    }

    public function equals(Expression $other): bool
    {
        if (!$other instanceof self) {
            return false;
        }
        if (count($this->fields) !== count($other->fields)) {
            return false;
        }
        foreach ($this->fields as $name => $value) {
            if (array_key_exists($name, $other->fields) && $value->equals($other->fields[$name])) {
                continue;
            }
            return false;
        }
        return true;
    }

    public function getType(): Type
    {
        return Type::struct(array_map(static fn(Expression $value) => $value->getType(), $this->fields));
    }
}
