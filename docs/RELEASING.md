# Releasing

Releases are automated by
[release-please](https://github.com/googleapis/release-please). You never tag by
hand; you write pull request titles that say what changed, and the automation
derives the version, the `CHANGELOG.md` entry, the tag, and the GitHub release
from them.

## Branching model

We are **trunk-based**: `master` is the single line of forward development. Work
lands on `master` through short-lived pull-request branches. There are no
long-lived version branches.

**Releases are git tags, not branches.** A release is an `X.Y.Z` tag (no `v`
prefix) on a commit of `master`; Packagist publishes from tags. You never need a
branch to cut a release — only a tag, and release-please pushes that for you.

## How a release happens

1. You merge a pull request into `master`. **Squash is the only merge method**, so
   the pull request title becomes the commit subject.
2. `.github/workflows/release-please.yml` runs and parses every commit subject
   since the last tag.
3. If any of them warrant a release, release-please opens (or updates) a
   **release pull request** titled `chore(master): release X.Y.Z`. It contains
   the generated `CHANGELOG.md` entry and the bumped
   `.release-please-manifest.json`. It accumulates further merges until you act
   on it.
4. Review the entry. This is the moment to fix a version you disagree with — see
   *Overriding the version* below.
5. Merge the release pull request. release-please then pushes the `X.Y.Z` tag and
   creates the GitHub release. Packagist publishes.

## Writing pull request titles

The title must be a [Conventional Commit](https://www.conventionalcommits.org/).
**A title that does not parse is skipped silently** — no changelog entry, no
version bump — so this is load-bearing, not a style preference.

```
feat: add the arithmetic operator %
fix: reject a zero divisor instead of returning 0
feat!: seal the UnaryOperator hierarchy
```

A scope is optional and the repository has no scope vocabulary; plain `feat:` is
the norm.

### Type → changelog section → bump

Pre-1.0 the **minor** is Composer's compatibility boundary (`^0.3` means
`>=0.3.0 <0.4.0`), so a break has to move the minor and everything else stays a
patch. The config encodes exactly that with `bump-minor-pre-major` and
`bump-patch-for-minor-pre-major`.

| Title | Changelog section | Bump from 0.3.0 |
| --- | --- | --- |
| `feat!:`, or any type with a `BREAKING CHANGE:` footer | ⚠ Breaking Changes | 0.4.0 |
| `feat:` | Features | 0.3.1 |
| `fix:` | Bug Fixes | 0.3.1 |
| `perf:` | Performance Improvements | 0.3.1 |
| `revert:` | Reverts | 0.3.1 |
| `refactor:` | Code Refactoring | 0.3.1 |
| `docs:` | Documentation | 0.3.1 |
| `test:` | Tests | 0.3.1 |
| `build:` | Build System | 0.3.1 |
| `ci:` | Continuous Integration | 0.3.1 |
| `chore:` | Miscellaneous Chores | 0.3.1 |
| `style:` | *(hidden)* | 0.3.1 |
| anything that does not parse | *(nothing)* | *(none)* |

Every type still produces a patch bump, so a documentation-only merge can ship a
release on its own. That is intended: a patch costs nothing, and the alternative
is changes sitting untagged.

### Declaring a breaking change

Put a `!` after the type in the pull request title:

```
feat!: seal the AbstractLiteral hierarchy
```

That alone moves the minor, and it is the part you must not forget. With nothing
else, the changelog reuses the subject line as the breaking-change description.

Most breaks need more than one line. Write the whole commit message in the pull
request description, inside a `BEGIN_COMMIT_OVERRIDE` block:

```
BEGIN_COMMIT_OVERRIDE
feat!: seal the AbstractLiteral hierarchy

BREAKING CHANGE: `AbstractLiteral` and `ListLiteral` are `@internal` and `final`.
Build literals through `Expression::literal()` instead of extending them.
END_COMMIT_OVERRIDE
```

release-please reads the description over the API, so the block reaches it whatever
GitHub put in the commit body. Two consequences:

- **The block replaces the entire message**, so it has to repeat the type and
  subject. What is inside the block is all release-please sees; the pull request
  title then only shapes the git history.
- Everything outside the block is ignored, so a review checklist or a test plan in
  the description cannot leak into the changelog.

**The marker activates wherever it appears in a description, so write it only as
the block itself.** release-please splits the description on the first occurrence of
the string, with no requirement that it sit on its own line or outside a code span —
inside backticks and inside a fenced block both count. Everything after that first
occurrence becomes the commit message. A description that merely *mentions* the
marker therefore hands release-please a fragment that does not parse, and the pull
request is skipped: no changelog entry, no bump, and nothing on the pull request to
say so. You find out only when no release pull request appears. When you need to
discuss the mechanism in a description, call it "the commit-override block" instead
of naming it.

Mind the blank lines inside the block. release-please starts a new commit at any
blank line followed by a `type: ` subject, so one block can deliberately carry two
entries for a pull request that does two things — and can accidentally carry two if
a paragraph happens to open that way.

Keep the footer to a summary. The migration steps themselves belong in
`UPGRADING.md`, which is where a reader hit by the break is sent anyway.

A `BREAKING CHANGE:` footer in a commit message on the branch also works, because
the squash body is built from those messages. Prefer the override: it goes where you
were going to explain the break anyway, and it survives a rebase of the branch.

Dependabot's prefixes come from `.github/dependabot.yml`.

## CHANGELOG.md is generated

**Do not hand-edit `CHANGELOG.md`**, and do not add an `Unreleased` section.
release-please inserts each new entry between the preamble and the newest
existing entry; a hand-written section is either overwritten or left stranded.
The 0.3.0 entry predates this setup and keeps its original hand-written
sections — treat it as a historical record, not a template.

`UPGRADING.md` is the opposite: **always hand-written**, and release-please never
touches it. A breaking pull request should add its migration steps under a
`## From 0.MINOR to 0.MINOR+1` heading in the same pull request that makes the
break.

## Overriding the version

release-please's bump is a default, not a verdict. A `Release-As:` footer forces
the version. It is a semantic footer of the same message the type comes from, so
it belongs in the `BEGIN_COMMIT_OVERRIDE` block:

```
BEGIN_COMMIT_OVERRIDE
fix: reject a zero divisor instead of widening to float

Release-As: 0.4.0
END_COMMIT_OVERRIDE
```

The usual reason is a *behavioral* break — one that changes what working code does
without changing a signature. `roave-backward-compatibility-check` compares
signatures, so it sees nothing, and no type prefix describes it either. Only you can
declare it.

If you notice it only once the release pull request is open, land an empty commit
carrying the footer (`git commit --allow-empty`), or edit the release pull request's
title to the version you want.

## The BC check

`roave-backward-compatibility-check` compares `HEAD` against the last release
tag. During a window where the next release is an intended **minor** (breaks
allowed), a red result is expected and correct — it is a *report of what broke*,
not a failure. It is therefore **informational** on `master` and feature
branches, and blocking on `.x` maintenance branches, which promise compatibility.
See [BACKWARD-COMPATIBILITY.md](BACKWARD-COMPATIBILITY.md).

Its job under automation is to catch the mismatch between what you broke and what
you *said* you broke:

1. When a release pull request opens, read its version.
2. If it says patch but the check reports breaks, a pull request title was missing
   its `!`. Fix it with `Release-As:` and add the missing `UPGRADING.md` notes.
3. Every break must be listed in `UPGRADING.md` for that release.

After a release the tool's baseline advances automatically to the new tag.

## Patching an older release (reactive only)

release-please releases from `master` only. A fix for an older line is a manual
release, and we do **not** keep version branches standing — create one only when
a fix is genuinely needed on a line that cannot take `master`'s changes:

1. Fix it on `master` first (so the fix ships forward and is never forgotten).
2. Branch from the release tag: `git switch -c 0.2.x 0.2.4`.
3. Cherry-pick the fix from `master` (or reimplement if the code has diverged).
4. Tag the patch by hand: `git tag 0.2.5 && git push origin 0.2.5`. Add its
   `CHANGELOG.md` entry by hand too, in release-please's format — it will not
   generate one for a commit it never saw.

The branch is disposable scaffolding — every release lives in its tag, so a
maintenance branch can be recreated from a tag whenever it is next needed.
