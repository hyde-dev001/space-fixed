# SoleSpace AI Development Operating Model

## Project context

SoleSpace is a Laravel 12 application with Inertia 2, React 18, TypeScript 5.7, Vite 7, and Tailwind CSS 4.

The backend uses PHP 8.2 and Composer.

Use `pnpm` for frontend commands because `package.json` declares pnpm as the package manager.

This file defines the default operating contract for AI-assisted development in this repository.

Repository conventions, existing architecture, authorization boundaries, and user-approved specifications take precedence over generic framework advice.

---

## Core principles

* Prefer the smallest coherent solution that fully satisfies the request.
* Preserve existing behavior unless the requested change explicitly requires modifying it.
* Reuse existing architecture, components, services, helpers, policies, types, and patterns before introducing new ones.
* Do not add unrelated features, abstractions, dependencies, cleanup, redesigns, or refactors.
* Never broaden scope merely because another improvement appears convenient.
* Understand existing behavior before changing it.
* Verification evidence is required before claiming completion.
* Preserve unrelated user work at all times.

---

## Default operating mode

* Use one main agent and execute work sequentially.
* Treat Tech Lead, Backend, Design, Frontend, QA, Security, and Writer as role modes of the same agent, not separate agents.
* Roles are scope-driven, not mandatory stages.
* Activate only the roles relevant to the current task.
* Revisit a role only when new work requires it.
* Do not dispatch subagents unless the optional parallel-review gate is satisfied.
* Never allow multiple agents to edit repository files simultaneously.
* Do not use media-generation, banner, slide, social, audio, image-generation, or video skills unless the user explicitly requests media work.
* UI/UX design for application interfaces is normal software-development work and is not considered media generation.
* Writing is part of normal delivery when needed: implementation plans, technical documentation, change summaries, migration notes, usage notes, and durable project guidance.

---

# Workflow

## Step 1: Triage

Before modifying code:

1. Identify the user's requested outcome.
2. Determine the affected feature/domain.
3. Identify likely files and architectural boundaries.
4. Determine the task size and risk level.
5. Inspect relevant existing implementation and nearby tests.
6. Inspect `git status` and preserve unrelated work.
7. Identify relevant skills and verification methods.

When `.codegraph/` exists and is usable, prefer CodeGraph for understanding relationships before broad application-code searches.

For UI-facing work, activate the Design role before implementation.

### Task sizing

Classify the task during triage.

#### Trivial

Examples:

* copy changes;
* isolated Tailwind/CSS adjustments;
* import fixes;
* small obvious one-file corrections;
* narrowly scoped UI state corrections.

Use the reduced workflow:

```text
inspect
→ implement
→ narrow verification
→ final diff inspection
→ report
```

Do not manufacture unnecessary plans, review ceremonies, or tests for trivial work.

#### Standard

Examples:

* normal bug fixes;
* small or medium features;
* multi-file UI changes;
* controller/service changes;
* ordinary CRUD workflow changes.

Use the normal workflow defined in this file.

#### High-risk

Examples:

* authentication;
* authorization;
* tenant isolation;
* payments;
* refunds;
* payroll;
* migrations;
* destructive data changes;
* uploads;
* private documents;
* secrets;
* external integrations;
* background jobs affecting business state;
* order/inventory/payment state transitions;
* broad architectural changes.

High-risk work requires explicit risk review and stronger verification.

---

## Step 2: Implementation contract

Before editing a Standard or High-risk task, establish a short implementation contract containing:

* requested outcome;
* acceptance criteria;
* affected files or architectural areas;
* important constraints;
* known risks;
* behavior that must remain unchanged;
* narrow verification commands.

For Trivial tasks, the contract may remain implicit when scope is obvious.

Ask for clarification only when a missing decision would materially change the implementation, data model, authorization model, workflow, or user-visible behavior.

Do not ask questions whose answers can reasonably be determined by inspecting the repository or existing specification.

When requirements are sufficiently clear, proceed.

---

## Step 3: Explore before modifying

Before implementation:

1. Trace the existing flow.
2. Inspect nearby implementation patterns.
3. Find relevant tests.
4. Identify reusable helpers, components, policies, services, types, or utilities.
5. Confirm authorization and tenant boundaries.
6. Identify existing error, notification, modal, form, and state-handling conventions where applicable.

Do not replace an established repository pattern with a theoretically cleaner abstraction unless the requested work actually requires it.

For multi-step work, write a short file-level implementation plan.

---

## Step 4: Execute sequentially

Implement one coherent scope at a time.

For each meaningful change:

1. modify the minimum necessary code;
2. preserve nearby conventions;
3. run the narrowest useful verification;
4. read the result;
5. address failures before continuing.

Do not continue stacking changes on top of an unexplained failure.

Avoid speculative abstractions.

Avoid premature generalization.

Avoid unrelated formatting changes.

Avoid drive-by refactoring.

Do not remove unfamiliar code unless its references and runtime role have been confirmed.

---

# UI and frontend work

## Design behavior

When a task creates or materially changes a:

* page;
* modal;
* card;
* table;
* form;
* navigation flow;
* responsive layout;
* interactive component;
* empty state;
* dashboard section;

activate the Design role.

Use:

* `ui-ux-pro-max` for information architecture, usability, responsive behavior, interaction flow, and accessibility;
* `design-system` for existing tokens, typography, spacing, states, and visual consistency;
* `ui-styling` for implementation using repository Tailwind and component conventions.

### Existing-theme rule

The existing SoleSpace visual system is authoritative.

When adding or changing UI:

* reuse existing components first;
* reuse existing color tokens;
* reuse spacing patterns;
* reuse modal/card/table conventions;
* reuse typography hierarchy;
* reuse button styles;
* reuse loading, empty, success, warning, and error states;
* preserve the surrounding page's visual language.

Do not create unnecessarily colorful, decorative, gradient-heavy, glassmorphic, oversized, or stylistically inconsistent "AI-generated" UI.

Do not redesign unrelated surrounding UI while fixing a specific issue.

### Frontend implementation

For React/TSX work:

* follow `vercel-react-best-practices`;
* adapt Next.js-specific guidance rather than introducing Next.js APIs;
* use `typescript-advanced-types` only when complexity genuinely requires it;
* prefer ordinary inferred types for ordinary application code;
* avoid unnecessary `any`;
* avoid unsafe assertions;
* keep component responsibilities focused;
* prefer readable control flow;
* preserve existing Inertia patterns.

Use lazy loading or code splitting only for genuinely heavy or conditional UI where there is a meaningful benefit.

Do not split small components or bundles merely to satisfy a checklist.

---

# Laravel and backend work

Use `laravel-best-practices` for relevant:

* routes;
* middleware;
* controllers;
* Form Requests;
* policies;
* models;
* services;
* actions;
* migrations;
* jobs;
* events;
* notifications;
* queries;
* caching;
* queues;
* tests.

Read only rules relevant to the task.

Prefer framework-native Laravel features and existing SoleSpace conventions over custom infrastructure.

Avoid introducing new dependencies when Laravel or the repository already provides an adequate mechanism.

---

# Authorization and tenant boundaries

Authorization and tenant isolation are repository-wide invariants.

For tenant-scoped functionality:

* never trust shop, user, employee, owner, order, or other tenant-related identifiers supplied by the client without server-side authorization;
* scope reads and writes to the authenticated actor's permitted tenant/shop;
* preserve Policies, Gates, middleware, scopes, services, and authorization boundaries already used by the repository;
* do not weaken authorization merely to make a UI or endpoint work;
* never expose cross-shop records through identifiers, queries, routes, APIs, exports, notifications, or frontend payloads;
* preserve role and permission distinctions;
* verify sensitive write actions server-side even when the frontend hides the action;
* test unauthorized access when changing protected workflows;
* test cross-tenant access when the affected workflow can reference tenant-owned resources.

Frontend visibility is never an authorization boundary.

---

# Data integrity and workflow changes

When modifying business-state transitions:

* understand the canonical current workflow first;
* preserve valid existing transitions unless explicitly changing them;
* reject impossible or conflicting transitions;
* consider idempotency for retryable actions;
* use transactions when multiple dependent writes must succeed atomically;
* use locking when concurrent modification can corrupt state;
* perform external side effects after commit where appropriate;
* avoid sending notifications for transactions that later roll back;
* preserve historical/audit data;
* do not silently reinterpret legacy data.

For migrations:

* prefer additive and reversible changes;
* consider existing production rows;
* avoid unsafe assumptions about legacy data;
* do not run destructive database commands without explicit user approval.

---

# Debugging and QA

Use `superpowers:systematic-debugging` when diagnosing defects.

Diagnose the root cause before applying a fix.

Avoid patching symptoms when the underlying problem is identifiable.

Use `superpowers:test-driven-development` when a practical regression test can reproduce a bug or define a feature boundary.

Prefer:

```text
reproduce
→ failing regression test when practical
→ implement
→ passing regression test
→ broader verification as needed
```

Use `webapp-testing` for browser-visible behavior when runnable.

Screenshots may be used as testing evidence.

Do not treat screenshots as proof of backend correctness, authorization, or data integrity.

---

# Review gate

Review checks are obligations, not necessarily separate tool invocations.

Use a named skill when it exists, is relevant, and provides value.

If a skill is unavailable, perform the underlying review directly.

For Trivial work, mark irrelevant checks `N/A` rather than manufacturing work.

For Standard and High-risk changes, perform the applicable review checks below.

## Required review checks

### 1. Simplification

Apply `ponytail` principles:

* remove avoidable complexity;
* remove duplication introduced by the change;
* remove speculative abstractions;
* avoid unnecessary dependencies;
* choose the smallest coherent solution.

Do not invoke a separate parallel simplify workflow.

---

### 2. Standards review

Review changed code against:

* repository conventions;
* nearby patterns;
* framework conventions;
* established naming;
* established architectural boundaries.

---

### 3. Specification review

Compare the completed implementation against:

* user requirements;
* approved specification;
* acceptance criteria;
* explicit constraints;
* behaviors that were required to remain unchanged.

Do not silently implement additional behavior.

---

### 4. Correctness and risk review

Inspect for:

* regressions;
* edge cases;
* state-transition errors;
* race conditions;
* authorization failures;
* tenant leakage;
* data-integrity risks;
* incorrect error handling;
* stale frontend state;
* duplicate submissions;
* unexpected side effects.

---

### 5. TypeScript quality

For changed TS/TSX, review:

* naming;
* focused responsibilities;
* readable control flow;
* typed boundaries;
* safe narrowing;
* error handling;
* nullable states;
* unnecessary assertions;
* unnecessary `any`;
* duplicated derived state.

Use `typescript-advanced-types` only when advanced typing is justified.

Use `vercel-react-best-practices` where applicable.

---

### 6. Frontend performance

Only when frontend bundle or rendering behavior is materially affected, review:

* direct imports;
* unnecessary rerenders;
* expensive derived state;
* unnecessary effects;
* large dependencies;
* lazy loading;
* deferred third-party code.

Do not optimize without evidence or plausible material benefit.

---

### 7. Improvement evidence

Support claims such as "faster", "simpler", "smaller", or "more efficient" with evidence when practical.

Possible evidence:

* tests;
* query count;
* bundle size;
* render count;
* latency;
* code-path reduction;
* complexity reduction;
* behavior comparison.

If no baseline was captured, report:

```text
not measured
```

Do not manufacture quantitative claims.

---

### 8. Security review

Use `security-review` when work involves:

* authentication;
* authorization;
* tenant boundaries;
* uploads;
* API endpoints;
* payments;
* refunds;
* payroll;
* secrets;
* personal/private data;
* third-party integrations;
* private documents.

Pair it with relevant Laravel security conventions.

Adapt or ignore framework-specific examples that do not apply to Laravel.

---

### 9. Verification-before-completion

Apply `superpowers:verification-before-completion`.

No completion claim is allowed without fresh and relevant evidence.

This is a policy, not itself a test command.

Concrete tests, builds, inspections, and runtime checks provide the evidence.

---

# Optional parallel review

Parallel review is disabled by default.

It may be used only when all of the following are true:

* the user explicitly requests or approves parallel review;
* the task is medium/large, high-risk, or materially benefits from independent review;
* every lane is bounded and read-only;
* lanes cover distinct concerns such as security, test gaps, or performance;
* no more than three subagents are used;
* subagents do not modify files;
* subagents do not commit;
* subagents do not push;
* subagents do not perform destructive actions;
* the main agent waits for all results;
* the main agent validates findings before accepting them;
* one consolidated report is produced.

The installed `code-review` skill may use parallel reviewers internally.

Invoke it only through this gate and only when a real base commit/branch and originating specification are available.

Otherwise perform Standards, Spec, and Risk reviews sequentially.

---

# Completion mandate

Before claiming completion:

## 1. Review record

Confirm each applicable review check has a recorded result:

* pass;
* finding resolved;
* `N/A`;
* not run with reason.

Do not rerun a review merely because the completion stage has begun.

Rerun only when later changes made prior evidence stale.

---

## 2. Reuse audit

Confirm whether the implementation appropriately reused existing:

* helpers;
* components;
* hooks;
* services;
* actions;
* models;
* policies;
* types;
* utilities;
* framework functionality.

Do not create duplicate infrastructure without justification.

---

## 3. Dead-code scan

Inspect changed areas for:

* unused imports;
* unreachable branches;
* stale references;
* abandoned TODOs;
* temporary debug output;
* commented-out implementation;
* obsolete code created by the change.

Confirm references and runtime entry points before deleting unfamiliar code.

---

## 4. Final diff inspection

Always inspect the final working-tree scope.

At minimum use appropriate Git inspection such as:

```bash
git status --short
git diff --stat
git diff
git diff --check
```

For large diffs, inspect changed files individually when necessary.

Confirm that:

* every changed file belongs to the requested scope;
* unrelated user work remains untouched;
* no accidental formatting changes are present;
* no debug statements remain;
* no temporary files remain;
* no generated files were unintentionally committed or modified;
* no unrelated cleanup slipped into the diff.

`git diff --check` is whitespace hygiene only and is not a substitute for reading the actual diff.

---

## 5. Quality gates

Run concrete verification appropriate to the change.

Prefer the narrowest relevant checks first.

Possible commands include:

```bash
pnpm run test:frontend
pnpm run build
composer test
git diff --check
```

Use narrower test targets when available.

Broaden verification according to risk and affected surface area.

Do not run expensive unrelated test suites merely for ceremony when a narrower check sufficiently validates a trivial change.

---

## 6. Browser verification

For browser-visible behavior, use browser/runtime verification when practical.

Verify relevant:

* rendering;
* interaction;
* responsive behavior;
* loading states;
* disabled states;
* empty states;
* validation errors;
* success states;
* navigation;
* modal behavior.

Do not claim browser verification when it was not performed.

---

## 7. Documentation

Update documentation only when behavior, architecture, workflow, configuration, or developer usage materially changed.

Do not create documentation merely to satisfy process.

---

## 8. Durable learning

`docs/ai-learning-log.md` is `N/A` by default.

Add an entry only when the task reveals a durable, non-obvious project rule likely to affect unrelated future work.

Do not log:

* obvious implementation details;
* one-off bugs;
* temporary debugging observations;
* temporary workarounds;
* task-specific instructions;
* information already documented elsewhere;
* secrets;
* tokens;
* credentials;
* personal data.

Prefer updating the authoritative existing documentation when a rule already has a natural home.

---

## 9. Evidence

Report exact verification performed and its result.

Never imply that a:

* test;
* build;
* lint;
* type check;
* security review;
* browser check;
* migration;
* benchmark;

passed unless it was actually executed and passed.

If something could not be verified, state that clearly.

---

# Skill layer

Use only skills relevant to the current role and task.

Skills support the workflow; they do not define the workflow.

## Tool and skill fallback

If a referenced tool or skill is unavailable:

* apply its documented principles directly where possible;
* use the closest repository-native alternative;
* continue the task when safely achievable;
* report the unavailable tool only when its absence materially limited implementation or verification.

Never fail an otherwise achievable task solely because an optional skill is unavailable.

Never fabricate skill output.

---

## Discovery and planning

### `superpowers:brainstorming`

Use to clarify requirements and design for genuinely creative or ambiguous implementation work.

Keep the process text-first unless visuals are explicitly requested.

Do not invoke brainstorming for obvious mechanical fixes.

### `superpowers:writing-plans`

Use for file-level plans on multi-step work.

### `superpowers:executing-plans`

Use to execute an approved implementation plan sequentially.

---

## Development

### `laravel-best-practices`

Use for Laravel implementation and review.

Read only relevant rules.

### `vercel-react-best-practices`

Use for React/TSX/Inertia work.

Adapt Next.js-only guidance and never introduce Next.js APIs into SoleSpace.

### `typescript-advanced-types`

Use when complex compile-time safety genuinely requires advanced types.

Prefer straightforward inferred types otherwise.

### `ponytail`

Use to enforce minimum scope and deliberate simplification.

---

## Design

Use automatically when materially changing application UI:

* `ui-ux-pro-max`;
* `design-system`;
* `ui-styling`.

After implementation:

* use `vercel-react-best-practices` for relevant frontend-performance review;
* use `webapp-testing` for runnable browser-visible verification.

Keep `design`, `brand`, `banner-design`, `slides`, image generation, and other media-focused skills explicit-request only.

---

## Debugging and QA

Use:

* `superpowers:systematic-debugging` for root-cause diagnosis;
* `superpowers:test-driven-development` when a practical regression test can lead the change;
* `webapp-testing` for browser-visible verification.

---

## Quality

Use:

* `superpowers:verification-before-completion`;
* `karpathy-guidelines`;
* `security-review` when risk-triggered.

Sequential Standards, Spec, Risk, Reuse, and Dead-code reviews remain the default.

`code-review` is optional and restricted to the approved parallel-review gate.

`improve-codebase-architecture` is explicit-request only.

Do not generate its visual HTML report as part of the normal no-media workflow.

---

## Writing

Write concise:

* implementation plans;
* technical documentation;
* migration notes;
* usage notes;
* change summaries;
* verification summaries;
* durable project guidance.

Explain meaningful technical decisions and trade-offs in plain language.

Do not create marketing copy, presentations, media scripts, or visual assets unless explicitly requested.

---

# Verification commands

Run the narrowest relevant command first.

Current repository examples:

### Frontend tests

```bash
pnpm run test:frontend
```

### Frontend production build

```bash
pnpm run build
```

### Laravel tests

```bash
composer test
```

### Git/diff hygiene

```bash
git status --short
git diff --stat
git diff
git diff --check
```

The repository currently has no committed TypeScript compiler configuration or frontend lint script.

Do not report type-checking or frontend linting as passing until that tooling exists and is actually run.

Do not invent commands merely because they are conventional in another repository.

---

# Git safety

The working tree belongs to the user.

Do not:

* reset user changes;
* discard unrelated modifications;
* overwrite unrelated work;
* force checkout files containing user changes;
* perform destructive cleanup;
* rewrite history.

Do not perform the following unless the user explicitly requests it:

* commit;
* push;
* force-push;
* merge;
* rebase;
* stash;
* branch deletion;
* tag creation;
* release creation;
* history rewriting.

Do not switch branches when doing so could disturb existing work unless required by the explicit request.

Editing working-tree files required by the task is allowed.

Always inspect `git status` before substantial changes and again before completion.

---

# Repository safety

* Preserve unrelated working-tree changes.
* Prefer existing framework functionality over new dependencies.
* Prefer repository-local conventions over generic examples.
* Do not edit `.env` unless the user explicitly requests configuration work requiring it.
* Never expose credentials or secrets.
* Do not edit generated `vendor/` content.
* Do not edit generated `node_modules/` content.
* Do not run destructive database commands without explicit approval.
* Do not delete migrations or production-history records casually.
* Do not alter lockfiles unless dependency changes genuinely require it.
* Do not add dependencies without a concrete need.
* Do not weaken security, authorization, validation, or auditing merely to make a feature work.

---

# Scope discipline

The user's requested scope is authoritative.

When implementing a request:

* do exactly what is required;
* include supporting changes necessary for correctness;
* do not add unrelated functionality;
* do not remove unrelated functionality;
* do not redesign unrelated UI;
* do not change architecture unless necessary;
* do not introduce speculative extensibility;
* do not "improve" behavior the user did not ask to change.

If an adjacent problem is discovered but is outside scope:

* leave it unchanged unless it blocks correctness or safety;
* mention it separately when materially important.

---

# Persistence

Keep durable project-wide AI guidance in this file.

Keep genuinely reusable, non-obvious project lessons in `docs/ai-learning-log.md`.

Do not convert:

* temporary debugging findings;
* individual task preferences;
* temporary implementation details;
* one-off exceptions;

into permanent repository rules.

The goal is a stable operating model, not an ever-growing list of historical instructions.
