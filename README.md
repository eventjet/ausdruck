# Ausdruck

A small expression engine for PHP.

## Quick start

```
composer require eventjet/ausdruck
```

```php
use Eventjet\Ausdruck\Parser\ExpressionParser;
use Eventjet\Ausdruck\Parser\Types;
use Eventjet\Ausdruck\Type;

$expression = ExpressionParser::parse(
    'joe:MyPersonType.name:string()',
    new Types(['MyPersonType' => Type::listOf(Type::string())]),
);
$scope = new Scope(
    // Passing values to the expression
    ['joe' => ['joe']],
    // Custom function definitions
    ['name' => static fn (array $person): string => $person[0]],
);
$name = $expression->evaluate($scope);
assert($name === 'Joe');
```

## Documentation

### Accessing scope variables

Syntax: `varName:type`

Scope variables are passed from PHP when it calls `evaluate()` on the expression:

```php
use Eventjet\Ausdruck\Parser\ExpressionParser;
use Eventjet\Ausdruck\Scope;

$x = ExpressionParser::parse('foo:int')
    ->evaluate(new Scope(['foo' => 123]));
assert($x === 123); 
```

#### Examples

`foo:int`, `foo:list<string>`

See [Types](#types)

### Literals

- `123`: Integer
- `"foo"`: String
- `1.23`: Float
- `[1, myInt:int, 3]`: List of integers
- `["foo", myString:string, "bar"]`: List of strings

### Operators

Most operators go between two operands of the same type:

| Operator | Description           | Example                  | Note                                            |
|----------|-----------------------|--------------------------|-------------------------------------------------|
| `===`    | Equality              | `foo:string === "bar"`   |                                                 |
| `!==`    | Inequality            | `foo:string !== "bar"`   |                                                 |
| `-`      | Subtraction           | `foo:int - bar:int`      | Operands must be of type `int` or `float`       |
| `+`      | Addition              | `foo:int + bar:int`      | Operands must be of type `int` or `float`       |
| `*`      | Multiplication        | `foo:int * bar:int`      | Operands must be of type `int` or `float`       |
| `/`      | Division              | `foo:int / bar:int`      | See [Division and modulo](#division-and-modulo) |
| `%`      | Modulo                | `foo:int % bar:int`      | See [Division and modulo](#division-and-modulo) |
| `>`      | Greater than          | `foo:int > bar:int`      | Operands must be of type `int` or `float`       |
| `>=`     | Greater than or equal | `foo:int >= bar:int`     | Operands must be of type `int` or `float`       |
| `<`      | Less than             | `foo:int < bar:int`      | Operands must be of type `int` or `float`       |
| `<=`     | Less than or equal    | `foo:int <= bar:int`     | Operands must be of type `int` or `float`       |
| `\|\|`   | Logical OR            | `foo:bool \|\| bar:bool` | Operands must be of type `bool`                 |
| `&&`     | Logical AND           | `foo:bool && bar:bool`   | Operands must be of type `bool`                 |

Equality is spelled `===`, so inequality is `!==`; there is no `==` or `!=`.

Two operators take a single operand, written in front of it:

| Operator | Description | Example     | Note                                         |
|----------|-------------|-------------|----------------------------------------------|
| `-`      | Negation    | `-foo:int`  | The operand must be of type `int` or `float` |
| `!`      | Logical NOT | `!foo:bool` | The operand must be of type `bool`           |

Both prefix operators bind tighter than every binary operator, so each applies only to the expression right next to it
and nothing more: `!foo:bool && bar:bool` is `(!foo:bool) && bar:bool`, and `-foo:int + bar:int` is
`(-foo:int) + bar:int`. That includes calls and field access, which bind tighter still, so a call is negated whole:

```
!names:list<string>.contains:bool(needle:string)
```

Where's the rest? We're implementing more as we need them.

#### Division and modulo

Like the other arithmetic operators, `/` and `%` take two operands of the same numeric type — but the result is an
`Option` of that type: `int / int` is an `Option<int>`. A zero divisor makes it `none` rather than an error, and `/`
has a second `none`: `PHP_INT_MIN / -1`, the one `int` quotient that doesn't fit in an `int`. `%` doesn't share it —
`PHP_INT_MIN % -1` is `0`, a remainder like any other. Get at the value the same way you would with `head`:

```
(a:int / b:int).unwrap:int()
(a:int / b:int).isSome:bool()
(i:int % 3).unwrap:int() === 0
```

`unwrap` fails evaluation on `none`, so use it where a zero divisor can't happen or should be loud; check with
`isSome` where it's a case to handle. Because the result is an `Option`, it doesn't chain into further arithmetic or
comparison without an `unwrap`: `a:int / b:int / c:int` is a type error.

An `int` quotient truncates toward zero: `7 / 2` is `3`, and `-7 / 2` is `-3`. The remainder takes the dividend's
sign: `-7 % 3` is `-1`. A `float` remainder is PHP's `fmod()`.

#### Int overflow

PHP's `int` arithmetic isn't closed: a sum, difference or product past the `int` range evaluates to a `float` rather
than wrapping. An `int`-typed expression that answered one would be handing back a value of a type it doesn't have, so
each operator decides up front whether its result exists and fails evaluation when it doesn't:

```
a:int + b:int
```

with `a` at `PHP_INT_MAX` and `b` at `1` is an evaluation error, not `9.2233720368548E+18`. `-`, `*` and unary `-` work
the same way. Evaluating an `int`-typed expression therefore gives you an `int` or nothing at all — the widened value is
never computed, so it can't reach a caller or quietly widen the operator above it either.

Overflow is not an `Option` case. `/` and `%` give `none` where the operands are fine but the result doesn't exist — a
zero divisor, or `PHP_INT_MIN / -1` — which is a case worth handling in an expression. A sum that leaves the range is
instead a mismatch between the values and the type they were declared with, so it's an error to fix rather than a branch
to write. `float` arithmetic has neither: it goes to `INF`, as it does in PHP.

#### Precedence

Operators bind from tightest to loosest in this order:

| Operator                           | Associativity   |
|------------------------------------|-----------------|
| `.` (field, method)                | Left            |
| `-` (negation), `!`                | Right           |
| `*`, `/`, `%`                      | Left            |
| `-` (subtraction), `+`             | Left            |
| `===`, `!==`, `>`, `>=`, `<`, `<=` | Non-associative |
| `&&`                               | Left            |
| `\|\|`                             | Left            |

As in most languages, `&&` binds tighter than `||`, so `a:bool && b:bool || c:bool` means
`(a:bool && b:bool) || c:bool`; `*`, `/` and `%` bind tighter than `+` and binary `-`, so `a:int + b:int * c:int`
means `a:int + (b:int * c:int)`. Operators that share a level are left-associative across it:
`a:int - b:int + c:int` means `(a:int - b:int) + c:int`.

The comparison operators are non-associative: `a:int > b:int > c:int` is a syntax error rather than a comparison
against the `bool` that the first comparison produces. Chain with `&&` instead.

#### Grouping

Wrap a sub-expression in parentheses to override the precedence:

```
a:bool && (b:bool || c:bool)
(a:int - b:int) - c:int
(a:int + b:int) * c:int
(a:int > b:int) === c:bool
(a:int / b:int).unwrap:int()
!(a:bool || b:bool)
```

Parentheses only group; they add no node of their own. Redundant ones — a group the precedence would have produced
anyway, like `(a:int - b:int) - c:int` — parse fine and simply disappear, so printing an expression back out puts a
pair of parentheses exactly where the precedence would otherwise regroup the tree, and nowhere else.

Anywhere an expression is expected, it can be a whole one, not only at the top level. List items, struct field values,
function arguments, and lambda bodies are all full expressions, so operators, calls, and field access are available in
all of them:

```
[a:int - 1, 10]
{total: a:int - b:int}
foo:string.substr(a:int - 1, 2)
names:list<string>.contains:bool(user:{ name: string }.name)
{matches: needle:string === haystack:string.substr:string(0, 1) || always:bool}
```

### Types

The following types are supported:

- `int`: Integer
- `string`: String
- `bool`: Boolean
- `float`: Floating point number
- `list<T>`: List of type T
- `map<K, V>`: Map with key type K and value type V
- `fn(A, B) -> R`: Function taking an A and a B and returning an R
- Any other type will be treated as an alias that you will have to provide when parsing the expression:
  ```php
  use Eventjet\Ausdruck\Parser\ExpressionParser;
  use Eventjet\Ausdruck\Type;
  
  ExpressionParser::parse('foo:MyType', ['MyType' => Type::alias(Type::listOf(Type::string()))]);
  ```

### Functions

Syntax: `target.functionName:returnType(arg1, arg2, ...)`

The target can be any expression. It will be passed as the first argument to the function.

#### Example

`haystack:list<string>.contains:bool(needle:string)`

#### Generic Signatures

A signature can leave a type open instead of naming it, by binding a type variable in front of the parameter list:

```
fn<T, U>(list<T>, fn(T) -> U) -> list<U>
```

That is the signature of `map`. `T` and `U` are not types; they are decided per call, from the types of the target and
the arguments. So `numbers:list<int>.map(|n| n:int > 2)` is a `list<bool>` while `names:list<string>.map(|n| n:string)`
is a `list<string>` — the same function, two return types, neither of them written down.

A binder is the whole of a variable's scope, so a variable is a type anywhere below the `fn` that binds it, and a name
no binder declares is not a variable: it is an alias, or an error. That's enforced for a signature written as a type
string, where only a `fn<...>` binder can introduce a name that resolves to a variable. The binder itself is checked
against, not just declared: it has to be exactly the variables that turn out to be used in the parameters and the
return type, so a written `fn<T, U>(int) -> int` where neither `T` nor `U` appears anywhere is rejected -- a name in
the binder has to earn its place.

In PHP, a type variable is `Type::var()`, and a function type is always `Type::func()`, whichever position it ends up
in -- the outermost signature of a declaration, an alias target, or a fixed parameter nested inside another one's own
parameters or return type, like a lambda parameter the way `map`'s own is, or a generic callback taken by a custom
function. `Type::func()` itself never commits to a binder: declaring a function (`Declarations`) or aliasing one
(`Type::alias()`) is what quantifies it, deriving its binder from wherever `Type::var()` turns out to be used in the
return type and the parameters -- the same rule a written `fn<...>` binder is checked against. A function type nested
inside another one built with `Type::func()` and no binder of its own shares its variables with whichever declaration
or alias goes on to quantify them, rather than claiming any of its own. Nothing stops a nested function type from
being a self-contained, rank-1-polymorphic value instead -- one already quantified by its own `fn<...>` binder, the
same way a written type string nests one -- and a signature like that is opaque to whatever encloses it: the outer
one can pass it around, store it in a list or an `Option`, or return it, but never reach through it for a variable of
its own. A nested binder may not reuse a name a binder further out already declares, though: the inner variable would
shadow the outer one, and shadowing is not allowed -- `fn<T>(fn<T>(T) -> T) -> T` is rejected, `fn<T>(fn<U>(U) -> U) -> T`
is fine. That holds whichever door builds the binder: a written `fn<...>` is rejected as it's parsed, and a binder
derived by `Declarations`, `Type::alias()`, or `Signature::quantified()` is rejected as it's derived, rather than
minting a value that would print as text the parser reads back as a redeclared variable.

Until it's quantified, a value built with `Type::func()` is open: printing it before declaring or aliasing it prints a
variable's bare name, or a `fn<...>` missing the binder that would make it valid syntax again. `Signature::quantified($t)->toType()`
promotes one directly, the same way `Declarations` and `Type::alias()` do, for the rarer case where neither fits.

Because the call site decides them, the inline return type is rarely worth writing: `foo:list<string>.head()` is
already an `Option<string>`. Writing one anyway is still allowed, and is then checked against the inferred one.

```php
use Eventjet\Ausdruck\Parser\Declarations;
use Eventjet\Ausdruck\Type;

// zip: fn<T, U>(list<T>, list<U>) -> list<{ a: T, b: U }>
$zip = Type::func(
    Type::listOf(Type::struct(['a' => Type::var('T'), 'b' => Type::var('U')])),
    [Type::listOf(Type::var('T')), Type::listOf(Type::var('U'))],
);
$declarations = new Declarations(functions: ['zip' => $zip]);
```

A generic higher-order function -- one that itself takes a generic function as a parameter, the way `map` does --
nests a `Type::var()` from the outer signature inside a `Type::func()` for the inner one, built exactly the same way
as the outer one:

```php
// myMap: fn<T, U>(list<T>, fn(T) -> U) -> list<U>
$myMap = Type::func(
    Type::listOf(Type::var('U')),
    [Type::listOf(Type::var('T')), Type::func(Type::var('U'), [Type::var('T')])],
);
$declarations = new Declarations(functions: ['myMap' => $myMap]);
```

#### Built-In Functions

| Function   | Signature                                  | Description                                                            | Example                                   |
|------------|--------------------------------------------|------------------------------------------------------------------------|-------------------------------------------|
| `count`    | `fn(list<any>) -> int`                     | Returns the number of elements in a list                               | `foo:list<string>.count()`                |
| `contains` | `fn<T>(list<T>, T) -> bool`                | Returns whether a list contains a value                                | `foo:list<string>.contains("bar")`        |
| `filter`   | `fn<T>(list<T>, fn(T) -> bool) -> list<T>` | Returns a new list of the elements matching a [predicate](#lambdas)    | `foo:list<int>.filter(\|i\| i:int > 2)`   |
| `head`     | `fn<T>(list<T>) -> Option<T>`              | Returns the first element of a list as an `Option`                     | `foo:list<string>.head()`                 |
| `isSome`   | `fn(Option<any>) -> bool`                  | Takes an Option and returns whether it is `Some`                       | `foo:Option<int>.isSome()`                |
| `map`      | `fn<T, U>(list<T>, fn(T) -> U) -> list<U>` | Returns a new list with the results of applying a [function](#lambdas) | `foo:list<int>.map(\|i\| i:int - 2)`      |
| `some`     | `fn<T>(list<T>, fn(T) -> bool) -> bool`    | Returns whether any element matches a [predicate](#lambdas)            | `foo:list<int>.some(\|item\| item:int > 5)`|
| `substr`   | `fn(string, int, int) -> string`           | Returns a substring of a string                                        | `foo:string.substr(0, 5)`                 |
| `tail`     | `fn<T>(list<T>) -> list<T>`                | Returns all elements of a list except the first                        | `foo:list<string>.tail()`                 |
| `take`     | `fn<T>(list<T>, int) -> list<T>`           | Returns the first n elements of a list                                 | `foo:list<string>.take(5)`                |
| `unique`   | `fn<T>(list<T>) -> list<T>`                | Returns a list with duplicate elements removed                         | `foo:list<string>.unique()`               |
| `unwrap`   | `fn<T>(Option<T>) -> T`                    | Returns the value contained in an `Option`                             | `foo:Option<int>.unwrap()`                |

The signatures are the ones the parser checks calls against; the target is the first parameter.

#### Custom Functions

You can pass custom functions along with the scope variables:

```php
use Eventjet\Ausdruck\Parser\ExpressionParser;use Eventjet\Ausdruck\Scope;

$scope = new Scope(
    ['foo' => 'My secret'],
    ['mask' => fn (string $str, string $mask) => str_repeat($mask, strlen($str))]
);
$result = ExpressionParser::parse('foo:string.mask("x")')->evaluate($scope);
assert($result === 'xxxxxxxxx');
```

The target of the function/method call (`foo:string` in the example above) will be passed as the first argument to the
function.

### Lambdas

Syntax: `|arg1, arg2, ... | expression`

To access an argument, you must specify its type, just like when accessing scope variables.

#### Example

`|item| item:int > 5`
