<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Closure;

use function array_map;
use function array_values;

/**
 * A callable whose implementation needs its instantiated return type. Expression calls supply their static types;
 * direct PHP calls through Scope infer it from their arguments because they have no declaration at the call site.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class TypeDependentFunction
{
    public function __construct(private readonly Signature $signature, private readonly Closure $implementation)
    {
    }

    public function __invoke(mixed $receiver, mixed ...$arguments): mixed
    {
        $arguments = array_values($arguments);
        $function = $this->specialize(
            Type::fromValue($receiver),
            array_map(Type::fromValue(...), $arguments),
            Type::any(),
        );
        return $function($receiver, ...$arguments);
    }

    /** @param list<Type> $arguments */
    public function specialize(Type $receiver, array $arguments, Type $expected): Closure
    {
        $inferred = $this->signature->instantiateForCall($receiver, $arguments)->returnType;
        $returnType = $expected->isSubtypeOf($inferred) ? $expected : $inferred;
        return fn(mixed ...$values): mixed => ($this->implementation)($returnType, ...$values);
    }
}
