<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Type;

use Eventjet\Ausdruck\Parser\TypeError;

use function implode;
use function is_callable;
use function sprintf;

/**
 * @template R
 * @extends AbstractType<callable(mixed...): mixed>
 */
final class FunctionType extends AbstractType
{
    /**
     * @param AbstractType<R> $returnType
     * @param list<AbstractType<mixed>> $parameterTypes
     */
    public function __construct(public readonly AbstractType $returnType, public readonly array $parameterTypes = [])
    {
    }

    public function __toString(): string
    {
        return sprintf(
            'func(%s): %s',
            implode(', ', $this->parameterTypes),
            $this->returnType,
        );
    }

    public function assert(mixed $value): callable
    {
        if (!is_callable($value)) {
            throw new TypeError('Value is not callable');
        }
        /**
         * @param mixed ...$params
         * @return R
         */
        $fn = function (mixed ...$params) use ($value): mixed {
            return $this->returnType->assert($value(...$params));
        };
        return $fn;
    }

    public function equals(AbstractType $type): bool
    {
        if (!$type instanceof self) {
            return false;
        }
        if (!$this->returnType->equals($type->returnType)) {
            return false;
        }
        foreach ($this->parameterTypes as $i => $parameterType) {
            if ($parameterType->equals($type->parameterTypes[$i])) {
                continue;
            }
            return false;
        }
        return true;
    }
}
