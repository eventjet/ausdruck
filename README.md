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

Both operands must be of the same type.

| Operator | Description           | Example                  | Note                                      |
|----------|-----------------------|--------------------------|-------------------------------------------|
| `===`    | Equality              | `foo:string === "bar"`   |                                           |
| `!==`    | Inequality            | `foo:string !== "bar"`   |                                           |
| `-`      | Subtraction           | `foo:int - bar:int`      | Operands must be of type `int` or `float` |
| `>`      | Greater than          | `foo:int > bar:int`      | Operands must be of type `int` or `float` |
| `>=`     | Greater than or equal | `foo:int >= bar:int`     | Operands must be of type `int` or `float` |
| `<`      | Less than             | `foo:int < bar:int`      | Operands must be of type `int` or `float` |
| `<=`     | Less than or equal    | `foo:int <= bar:int`     | Operands must be of type `int` or `float` |
| `\|\|`   | Logical OR            | `foo:bool \|\| bar:bool` | Operands must be of type `bool`           |
| &&       | Logical AND           | `foo:bool && bar:bool`   | Operands must be of type `bool`           |

Equality is spelled `===`, so inequality is `!==`; there is no `==` or `!=`.

Where's the rest? We're implementing more as we need them.

#### Precedence

Operators bind from tightest to loosest in this order:

| Operator                            | Associativity   |
|-------------------------------------|-----------------|
| `.` (field, method)                 | Left            |
| `-` (negation)                      | Right           |
| `-` (subtraction)                   | Left            |
| `===`, `!==`, `>`, `>=`, `<`, `<=`  | Non-associative |
| `&&`                                | Left            |
| `\|\|`                              | Left            |

As in most languages, `&&` binds tighter than `||`, so `a:bool && b:bool || c:bool` means
`(a:bool && b:bool) || c:bool`, and `a:int - b:int - c:int` means `(a:int - b:int) - c:int`.

The comparison operators are non-associative: `a:int > b:int > c:int` is a syntax error rather than a comparison
against the `bool` that the first comparison produces. Chain with `&&` instead.

#### Grouping

Wrap a sub-expression in parentheses to override the precedence:

```
a:bool && (b:bool || c:bool)
(a:int - b:int) - c:int
(a:int > b:int) === c:bool
(a:int - b:int).abs:int()
```

Parentheses only group; they add no node of their own. Redundant ones — a group the precedence would have produced
anyway, like `(a:int - b:int) - c:int` — parse fine and simply disappear, so printing an expression back out adds a
pair of parentheses exactly where one is needed to parse it back into the same expression, and nowhere else.

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
- Any other type will be treated as an alias that you will have to provide when parsing the expression:
  ```php
  use Eventjet\Ausdruck\Parser\ExpressionParser;
  use Eventjet\Ausdruck\Type;
  
  ExpressionParser::parse('foo:MyType', ['MyType' => Type::alias(Type::listOf(Type::string()))]);
  ```

Type arguments are separated by commas, like every other list in the language: `map<string, int>`, not
`map<string int>`. A trailing one is allowed — `map<string, int,>` — so a type spread over several lines can end each
of them the same way.

### Functions

Syntax: `target.functionName:returnType(arg1, arg2, ...)`

The target can be any expression. It will be passed as the first argument to the function.

#### Example

`haystack:list<string>.contains:bool(needle:string)`

#### Built-In Functions

| Function   | Description                                                            | Example                                          |
|------------|------------------------------------------------------------------------|--------------------------------------------------|
| `count`    | Returns the number of elements in a list                               | `foo:list<string>.count:int()`                   |
| `contains` | Returns whether a list contains a value                                | `foo:list<string>.contains:bool("bar")`          |
| `filter`   | Returns a new list of the elements matching a [predicate](#lambdas)    | `foo:list<int>.filter:list<int>(\|i\| i:int > 2)`|
| `head`     | Returns the first element of a list as an `Option`                     | `foo:list<string>.head:Option<string>()`         |
| `isSome`   | Takes an Option and returns whether it is `Some`                       | `foo:Option<int>.isSome:bool()`                  |
| `map`      | Returns a new list with the results of applying a [function](#lambdas) | `foo:list<int>.map:list<int>(\|i\| i:int - 2)`   |
| `some`     | Returns whether any element matches a [predicate](#lambdas)            | `foo:list<int>.some:bool(\|item\| item:int > 5)` |
| `substr`   | Returns a substring of a string                                        | `foo:string.substr:string(0, 5)`                 |
| `tail`     | Returns all elements of a list except the first                        | `foo:list<string>.tail:list<string>()`           |
| `take`     | Returns the first n elements of a list                                 | `foo:list<string>.take:list<string>(5)`          |
| `unique`   | Returns a list with duplicate elements removed                         | `foo:list<string>.unique:list<string>()`         |
| `unwrap`   | Returns the value contained in an `Option`                             | `foo:Option<int>.unwrap:int()`                   |

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
