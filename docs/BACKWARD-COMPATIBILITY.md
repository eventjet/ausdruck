# Backward Compatibility & Versioning

This document defines the public API of `eventjet/ausdruck`, what we promise about it,
and how that promise is kept: a written policy, a controlled surface, tooling that
reports structural breaks, and a human who chooses the version.

## The public API surface

**The public API is every symbol that is `public` and not marked `@internal`.**

- Code marked `@internal` (classes, methods, properties, constants) sits outside this
  promise and may change or be removed in any release, including a patch.
- Internal code is additionally marked `@psalm-internal <namespace>` so the static
  analyzers forbid outside use. `@internal` and `@psalm-internal` must always travel
  together: the backward-compatibility checker only understands `@internal`, so a
  symbol that has only `@psalm-internal` would be mistaken for public API. This pairing
  is enforced by `InternalAnnotationConsistencyTest`.

A symbol leaves the public surface by carrying `@internal` — that annotation is the
single lever, applied where the symbol is declared.

## Versioning

The project follows [Semantic Versioning](https://semver.org/). While on the `0.x`
line, the **minor** is the compatibility boundary:

- A change that breaks the public API ships as a new `0.MINOR` (e.g. `0.2.4` → `0.3.0`).
- A backward-compatible change or a bug fix ships as a patch (e.g. `0.3.0` → `0.3.1`).
- `^0.3` therefore means `>=0.3.0 <0.4.0`.

Once the project reaches `1.0.0`, the **major** becomes the boundary and minors become
backward-compatible again.

## What counts as a break

- **Structural breaks** — removing or renaming a public symbol, changing a signature,
  return type, or parameter type, narrowing visibility, adding a required parameter,
  marking a previously-public class `@internal` or `final`, and so on. The automated
  checker detects these.
- **Behavioral breaks** — the signature is unchanged but the behavior is not: what a
  symbol returns, throws, or computes shifts. These are just as much breaks. Deciding
  semantic equivalence is undecidable, so tests, code review, and judgment are what
  catch them — and most real-world breaks are of this kind.

## How compatibility is checked

`roave/backward-compatibility-check` runs in CI (`.github/workflows/checks.yml`); it
compares the public API against the last stable tag and reports structural breaks.

- It is **informational** on `master` and feature branches. During a pre-release minor
  cycle the trunk intentionally accumulates the next version's breaks, so a reported
  break there is expected and does not fail the build.
- It is **blocking** on maintenance branches (`*.x`, e.g. `0.3.x`), which promise
  backward compatibility: a structural break there fails CI.

When cutting or backporting a release on a `*.x` branch whose lineage is not the
highest tag in the repository, pin the checker's baseline explicitly
(`--from=<previous tag on that line>`); it otherwise compares against the highest stable
tag regardless of lineage.

## Deprecations

Where practical, prefer deprecate-then-remove over an immediate break: mark the old
path `@deprecated` with a pointer to the replacement, keep it working for the remainder
of the current minor, and remove it at the next minor bump. Some pre-`1.0` changes will
still be direct breaks; every one is recorded in the upgrade notes.

## Choosing and recording the version

The version number is **derived** from the pull request titles that landed, by
release-please (see `RELEASING.md` for the mechanics). The human decision moves one step
earlier: marking a change as breaking, with a `!` after the type in the pull request
title, is what turns it into a minor bump. Nothing detects a break for you — the
compatibility check reports signature changes, but a *behavioral* break has no signature
to compare, so only the author can declare it. `Release-As:` overrides the derived number
when the declaration was missed.

Every break and deprecation is recorded in the changelog (generated) and the upgrade
notes (hand-written), and releases are cut as git tags.
