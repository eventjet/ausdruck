# Expression fixtures

Prefer fixtures under `tests/unit/cases/` for language behavior. Start with the
expression, then use named sections (`-- Output --`, `-- Expression type --`,
`-- Type error --`, or `-- Syntax error --`). `Input` supplies a struct of values;
`Types` and `Functions` declare aliases and signatures.

Enum constructors can appear in `Input` and `Output`. To register a custom enum,
use an `Enums` section; each line describes one PHP `EnumDefinition`:

```text
Ok(Some(42))
-- Enums --
Result<T, E> = Ok(T) | Err(E)
-- Expression type --
Result<Option<int>, !>
-- Output --
Ok(Some(42))
-- Printed --
Ok(Some(42))
```

`Enums` is test setup syntax, not an expression-language declaration. `Printed`
checks the canonical spelling and reparses it, comparing expression and type.
`Evaluation error` checks the exact runtime error message, with optional `Input`.
Definitions are nominal and reused by input, expected output, and source parsing.
