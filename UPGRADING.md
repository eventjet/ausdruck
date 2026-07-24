# Upgrading

## From 0.2 to 0.3

Ausdruck 0.3 is a breaking release. Most 0.2 expressions and integrations keep working
unchanged — `ExpressionParser::parse()`, `Scope`, and the `Expression` builder all keep
their 0.2 signatures, and 0.3 only adds to them. This guide covers the cases that change.

Two of the changes are behavioral: an expression that parsed or evaluated in 0.2 does
something different in 0.3. Read those first — they are the ones a passing test suite can
miss.

### Behavioral changes

#### `&&` now binds tighter than `||`

In 0.2, `&&` and `||` shared one precedence level and grouped to the right, so
`a && b || c` parsed as `a && (b || c)`. In 0.3 the operators are layered the usual way —
`&&` binds tighter than `||` and each is left-associative — so the same text parses as
`(a && b) || c`.

Any expression that mixes `&&` and `||` without parentheses changes meaning. Add the
parentheses that spell out the 0.2 grouping where you relied on it:

```
# 0.2 meaning of `a:bool && b:bool || c:bool`
a:bool && (b:bool || c:bool)
```

Expressions that use only one of the two operators, or that already parenthesize the mix,
are unaffected.

#### Integer arithmetic that leaves the `int` range is now an evaluation error

In 0.2, `a:int - b:int` and `-a:int` used PHP's native operators, which silently return a
`float` when the result leaves the `int` range (for example `PHP_INT_MIN - 1`). An
`int`-typed expression could therefore hand back a `float`. In 0.3 each `int` operator
checks its result up front and raises an `EvaluationError` when it does not fit, so
evaluating an `int`-typed expression yields an `int` or fails — it never widens.

If you depended on the silent widening, convert the operands to `float` before the
operation so the arithmetic is `float` arithmetic (which goes to `INF` at the boundary, as
PHP does), or handle the `EvaluationError`. See the "Int overflow" section of the README
for the full rule, including how it differs from the `none` that `/` and `%` produce.

### API changes

#### `Type` no longer exposes its internals as public properties

`Type`'s representation is now private. The public readonly properties `$name`, `$args`,
`$aliasFor`, and `$fields` are gone. Ask the type through its methods instead:

- `isStruct()` and `getFieldType(string $name): ?Type` replace reading `$fields`.
- `isOption()` replaces checking the old `$name` discriminator for an option, and
  `asFunction(): ?Signature` replaces reading a function type's `$args`.
- `equals()`, `isSubtypeOf()`, and `assert()` — carried over from 0.2 — are the supported
  way to test identity and assignability.
- `(string) $type` renders the type.

There is no public accessor that hands back a `list`'s or `map`'s element type, or an
alias's target, as a raw `Type`. If you read those off `$args`/`$aliasFor`, open an issue
describing what you need so it can get a proper accessor.

#### `Type::returnType()` is replaced by `Type::asFunction()`

`Type::returnType()` is removed. A function type is now read through a `Signature`:
`Type::asFunction()` returns the `Signature` (or `null` for a non-function type), and the
signature exposes the return type, receiver, and parameters.

```php
# 0.2
$return = $type->returnType();

# 0.3
$return = $type->asFunction()?->returnType;          // Type|null
$params = $type->asFunction()?->argumentTypes();     // list<Type>
```

#### `Type::some()` is removed

`Type::some()` is gone. It took a type and returned it unchanged — an identity function
that never wrapped anything in an `Option`, despite its name. If you called it to build an
option type, use `Type::option()`, which actually constructs `Option<T>`. If you relied on
the identity behavior, drop the call and use the argument directly.

```php
# 0.2
$t = Type::some(Type::int());   // returned Type::int() unchanged — not an option

# 0.3
$t = Type::option(Type::int()); // Option<int>, if that is what you meant
```

#### Some parser members are no longer public API

- `Parser\TypeParser` is now `@internal`. Parse through `ExpressionParser` — its public
  API is unchanged.
- `Parser\Types::resolve()` is now `@internal`. It takes a `TypeNode`, which only the
  `@internal` `TypeParser` constructs, so no outside caller could reach it anyway.
  Constructing `new Types([...])` and passing it to `ExpressionParser::parse()` works as
  before — the `Types` class itself stays part of the public API.
- `Parser\Delimiters` is removed. It was an internal token detail with no role in the
  public API.
- `Parser\TypeHint` and `Parser\ParsedToken` are now `@internal`. They are parser plumbing
  the public API never handed out; parse through `ExpressionParser`.

#### More node-level plumbing is now `@internal`

Several classes that were technically public but only ever existed as internal machinery
now carry `@internal`, so they sit outside the compatibility promise and may change or be
removed in any release: `Negative`, `AbstractLiteral`, `ListLiteral`, and `LocationTrait`.
Build nodes through the `Expression` builder combinators and parse through
`ExpressionParser` rather than naming these directly. As part of sealing them, `Negative`'s
`equals()` and `__toString()` are now `final`.

#### `Expression`'s builder combinators are now `final`

`Expression` stays an open extension point: subclass it and implement `location()`,
`evaluate()`, `equals()`, and `getType()` to add a node, exactly as before. What changed is
that its concrete builder combinators — `eq`, `neq`, `add`, `subtract`, `multiply`,
`divide`, `modulo`, `gt`, `lt`, `gte`, `lte`, `or_`, `and_`, `not`, `call`, `matchesType`,
and `isSubtypeOf` — are now `final`. They are fixed algorithms that assemble the internal
node set, so a subclass has no reason to override them. If you did override one, move that
logic out of the subclass; nothing else needs to change.

#### `Expression`'s builder combinators all return `self`

In 0.2, nine of the combinators declared a concrete node as their return type — `eq`
returned `Eq`, `add` returned `Add`, `call` returned `Call`, and so on — while the rest
returned `self`. Those node classes are `@internal`, so the surface both leaked internal
symbols and disagreed with itself (`eq` returned `Eq` but its sibling `neq` returned
`self`). In 0.3 every combinator returns `self`.

If you typed a variable or property against one of those concrete returns
(`$c = $a->eq($b);` inferring `Eq`), widen the declaration to `Expression`. The value is
unchanged — only the declared type narrows — so code that already treated the result as an
`Expression` needs no change.

As part of this, the `Eq` and `Gt` classes are removed. They were the `@internal` node
classes that `eq()` and `gt()` returned, existing only to be named by those builders; now
that the builders return `self`, they fold into the `Comparison` node. An `eq(...)` value
already compared equal to the matching `Comparison`, so `equals()` is unaffected.

#### Rendered form of some expressions and types changed

Printing follows precedence, so an expression that mixes `&&` and `||` may render with
different parentheses than it did in 0.2 — always in a form the parser reads back to the
same tree. Function types render differently too: a 0.2 `func(int): string` prints as
`fn(int) -> string` in 0.3. If you persist or compare `(string) $expression` or
`(string) $type`, regenerate the stored strings from 0.3 rather than comparing them against
0.2 output.

### New in 0.3

These are additive — nothing to change on upgrade, listed so you know what became
available. The README documents each in full.

- **More operators:** `+`, `*`, `/`, `%` (arithmetic), `!==`, `>=`, `<`, `<=`
  (comparison), and prefix `!` (logical NOT), with a defined precedence table and grouping
  with `( )`.
- **`/` and `%` return an `Option`:** `int / int` is an `Option<int>`, with `none` for a
  zero divisor (and for `PHP_INT_MIN / -1`). Reach the value with `unwrap` or check it with
  `isSome`.
- **Inferred function return types:** the return-type annotation on a call is now optional.
  `foo:list<string>.count()` works; the explicit `foo:list<string>.count:int()` still works
  and is checked against the inferred type.
- **Function types and generic signatures:** `fn(A, B) -> R` type syntax, `fn<T, U>(...)`
  generic signatures with call-site inference, and the PHP-side `Type::var()`, `Signature`,
  and `Declarations(functions: [...])` to declare them.
- **Declared signatures for `filter` and `unwrap`:** both built-ins already existed but
  could not be typed until generics; they now carry `Signature`s and are type-checked at
  parse time.
- **New `Expression` builder methods:** `add`, `multiply`, `divide`, `modulo`, `neq`, `lt`,
  `gte`, `lte`, `not`.
