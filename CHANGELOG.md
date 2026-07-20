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

  One caveat on where the error lands. A `<` after a name that is not a built-in generic constructor might be a
  less-than, so the argument list after it is only *tried*, and any error inside it merely rules that reading out —
  including an error from a committed constructor nested within. A missing comma nested in such a list is therefore
  blamed on the outer `<`, which may be far from the mistake: `MyType<map<string int>>` reports `Unexpected <` at
  column 7, not the missing comma at column 18. The same mistake outside a speculative list reads well
  (`list<list<int int>>` gives `Expected >, got int` at the `int`). Reporting the nearest mistake instead would mean
  keeping the furthest failure across every reading tried, which this parser does not yet do.

- Syntax errors say `end of input` where some of them used to say `end of string`, and a type that is required in a
  named position says so: `Expected return type, got end of input` rather than `Expected type after colon`, and
  `Expected type for Foo, got ->` for a declaration in `TypeParser::parseDeclarations()`. Error messages are not
  covered by the backward-compatibility promise, but code matching on them will need updating.

### Fixed

- Tokens are now located as wide as they are written rather than as wide as they print back, so an error at the end of
  the input points just past the whole last token instead of somewhere inside it. `{name` is reported at column 6
  instead of column 3. This was most visible after a number literal that does not round-trip: `[007` reported column 3
  — a column the input has not even reached — and now reports 5, and `[1, 2.50` reported 8 and now reports 9. The same
  correction applies to the span of the token itself, so `list<1.50>` underlines all four columns of the literal.

- A string literal written across two lines no longer throws off the line number of every token after it. In
  `["a`, newline, `b", &]` the `&` is now reported on line 2, where it is written, rather than line 1.
