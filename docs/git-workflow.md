# Team Git Workflow

Use a separate feature branch for every task and person. Do not develop or push directly on `solespace-b`.

For two people, use separate branches:

```text
solespace-b
├── feature/alice-task
└── fix/bob-task
```

One person may use the same feature branch on two devices, but must push completed
commits before switching devices and pull the branch before continuing. Do not have
two people actively pushing to the same feature branch.

## 1. Start from the latest shared branch

Check that local work is safe:

```powershell
git status
```

If unfinished work exists, commit it to its current feature branch before continuing.

For a new device, clone the repository first. Replace the placeholders with the
repository URL and folder name:

```powershell
git clone <repository-url> <repository-folder>
cd <repository-folder>
git fetch origin
git switch --track origin/solespace-b
```

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

If `solespace-b` does not exist locally yet, create the local tracking branch first:

```powershell
git fetch origin
git switch --track origin/solespace-b
```

To continue the same task on another device, use the existing remote feature branch:

```powershell
git fetch origin --prune
git switch --track origin/feature/short-task-name
git pull --rebase origin feature/short-task-name
```

If the feature branch already exists locally, use `git switch feature/short-task-name`
instead of the tracking command.

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
git fetch origin --prune
git rebase origin/solespace-b
```

Rebase only your own feature branch. If another person has already pulled or pushed
to that feature branch, coordinate with them before rewriting its history. Never
force-push a shared branch.

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
pnpm run test:frontend
pnpm run build
git diff --check
```

Do not push when:

- unrelated files are present;
- an unexpected file is deleted;
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

## 8. Protect the shared branch

Configure `solespace-b` in GitHub so that:

- changes require a Pull Request;
- at least one review is required;
- required tests and builds must pass;
- force-pushes and branch deletion are blocked.

## 9. Update after merge

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

Review all conflicts, deletions, and restored changes before committing or pushing.
If `git stash pop` reports conflicts, resolve them and verify `git status` and
`git diff` before continuing.
