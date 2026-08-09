# SoleSpace AI Development Operating Model

## Project context

SoleSpace is a Laravel 12 application with Inertia 2, React 18, TypeScript 5.7, Vite 7, and Tailwind CSS 4. The backend uses PHP 8.2 and Composer. Use `pnpm` for frontend commands because `package.json` declares pnpm as the package manager.

## Default operating mode

- Use one main agent and execute work sequentially.
- Treat Tech Lead, Backend, Design, Frontend, QA, Security, and Writer as role modes of the same agent, not separate agents.
- Roles are scope-driven, not mandatory stages. Activate only the roles relevant to the task and revisit a role only when the work requires it.
- Do not dispatch subagents unless the optional parallel-review gate below is satisfied.
- Do not use media-generation, banner, slide, social, audio, or video skills unless the user explicitly requests media work.
- Writing is part of normal delivery: plans, technical documentation, change summaries, and durable learning notes.

## Workflow

### Step 1: Triage

Identify the user outcome, scope, affected domain, risk level, likely files, and relevant skills. Inspect `git status` and preserve unrelated work.

For UI-facing work, activate the Design role before frontend implementation. UI/UX design is part of normal software development and does not count as media generation.

### Step 2: Contract

Before editing, state a short implementation contract containing:

- acceptance criteria;
- files or areas likely to change;
- important constraints and risks;
- narrow verification commands.

Ask for clarification only when a missing decision would materially change the result.

### Step 3: Execute sequentially

1. Explore the existing flow and nearby tests. When `.codegraph/` exists, use CodeGraph before searching application code.
2. Write a short file-level plan for multi-step work.
3. Implement one coherent scope at a time using existing conventions.
4. Run the narrowest relevant check after each meaningful change.
5. Continue only after reading and addressing the result.

Never run multiple agents that edit code simultaneously.

### Step 4: Review gate

The default review is sequential and performed after implementation and tests:

1. Standards review against repository conventions.
2. Spec review against acceptance criteria.
3. Risk review for security, authorization, data integrity, and regressions.

Optional parallel dispatch is allowed only when all of these are true:

- the user explicitly asks for or approves parallel review;
- the task is medium/large, high-risk, or benefits from independent coverage;
- each lane is bounded and read-only, such as security, test gaps, or performance;
- no more than three subagents are used;
- the main agent waits for all results, validates every finding, and produces one report;
- subagents do not edit files, commit, push, or perform destructive actions.

The installed `code-review` skill uses parallel reviewers internally. Invoke it only inside this optional gate with a real base commit/branch and an originating spec. Otherwise, perform the Standards and Spec reviews sequentially without invoking that skill.

#### Required review stack

Run these checks in order for medium/high-risk changes. For a trivial change, mark non-applicable checks as `N/A` instead of manufacturing work.

1. **simplify** - use `ponytail` to remove avoidable complexity, duplication, speculative abstractions, and unnecessary dependencies. Do not use a separate simplify skill that dispatches parallel writers.
2. **code-review** - review repository Standards, then Spec/acceptance criteria, then correctness risks. Use the installed skill only through the approved parallel-review gate; otherwise review sequentially.
3. **clean-code-typescript** - for changed TS/TSX, check naming, focused responsibilities, readable control flow, typed boundaries, safe narrowing, error handling, and unnecessary assertions or `any`. Use `typescript-advanced-types` and `vercel-react-best-practices`; this is a repo review checklist, not an additional third-party skill.
4. **karpathy-guidelines** - surface assumptions, prefer the minimum solution, keep the diff surgical, remove only orphans created by the change, and define verifiable success criteria.
5. **code-splitting** - when frontend bundle behavior is affected, check direct imports, lazy loading for genuinely heavy/conditional UI, and deferred third-party code. Use `vercel-react-best-practices`; do not split small code without measured benefit.
6. **gauge-improvements** - support improvement claims with before/after evidence such as behavior, tests, query count, bundle size, render count, latency, or complexity. If no baseline was captured, report `not measured`; this is a review gate, not an installed public skill.
7. **security-review** - use `security-review` for auth, authorization, input, uploads, API endpoints, payments, secrets, third-party integrations, or sensitive data. Pair it with the relevant `laravel-best-practices` security rules and adapt or ignore Next.js/Supabase-specific examples.
8. **verification-before-completion** - apply the policy that no completion claim is allowed without fresh, relevant evidence. This governs when evidence is required; it is not itself a test, build, lint, or check command.

### Step 5: Completion mandate

Before claiming completion, confirm the recorded review results and perform the remaining gates in order. Do not run the Required Review Stack a second time merely because Step 5 started; rerun an item only when later changes made its evidence stale or invalid.

1. **Required review stack record** - confirm that each applicable item already has a recorded result of pass, finding/resolved, `N/A`, or not run with reason.
2. **Reuse audit** - confirm the change reuses existing helpers, components, types, services, and framework features where appropriate.
3. **Dead-code scan** - inspect changed areas for unused imports, unreachable branches, stale references, abandoned TODOs, and obsolete code. Confirm references and runtime entry points before deleting unfamiliar code.
4. **Quality gates** - run the concrete checks appropriate to the change, such as `git diff --check`, tests, builds, linters, type checks, or browser verification. These commands produce the evidence required by the verification-before-completion policy.
5. **Writing** - update plans, technical docs, change summaries, or usage notes when behavior or developer workflow changed.
6. **Vault learning** - record only durable lessons in `docs/ai-learning-log.md`; never store secrets, tokens, or personal data.
7. **Evidence** - report exact commands and results. Never imply a check passed when it was not run.

### Step 6: Persistence

Keep durable project guidance in this file and reusable lessons in `docs/ai-learning-log.md`. Do not turn one-off preferences or temporary debugging observations into permanent rules.

## Skill layer

Use only skills relevant to the current role and task.

### Discovery and planning

- `superpowers:brainstorming`: clarify requirements and design before creative implementation work; keep the process text-first unless the user requests visuals.
- `superpowers:writing-plans`: create file-level plans for multi-step work.
- `superpowers:executing-plans`: execute an approved plan sequentially in the current workflow.

### Development

- `laravel-best-practices`: Laravel routes, controllers, models, migrations, validation, authorization, queries, jobs, caching, and tests. Read only relevant rule files before editing.
- `vercel-react-best-practices`: React/TSX, Inertia pages, client data fetching, rendering, and frontend performance. Adapt Next.js-only rules; do not introduce Next.js APIs.
- `typescript-advanced-types`: complex type utilities and compile-time safety. Prefer simple inferred types for ordinary code.
- `ponytail`: apply the smallest coherent solution and a deliberate simplification pass.

### Design

- `ui-ux-pro-max`: guide information architecture, interaction flow, responsive layout, usability, and accessibility for UI-facing work.
- `design-system`: reuse or define the minimum necessary tokens and component conventions for consistent color, typography, spacing, states, and behavior.
- `ui-styling`: implement accessible interfaces with the repository's Tailwind and component patterns; prefer existing components before creating new ones.
- Apply these skills automatically when a task creates or materially changes a page, form, navigation flow, responsive layout, or interactive component.
- After implementation, use `vercel-react-best-practices` for frontend performance and `webapp-testing` for browser-visible behavior when runnable.
- Keep `design`, `brand`, `banner-design`, `slides`, image generation, and other media-oriented skills explicit-request only.

### Debugging and QA

- `superpowers:systematic-debugging`: diagnose root cause before proposing a fix.
- `superpowers:test-driven-development`: use a failing regression test before implementing a feature or bug fix when practical.
- `webapp-testing`: verify browser-visible behavior. Screenshots are allowed only as test evidence, not as generated media deliverables.

### Quality

- `superpowers:verification-before-completion`: enforce the policy that completion claims require fresh evidence; quality gates are the concrete commands that produce that evidence.
- Sequential Standards, Spec, risk, reuse, and dead-code reviews are the default.
- `code-review`: optional gated parallel review only.
- `karpathy-guidelines`: assumptions, minimum scope, surgical changes, and verifiable goals.
- `security-review`: risk-triggered security checklist adapted to Laravel and this repository's conventions.
- `improve-codebase-architecture`: explicit request only; do not generate its visual HTML report in the default no-media workflow.

### Writing

- Write concise implementation plans, technical documentation, usage notes, change summaries, and learning-log entries.
- Explain decisions, trade-offs, and verification evidence in plain language.
- Do not create marketing copy, media scripts, presentations, or visual assets unless explicitly requested.

## Verification commands

Run the narrowest relevant command first, then broader checks when practical:

- Frontend tests: `pnpm run test:frontend`
- Frontend build: `pnpm run build`
- Laravel tests: `composer test`
- Diff hygiene: `git diff --check`

The repository currently has no committed TypeScript compiler configuration or frontend lint script. Do not report type-checking or linting as passing until that tooling exists and is actually run.

## Safety and repository hygiene

- Preserve unrelated working-tree changes; never reset or overwrite user work.
- Prefer existing framework features and local conventions over new dependencies or speculative abstractions.
- Do not edit `.env`, generated `vendor/` or `node_modules/` content, or run destructive database commands without explicit approval.
