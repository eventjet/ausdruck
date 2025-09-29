<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use Eventjet\Ausdruck\Type;
use Override;
use Stringable;

use function sprintf;

final class TypeHint implements Stringable
{
    public function __construct(public readonly Type $type, public readonly bool $explicit)
    {
    }

    #[Override]
    public function __toString(): string
    {
        if (!$this->explicit) {
            return '';
        }
        return sprintf(':%s', $this->type);
    }
}
