<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

/**
 * Which shape a {@see Type} is, told apart from a field of its own rather than from what {@see Type::$name} happens to
 * spell: before this existed, {@see Type} read `$this->name === 'fn'` or `'Struct'` to mean "this is a function type"
 * or "this is a struct type", which made those two strings magic values a real type constructor could never spell
 * (see the deleted `TypeConstructor::isReservedName()`) and a variable or an alias could never be named either,
 * on pain of being misread as the type it merely shared a name with. A variable named `Struct` is now just a variable
 * named `Struct` -- {@see Type::isStruct()} asks {@see self::Struct}, not the name, so there is nothing left for it to
 * be misread as.
 *
 * A function type's kind is {@see self::Func}, but the dispatch sites that used to read `$this->name === 'fn'` read
 * `$this->signature !== null` instead: the two are equivalent by construction -- only {@see Type::func()} ever sets a
 * signature, and it always sets this kind alongside it -- and the nullable-property check narrows for the type
 * checker without an `assert()`, where a `$this->kind === self::Func` check would still leave `$this->signature`
 * looking nullable to it.
 *
 * @internal
 * @psalm-internal Eventjet\Ausdruck
 */
enum TypeKind
{
    /**
     * Everything with a name a {@see TypeConstructor} spells, plus every alias: `int`, `list<T>`, `Option<T>`, and
     * `Type::alias($name, $type)` regardless of what $type stands for -- an alias prints as its own name
     * ({@see Type::toString()}), not as the kind of the type behind it.
     */
    case Named;
    /**
     * A struct, written as its fields between `{ }` rather than as a name -- see {@see Type::struct()}.
     */
    case Struct;
    /**
     * A function type built by {@see Type::func()}, which is also the only place this kind and a non-null
     * {@see Type::$signature} are ever set, together.
     */
    case Func;
    /**
     * A type variable built by {@see Type::var()} -- a placeholder a generic signature's call site decides, not a
     * type of its own.
     */
    case Variable;
}
