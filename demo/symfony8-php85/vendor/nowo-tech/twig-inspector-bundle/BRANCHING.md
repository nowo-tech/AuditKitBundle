# Branching Policy

This document describes the branching strategy and conventions used in the Twig Inspector Bundle project.

## Main Branches

### `main`
- **Purpose**: Production-ready code
- **Protection**: Protected branch (requires PR and CI approval)
- **Deployment**: Automatically deployed to Packagist when tagged
- **Merges**: Only via Pull Requests from `develop` or hotfix branches
- **Status**: Always stable and deployable

### `develop`
- **Purpose**: Integration branch for features
- **Protection**: Protected branch (requires PR approval)
- **Merges**: Feature branches are merged here
- **Status**: Should always be in a deployable state

## Branch Types

### Feature Branches
**Naming**: `feature/description-of-feature`

**Purpose**: Develop new features or enhancements

**Workflow**:
1. Create from `develop`
2. Develop and test the feature
3. Create Pull Request to `develop`
4. After approval and merge, delete the branch

**Examples**:
- `feature/configurable-exclusions`
- `feature/template-metrics`
- `feature/performance-optimization`

### Bugfix Branches
**Naming**: `fix/description-of-fix`

**Purpose**: Fix bugs found in `develop` branch

**Workflow**:
1. Create from `develop`
2. Fix the bug and add tests
3. Create Pull Request to `develop`
4. After approval and merge, delete the branch

**Examples**:
- `fix/path-traversal-vulnerability`
- `fix/template-exclusion-wildcard`
- `fix/collector-session-handling`

### Hotfix Branches
**Naming**: `hotfix/description-of-hotfix`

**Purpose**: Fix critical bugs in production (`main` branch)

**Workflow**:
1. Create from `main`
2. Fix the critical bug
3. Create Pull Request to `main` (and optionally to `develop`)
4. After approval and merge, delete the branch
5. Tag a new patch version (e.g., `1.0.2` → `1.0.3`)

**Examples**:
- `hotfix/security-patch`
- `hotfix/critical-memory-leak`
- `hotfix/breaking-change-revert`

### Release Branches
**Naming**: `release/x.y.z`

**Purpose**: Prepare a new production release

**Workflow**:
1. Create from `develop` when ready for release
2. Update version numbers, CHANGELOG.md, etc.
3. Final testing and bug fixes (only critical fixes)
4. Create Pull Request to `main` and `develop`
5. After merge to `main`, tag the release (e.g., `v1.1.0`)
6. Delete the branch

**Examples**:
- `release/1.1.0`
- `release/1.2.0`

## Branch Naming Conventions

### General Rules
- Use lowercase letters
- Use hyphens (`-`) to separate words
- Be descriptive but concise
- Prefix with branch type (`feature/`, `fix/`, `hotfix/`, `release/`)

### Good Examples
```
feature/template-exclusion-patterns
fix/collector-null-pointer-exception
hotfix/security-vulnerability-patch
release/1.1.0
```

### Bad Examples
```
Feature/NewStuff          # Wrong: uppercase, no prefix
fix-bug                   # Wrong: missing prefix
feature/new stuff         # Wrong: spaces instead of hyphens
feat/awesome-feature      # Wrong: use 'feature/' not 'feat/'
```

## Workflow

### Starting Work
1. Ensure you're on `develop` and it's up to date:
   ```bash
   git checkout develop
   git pull origin develop
   ```

2. Create a new branch:
   ```bash
   git checkout -b feature/my-new-feature
   # or
   git checkout -b fix/bug-description
   ```

### During Development
- Commit frequently with clear messages
- Follow [Conventional Commits](https://www.conventionalcommits.org/) format:
  - `feat:` for new features
  - `fix:` for bug fixes
  - `docs:` for documentation
  - `test:` for tests
  - `refactor:` for refactoring
  - `style:` for formatting
  - `chore:` for maintenance

### Before Creating Pull Request
1. Ensure all tests pass:
   ```bash
   composer test
   ```

2. Check code coverage (must be 100%):
   ```bash
   composer test-coverage
   ```

3. Fix code style:
   ```bash
   composer cs-fix
   ```

4. Update documentation if needed:
   - Update `README.md` if user-facing features changed
   - Update `CHANGELOG.md` for user-visible changes
   - Update `CONFIGURATION.md` if configuration changed

5. Rebase on latest `develop`:
   ```bash
   git checkout develop
   git pull origin develop
   git checkout feature/my-new-feature
   git rebase develop
   ```

### Creating Pull Request
1. Push your branch:
   ```bash
   git push origin feature/my-new-feature
   ```

2. Create Pull Request on GitHub:
   - Target branch: `develop` (or `main` for hotfixes)
   - Fill out PR template
   - Link related issues
   - Request review

3. Address review comments:
   - Make requested changes
   - Push updates to the same branch
   - PR will automatically update

### After Merge
1. Delete local branch:
   ```bash
   git checkout develop
   git pull origin develop
   git branch -d feature/my-new-feature
   ```

2. Delete remote branch (if not auto-deleted):
   ```bash
   git push origin --delete feature/my-new-feature
   ```

## Branch Protection Rules

### `main` Branch
- ✅ Require pull request reviews (at least 1 approval)
- ✅ Require status checks to pass (CI/CD)
- ✅ Require branches to be up to date
- ✅ Require conversation resolution
- ✅ No force pushes
- ✅ No deletions

### `develop` Branch
- ✅ Require pull request reviews (at least 1 approval)
- ✅ Require status checks to pass (CI/CD)
- ✅ Require branches to be up to date
- ✅ Require conversation resolution
- ✅ No force pushes
- ✅ No deletions

## Release Process

### Minor/Major Releases
1. Create release branch from `develop`:
   ```bash
   git checkout develop
   git pull origin develop
   git checkout -b release/1.1.0
   ```

2. Update version in `composer.json`
3. Update `CHANGELOG.md` with release notes
4. Create PR to `main` and `develop`
5. After merge to `main`, create Git tag:
   ```bash
   git tag -a v1.1.0 -m "Release version 1.1.0"
   git push origin v1.1.0
   ```

### Patch Releases (Hotfixes)
1. Create hotfix branch from `main`:
   ```bash
   git checkout main
   git pull origin main
   git checkout -b hotfix/1.0.3
   ```

2. Fix the issue
3. Update version in `composer.json`
4. Update `CHANGELOG.md`
5. Create PR to `main` and `develop`
6. After merge to `main`, create Git tag:
   ```bash
   git tag -a v1.0.3 -m "Hotfix version 1.0.3"
   git push origin v1.0.3
   ```

## Best Practices

1. **Keep branches focused**: One feature/fix per branch
2. **Keep branches short-lived**: Merge as soon as ready
3. **Keep branches up to date**: Regularly rebase on `develop`
4. **Write clear commit messages**: Follow Conventional Commits
5. **Test before pushing**: Ensure all tests pass locally
6. **Update documentation**: Keep docs in sync with code changes
7. **Small, frequent commits**: Easier to review and revert if needed
8. **No direct commits to `main` or `develop`**: Always use PRs

## Common Scenarios

### Updating Your Branch with Latest Changes
```bash
git checkout develop
git pull origin develop
git checkout feature/my-feature
git rebase develop
git push origin feature/my-feature --force-with-lease
```

### Renaming a Branch
```bash
# Rename local branch
git branch -m old-name new-name

# Delete old remote branch
git push origin --delete old-name

# Push new branch
git push origin new-name

# Set upstream
git push origin -u new-name
```

### Undoing Last Commit (Before Push)
```bash
git reset --soft HEAD~1  # Keep changes staged
# or
git reset HEAD~1         # Unstage changes
```

### Undoing Last Commit (After Push)
```bash
# Create a new commit that undoes changes
git revert HEAD
git push origin feature/my-feature
```

## Questions?

If you have questions about branching:
- Open an issue on GitHub
- Contact maintainers at hectorfranco@nowo.com
- Check [CONTRIBUTING.md](CONTRIBUTING.md) for general contribution guidelines

