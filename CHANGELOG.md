# Changelog

## Unreleased

### Changed

- **Type argument lists now require commas between their elements.** `map<string int>`, `list<int string>` and
  `fn(int string) -> bool` used to parse as if the comma were there; they are now syntax errors naming the bracket the
  list is missing (`Expected >, got int`). Every other list in the language already required commas, and the
  documented spelling has always been `map<K, V>`.

  This affects type strings wherever they are written: inline annotations in expressions passed to
  `ExpressionParser::parse()`, and whole types passed to `TypeParser::parseString()` and
  `TypeParser::parseDeclarations()`. Nothing that parsed to one type now parses to a different one — the only change
  is that comma-less lists are rejected rather than accepted — so a type string that reads correctly today keeps its
  meaning. Type strings kept outside the codebase, in configuration or a database, are the ones worth checking.

- Syntax errors say `end of input` where some of them used to say `end of string`, and a type that is required in a
  named position says so: `Expected return type, got end of input` rather than `Expected type after colon`, and
  `Expected type for Foo, got ->` for a declaration in `TypeParser::parseDeclarations()`. Error messages are not
  covered by the backward-compatibility promise, but code matching on them will need updating.

- Errors reported at the end of the input now point just past the whole last token rather than just past the column it
  starts in. `{name` is reported at column 6 instead of column 3.
