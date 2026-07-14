<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Parser;

use Eventjet\Ausdruck\Type;

/**
 * A type the source spells out, together with where it spells it out. Keeping the two together is what lets the type
 * checker blame the annotation itself when it contradicts a declaration, instead of the expression it annotates.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class TypeAnnotation
{
    public function __construct(public readonly Type $type, public readonly Span $location)
    {
    }
}
