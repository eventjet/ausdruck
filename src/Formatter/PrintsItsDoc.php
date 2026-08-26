<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck\Formatter;

use Eventjet\Ausdruck\Expression;

/**
 * How a node that describes its layout prints. Printing is formatting with every line end declined, so there is
 * nothing here for a node to decide: it is {@see Doc::flat()} over the very document {@see HasDoc::doc()} hands the
 * formatter.
 *
 * Written down once rather than once per node, because that is what makes the agreement between the two structural
 * rather than a convention. {@see Doc} says a node has no way to describe a spelling only one of them would produce;
 * a node that spelled its printed form by hand would have exactly that way, and nothing outside it would notice.
 *
 * It is a trait rather than a default `__toString()` on {@see Expression}, which is where it would otherwise belong:
 * {@see Doc::of()} spells any node that isn't {@see HasDoc} by what it prints, so a default there would print by
 * building a document that prints by building a document, for every node a consumer ever wrote. Only the nodes that
 * do describe one can be given this, and a trait is how they are given it and nothing else is.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
trait PrintsItsDoc
{
    final public function __toString(): string
    {
        return $this->doc()->flat();
    }

    abstract public function doc(): Doc;
}
