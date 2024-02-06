<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use Eventjet\Ausdruck\Type;

final class Declaration
{
    public function __construct(
        public readonly string $name,
        public readonly Type $type,
        public readonly Span $location,
    ) {
    }
}
