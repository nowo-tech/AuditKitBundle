# Release process

1. Update [CHANGELOG.md](CHANGELOG.md): move entries from `[Unreleased]` to a new `[X.Y.Z] - YYYY-MM-DD` section. (This project does not store version in `composer.json`; Packagist uses the git tag.)
2. Update [UPGRADING.md](UPGRADING.md) if the release has upgrade notes.
3. Run pre-release checks: `make release-check` (includes `check-no-cursor-coauthor`, cs-fix, cs-check, rector-dry, phpstan, test-coverage, and optionally demo healthchecks).
4. Commit all changes, create an annotated tag (e.g. `v1.0.1`), and push branch and tag. The release workflow will create the GitHub Release with the changelog.
5. Publish the package to Packagist if applicable (usually automatic when the tag is pushed).

After creating the release commit and tag, run `make check-no-cursor-coauthor` again **before** `git push` (REQ-GIT-001). The release commit itself is not covered by an earlier `release-check` run.

## Example for v1.1.1

```bash
git add -A
git status   # review
make release-check
git commit -m "Release 1.1.1: REQ-GIT-001 hygiene and CI Doctrine Bundle matrix"
make check-no-cursor-coauthor
git tag -a v1.1.1 -m "Release 1.1.1"
git push origin main
git push origin v1.1.1
```
