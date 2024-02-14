<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Type;

use Stringable;
use TypeError;

/**
 * @template-covariant T
 */
abstract class AbstractType implements Stringable
{
    /**
     * @param self<mixed> $type
     */
    public function equals(self $type): bool
    {
        return $this === $type;
    }

    /**
     * @return T
     * @throws TypeError
     */
    abstract public function assert(mixed $value): mixed;
}
