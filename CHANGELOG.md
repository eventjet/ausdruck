# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
While the version stays below `1.0.0`, a minor bump (`0.x`) may carry breaking
changes; see [UPGRADING.md](UPGRADING.md) for the migration steps behind each one.

This changelog begins at 0.3.0. Earlier releases are recorded in the
[git tags](https://github.com/eventjet/ausdruck/tags).

## [0.3.0] - Unreleased

A breaking release that rounds out the operator set and the type system. Most 0.2
expressions and integrations keep working unchanged; the migration notes for the
cases that don't are in [UPGRADING.md](UPGRADING.md).

### Added

- Arithmetic operators `+`, `*`, `/`, and `%`, with a defined precedence table and
  `( )` for grouping.
- `/` and `%` answer an `Option`: `int / int` is `Option<int>`, with `none` for a
  zero divisor (and for `PHP_INT_MIN / -1`).
- Comparison operators `!==`, `>=`, `<`, and `<=`.
- Prefix logical NOT, `!`.
- Inferred function return types: the return-type annotation on a call is now
  optional, so `foo:list<string>.count()` works. The explicit
  `foo:list<string>.count:int()` still works and is checked against the inferred type.
- Function type syntax `fn(A, B) -> R`, and generic signatures `fn<T, U>(...)` with
  call-site inference.
- PHP-side `Type::var()`, `Signature`, and `Declarations(functions: [...])` for
  declaring function and generic signatures.
- Declared signatures for the `filter` and `unwrap` built-ins: both already existed
  but could not be typed until generics, and are now type-checked at parse time.
- New `Expression` builder methods: `add`, `multiply`, `divide`, `modulo`, `neq`,
  `lt`, `gte`, `lte`, and `not`.

### Changed

- `&&` now binds tighter than `||`, and each is left-associative. In 0.2 they shared
  one right-grouping precedence level, so `a && b || c` changes meaning from
  `a && (b || c)` to `(a && b) || c`. **Behavioral break.**
- Integer arithmetic that leaves the `int` range is now an `EvaluationError` instead
  of silently widening to `float`. An `int`-typed expression yields an `int` or fails.
  **Behavioral break.**
- Evaluated values are now compared strictly, including their PHP type. **Behavioral
  break.**
- `Type`'s representation is private. The public readonly properties `$name`, `$args`,
  `$aliasFor`, and `$fields` are gone; use `isStruct()`, `getFieldType()`, `isOption()`,
  `asFunction()`, `equals()`, `isSubtypeOf()`, `assert()`, and `(string) $type` instead.
- `Expression`'s concrete builder combinators (`eq`, `neq`, `add`, `subtract`,
  `multiply`, `divide`, `modulo`, `gt`, `lt`, `gte`, `lte`, `or_`, `and_`, `not`,
  `call`, `matchesType`, `isSubtypeOf`) are now `final` and all return `self` rather
  than an internal concrete node type. `Expression` itself stays subclassable.
- Function types render as `fn(int) -> string` (was `func(int): string`), and printing
  follows precedence, so an expression mixing `&&` and `||` may render with different
  parentheses — always in a form the parser reads back to the same tree.
- Marked internal-only machinery `@internal`, placing it outside the compatibility
  promise: `Parser\TypeParser`, `Parser\TypeHint`, `Parser\ParsedToken`, `Negative`,
  `AbstractLiteral`, `ListLiteral`, and `LocationTrait`.

### Removed

- `Type::some()` — an identity function that never wrapped its argument in an `Option`.
  Use `Type::option()` to build `Option<T>`, or drop the call and use the argument.
- `Type::returnType()` — read a function type through `Type::asFunction()`, which
  returns a `Signature` exposing the return type, receiver, and parameters.
- `Parser\Types::resolve()` — resolving a type name against declared aliases is handled
  inside the parser; construct `new Types([...])` and pass it to `ExpressionParser::parse()`.
- `Parser\Delimiters` — an internal token detail with no role in the public API.
- The `Eq` and `Gt` node classes — `@internal` subclasses of `Comparison` that folded
  into it once `eq()` and `gt()` began returning `self`.

[0.3.0]: https://github.com/eventjet/ausdruck/compare/0.2.4...HEAD
