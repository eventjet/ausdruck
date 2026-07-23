# Releasing

## Branching model

We are **trunk-based**: `master` is the single line of forward development. Work
lands on `master` through short-lived pull-request branches. There are no
long-lived version branches.

**Releases are git tags, not branches.** A release is a `vX.Y.Z` tag on a commit
of `master`; Packagist publishes from tags. You never need a branch to cut a
release — only a tag.

## Versioning (pre-1.0)

We are on a `0.x` line, so Composer treats the **minor** as the compatibility
boundary (`^0.2` means `>=0.2.0 <0.3.0`). Map every change to that:

- **Breaking change** → new minor: `0.2.x` → `0.3.0`.
- **Backward-compatible change or fix** → patch within the current minor:
  `0.3.0` → `0.3.1`.

So within a `0.MINOR.x` line nothing may break; breaking anything requires a new
`0.MINOR`.

## The BC check

`roave-backward-compatibility-check` compares `HEAD` against the last release
tag. During a window where the next release is an intended **minor** (breaks
allowed), a red result is expected and correct — it is a *report of what broke*,
not a failure. It is therefore **informational per-PR**, and the real gate is at
release time:

1. Before tagging, run the check against the last tag.
2. If it reports breaks and you intended a **patch**, that is your signal to bump
   the **minor** instead (or to reconsider the break).
3. Every break must be listed in `UPGRADING.md` for that release.

After a release the tool's baseline advances automatically to the new tag, so the
check is meaningful again for the next line's patches.

## Cutting a release

1. Ensure `UPGRADING.md` documents every break since the last release.
2. Run the BC check against the last tag and reconcile it with `UPGRADING.md`.
3. Decide the version per the rules above.
4. Tag `master`: `git tag vX.Y.Z && git push origin vX.Y.Z`. Packagist publishes.

## Patching an older release (reactive only)

We do **not** keep version branches standing. Create one only when a fix is
genuinely needed on an older line that cannot take `master`'s changes:

1. Fix it on `master` first (so the fix ships forward and is never forgotten).
2. Branch from the release tag: `git switch -c 0.2.x v0.2.4`.
3. Cherry-pick the fix from `master` (or reimplement if the code has diverged).
4. Tag the patch: `git tag v0.2.5 && git push origin v0.2.5`.

The branch is disposable scaffolding — every release lives in its tag, so a
maintenance branch can be recreated from a tag whenever it is next needed.
