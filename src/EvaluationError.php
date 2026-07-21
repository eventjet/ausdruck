<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use RuntimeException;

use function sprintf;

final class EvaluationError extends RuntimeException
{
    /**
     * An int-typed expression whose result doesn't exist in int. Both operators that can raise it — {@see Arithmetic}
     * and {@see Negative} — say so the same way, because it is the same failure: the node's type promises an int, and
     * the honest answer is that there is no int to give rather than a float the type doesn't allow.
     *
     * @internal
     * @psalm-internal Eventjet\Ausdruck
     */
    public static function outsideIntRange(Expression $expression): self
    {
        return new self(sprintf('%s leaves the int range', $expression));
    }
}
