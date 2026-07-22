<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

use function implode;
use function sprintf;

/**
 * The one place a type's printed grammar is spelled, shared by the two things that print one: {@see Type} prints a
 * fully resolved type, and {@see Parser\TypeNode} (with {@see Parser\FunctionTypeNode} and
 * {@see Parser\StructTypeNode}) prints one that's only gotten as far as parsing -- {@see Parser\TypeResolution::checkArity()}
 * prints a node that, by definition, failed to become a {@see Type}, so neither side can be built in terms of the
 * other. Both sides spell the same three shapes the same way -- an empty struct prints as `{}` on both, for
 * instance -- which is what keeps an application, a function type and a struct type from drifting into two
 * different spellings of the same shape.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
final class TypeSyntax
{
    /**
     * A name applied to its type arguments, e.g. `map<T, U>` or a bare `int` with none.
     *
     * @param list<string> $args
     */
    public static function application(string $name, array $args): string
    {
        return $args === [] ? $name : sprintf('%s<%s>', $name, implode(', ', $args));
    }

    /**
     * A struct's fields between `{ }`, e.g. `{ name: string }` -- or `{}`, with none.
     *
     * @param list<string> $fields
     */
    public static function struct(array $fields): string
    {
        return $fields === [] ? '{}' : sprintf('{ %s }', implode(', ', $fields));
    }

    /**
     * A function type, e.g. `fn<T>(int) -> T` -- or with no binder at all, `fn(int) -> string`.
     *
     * @param list<string> $binder
     * @param list<string> $parameters
     */
    public static function func(array $binder, array $parameters, string $returnType): string
    {
        $binderString = $binder === [] ? '' : sprintf('<%s>', implode(', ', $binder));
        return sprintf('fn%s(%s) -> %s', $binderString, implode(', ', $parameters), $returnType);
    }
}
