# Team Git Workflow

Use a separate feature branch for every task. Do not develop or push directly on `solespace-b`.

## 1. Start from the latest shared branch

Check that local work is safe:

```powershell
git status
```

If unfinished work exists, commit it to its current feature branch before continuing.

Update the shared branch and create a task branch:

```powershell
git switch solespace-b
git pull --rebase origin solespace-b
git switch -c feature/short-task-name
```

Examples:

```text
feature/premium-showroom
fix/logistics-settings
feature/customer-notifications
```

## 2. Review changes while working

```powershell
git status --short
git diff
```

Status codes:

```text
M = modified
A = added
D = deleted
```

Stop and investigate every unexpected `D` entry.

## 3. Commit only intended files

Stage specific files instead of blindly staging everything:

```powershell
git add path\to\file1 path\to\file2
git diff --cached
git commit -m "feat: describe the change"
```

Avoid `git add .` unless every listed file has been reviewed.

## 4. Rebase before pushing

```powershell
git fetch origin
git rebase origin/solespace-b
```

If Git reports conflicts:

```powershell
git status
```

Resolve each conflict while preserving both the latest shared behavior and the intended feature. Then continue:

```powershell
git add path\to\resolved-file
git rebase --continue
```

If the correct resolution is unclear:

```powershell
git rebase --abort
```

Do not guess, use `git reset --hard`, or force-push a shared branch.

## 5. Run the pre-push gate

Review exactly what the branch will introduce:

```powershell
git diff --name-status origin/solespace-b...HEAD
git diff --stat origin/solespace-b...HEAD
```

Run the relevant checks:

```powershell
php artisan test tests/Feature/Logistics
npm run build
```

For the current logistics implementation, expect:

```text
114 tests
320 assertions
```

Do not push when:

- unrelated files are present;
- an unexpected file is deleted;
- the logistics test count drops below the expected baseline;
- any test or build fails.

## 6. Push the feature branch

```powershell
git push -u origin feature/short-task-name
```

Create a Pull Request:

```text
feature/short-task-name -> solespace-b
```

Do not push the feature directly into `solespace-b`.

## 7. Review and merge

The reviewer must confirm:

- the diff matches the task;
- there are no unrelated or unexpected deletions;
- tests pass with the expected count;
- the production build passes;
- migrations and generated files are intentional.

Merge through GitHub only after approval.

## 8. Update after merge

```powershell
git switch solespace-b
git pull --rebase origin solespace-b
git branch -d feature/short-task-name
```

Repeat this workflow for every task.

## Emergency: local changes exist before pulling

Committing to a feature branch is preferred. If a temporary stash is necessary:

```powershell
git stash push -u -m "before pulling solespace-b"
git pull --rebase origin solespace-b
git stash pop
```

Review all conflicts and deletions before committing or pushing.
