<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Formatter;

/**
 * Which of its two spellings a document is being given. It is decided per {@see DocKind::Group}, by measuring, and then
 * handed down to everything inside that group until a nested group decides again — so the same {@see DocKind::Line} is
 * a space under one mode and a line end under the other.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
enum DocMode
{
    case Flat;
    case Broken;
}
