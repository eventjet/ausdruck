# Changelog

All notable changes to this project are documented in this file. It is generated from
[Conventional Commits](https://www.conventionalcommits.org/) by
[release-please](https://github.com/googleapis/release-please), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

While the version stays below `1.0.0`, a minor bump (`0.x`) may carry breaking
changes; see [UPGRADING.md](UPGRADING.md) for the migration steps behind each one.

This changelog begins at 0.3.0. Earlier releases are recorded in the
[git tags](https://github.com/eventjet/ausdruck/tags).

## [0.4.0](https://github.com/eventjet/ausdruck/compare/0.3.1...0.4.0) (2026-09-07)


### ⚠ BREAKING CHANGES

* `flatten` is now a predefined function. An integration that declared its own now gets "Can't shadow predefined functions: flatten" from `Scope` and "Can't override built-in function flatten" from `Declarations`.

### Features

* add an expression formatter ([#107](https://github.com/eventjet/ausdruck/issues/107)) ([5cdd0c9](https://github.com/eventjet/ausdruck/commit/5cdd0c99111fdcd0f47259591c1a0b26af215ae6))
* add the flatten built-in ([e0627b5](https://github.com/eventjet/ausdruck/commit/e0627b55f07c580f3f7d1275bbc42e37eba41ef8))


### Continuous Integration

* match the release title to the unprefixed tag ([#114](https://github.com/eventjet/ausdruck/issues/114)) ([ad103d5](https://github.com/eventjet/ausdruck/commit/ad103d5c2dee8c3257cbecdf01ebd85fcae28083))

## [0.3.1](https://github.com/eventjet/ausdruck/compare/0.3.0...0.3.1) (2026-09-07)


### Documentation

* warn that the override marker activates anywhere in a description ([#112](https://github.com/eventjet/ausdruck/issues/112)) ([b6e84a3](https://github.com/eventjet/ausdruck/commit/b6e84a301779a7148c7402abf72931a3ecb2c52a))


### Continuous Integration

* automate releases with release-please ([#110](https://github.com/eventjet/ausdruck/issues/110)) ([ca0740c](https://github.com/eventjet/ausdruck/commit/ca0740cf7e1c8666e7035b3e1331be4d87b27cdf))

## [0.3.0](https://github.com/eventjet/ausdruck/compare/0.2.4...0.3.0) (2026-07-24)

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
- `int` subtraction and negation that leave the `int` range are now an `EvaluationError`
  instead of silently widening to `float`, so an `int`-typed expression yields an `int`
  or fails. The new `+` and `*` apply the same rule. **Behavioral break.**
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
  `AbstractLiteral`, `ListLiteral`, and `LocationTrait`. `Parser\Types::resolve()` is
  `@internal` too (it takes a `TypeNode`, which only the `@internal` `TypeParser`
  constructs); the `Types` class itself stays part of the public API.

### Removed

- `Type::some()` — an identity function that never wrapped its argument in an `Option`.
  Use `Type::option()` to build `Option<T>`, or drop the call and use the argument.
- `Type::returnType()` — read a function type through `Type::asFunction()`, which
  returns a `Signature` exposing the return type, receiver, and parameters.
- `Parser\Delimiters` — an internal token detail with no role in the public API.
- The `Eq` and `Gt` node classes — the `@internal` concrete nodes that `eq()` and `gt()`
  returned in 0.2; now that those builders return `self`, they fold into `Comparison`.
