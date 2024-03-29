# Ausdruck

A small expression engine for PHP.

## Quick start

```
composer require eventjet/ausdruck
```

```php
use Eventjet\Ausdruck\Parser\ExpressionParser;
use Eventjet\Ausdruck\Parser\Types;

class Person { public function __construct(public string $name) {} }

$expression = ExpressionParser::parse(
    'joe:MyPersonType.name:string()',
    new Types(['MyPersonType' => Person::class]),
);
$scope = new Scope(
    // Passing values to the expression
    ['joe' => new Person('Joe')],
    // Custom function definitions
    ['name' => static fn (Person $person): string => $person->name],
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

| Operator | Description  | Example                  | Note                                      |
|----------|--------------|--------------------------|-------------------------------------------|
| `===`    | Equality     | `foo:string === "bar"`   |                                           |
| `-`      | Subtraction  | `foo:int - bar:int`      | Operands must be of type `int` or `float` |
| `>`      | Greater than | `foo:int > bar:int`      | Operands must be of type `int` or `float` |
| `\|\|`   | Logical OR   | `foo:bool \|\| bar:bool` | Operands must be of type `bool`           |
| &&       | Logical AND  | `foo:bool && bar:bool`   | Operands must be of type `bool`           |

Where's the rest? We're implementing more as we need them.

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
  
  ExpressionParser::parse('foo:MyType', ['MyType' => Type::object(Foo::class)]);
  ```

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
| `isSome`   | Takes an Option and returns whether it is `Some`                       | `foo:Option<int>.isSome:bool()`                  |
| `map`      | Returns a new list with the results of applying a [function](#lambdas) | `foo:list<int>.map:list<int>(\|i\| i:int - 2)`   |
| `some`     | Returns whether any element matches a [predicate](#lambdas)            | `foo:list<int>.some:bool(\|item\| item:int > 5)` |
| `substr`   | Returns a substring of a string                                        | `foo:string.substr:string(0, 5)`                 |
| `take`     | Returns the first n elements of a list                                 | `foo:list<string>.take:list<string>(5)`          |
| `unique`   | Returns a list with duplicate elements removed                         | `foo:list<string>.unique:list<string>()`         |

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
