<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

/**
 * Which shape a {@see Type} is, told apart by a field of its own rather than by what {@see Type::$name} happens to
 * spell: a variable or an alias can be named `Struct` without being misread as a struct, because {@see Type::isStruct()}
 * and the other kind-based checks read {@see self::$kind}, not the name.
 *
 * A function type has no case of its own here: {@see Type::$signature} is non-null exactly when {@see Type::func()}
 * built it, which is already an unambiguous signal nothing else can forge -- {@see Type::var()} and
 * {@see Type::alias()} never set it -- and checking it directly, rather than a kind, lets the type checker narrow
 * $signature from nullable to a {@see Signature} without an `assert()`.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
enum TypeKind
{
    /**
     * Everything with a name a {@see TypeConstructor} spells, every alias -- which prints as its own name rather than
     * the kind of type it stands for, see {@see Type::alias()} -- and every function type, told apart from these by a
     * non-null {@see Type::$signature} instead of by a case here.
     */
    case Named;
    /**
     * A struct, written as its fields between `{ }` rather than as a name -- see {@see Type::struct()}.
     */
    case Struct;
    /**
     * A type variable built by {@see Type::var()} -- a placeholder a generic signature's call site decides, not a
     * type of its own.
     */
    case Variable;
}
