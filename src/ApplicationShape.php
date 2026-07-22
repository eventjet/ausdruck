<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

/**
 * A name applied to its type arguments -- `list<T>`, `map<K, V>`, or a bare `int` with none. Every
 * {@see TypeConstructor} other than `Option` and `Some` themselves, plus `None`, `any` and `never`, is one of these;
 * see {@see Type::listOf()}, {@see Type::mapOf()}, {@see Type::option()} and the other scalar factories.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class ApplicationShape implements TypeShape
{
    /**
     * @param list<Type> $args
     */
    public function __construct(
        public readonly array $args = [],
    ) {
    }
}
