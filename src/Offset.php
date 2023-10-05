<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use function array_key_exists;
use function assert;
use function sprintf;

/**
 * @template K of array-key
 * @template V
 * @extends Expression<V>
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final readonly class Offset extends Expression
{
    /** @var Expression<K> */
    private Expression $offset;

    /**
     * @param Expression<array<K, V>> $array
     * @param Expression<K> | K $offset
     */
    public function __construct(public Expression $array, Expression|string|int $offset)
    {
        $this->offset = $offset instanceof Expression ? $offset : Expr::literal($offset);
    }

    public function __toString(): string
    {
        /** @psalm-suppress ImplicitToStringCast */
        return sprintf('%s[%s]', $this->array, $this->offset);
    }

    public function evaluate(Scope $scope): mixed
    {
        $array = $this->array->evaluate($scope);
        $offset = $this->offset->evaluate($scope);
        if (!array_key_exists($offset, $array)) {
            throw new EvaluationError(sprintf('Offset %s does not exist', $offset));
        }
        return $array[$offset];
    }

    public function equals(Expression $other): bool
    {
        return $other instanceof self
            && $this->array->equals($other->array)
            && $this->offset->equals($other->offset);
    }

    public function getType(): Type
    {
        $arrayType = $this->array->getType();
        $name = $arrayType->name;
        assert($name === 'list' || $name === 'map');
        /** @var Type<V> $type */
        $type = $name === 'list' ? $arrayType->args[0] : $arrayType->args[1];
        return $type;
    }
}
