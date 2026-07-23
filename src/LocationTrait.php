<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Span;

/**
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
trait LocationTrait
{
    private readonly Span $location;

    public function location(): Span
    {
        return $this->location;
    }
}
