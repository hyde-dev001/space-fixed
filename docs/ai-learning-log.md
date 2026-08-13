# AI Learning Log

## 2026-08-13 — Frontend delivery workflow

- After completing frontend code changes, follow `docs/git-workflow.md`.
- Generate and include a fresh `public/build`, run the relevant pre-push checks, and push only the task's feature branch.
- Leave Pull Request creation to the user unless they explicitly request otherwise.

## 2026-08-13 - Database ID hydration

- Treat foreign-key IDs as integers at Eloquent model boundaries. MySQL/MariaDB can hydrate integer columns as strings, which breaks strict authorization comparisons unless the model casts those IDs; cover the boundary with a string-hydration regression test.
