<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Formatter;

use Eventjet\Ausdruck\Expression;

/**
 * A node that describes its own layout rather than only its printed form. It is what {@see Doc::of()} looks for, and
 * the reason it is an interface rather than a method on {@see Expression}: that class is public API and this one is
 * not, so declaring `doc()` there would put an internal return type on the public surface. A node that doesn't
 * implement this is spelled by what it prints, which is all a node with no break points has to say.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
interface HasDoc
{
    public function doc(): Doc;
}
