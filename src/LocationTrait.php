<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use Eventjet\Ausdruck\Parser\Span;

trait LocationTrait
{
    private readonly Span $location;

    public function location(): Span
    {
        return $this->location;
    }
}
