<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use Eventjet\Ausdruck\Type;

final class Declarations
{
    /**
     * @param array<string, Type<mixed>> $variables
     */
    public function __construct(
        public readonly Types $types = new Types(),
        public readonly array $variables = [],
    ) {
    }
}
