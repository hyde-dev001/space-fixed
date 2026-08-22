# AI Learning Log

## 2026-08-13 — Frontend delivery workflow

- After completing frontend code changes, follow `docs/git-workflow.md`.
- Generate and include a fresh `public/build`, run the relevant pre-push checks, and push only the task's feature branch.
- Leave Pull Request creation to the user unless they explicitly request otherwise.

## 2026-08-13 - Database ID hydration

- Treat foreign-key IDs as integers at Eloquent model boundaries. MySQL/MariaDB can hydrate integer columns as strings, which breaks strict authorization comparisons unless the model casts those IDs; cover the boundary with a string-hydration regression test.

## 2026-08-13 - Pre-authentication continuation

- When a pre-authentication flow cannot reliably carry authorization through a rotated session, use a short-lived authenticated proof for the next step. Keep the database token authoritative and one-time, and never persist or log the proof.

## 2026-08-14 - Shared session guard isolation

- Multiple Laravel session guards can coexist in one browser session. Involuntary lifecycle enforcement must remove only the invalid guard, preserve unrelated authenticated guards and session data, rotate the session identifier, and leave route-specific middleware responsible for selecting the required actor.

## 2026-08-14 - Middleware priority preserves framework prerequisites

- Laravel's `Middleware::priority()` replaces the framework priority list. Any custom list must retain cookie decryption and session startup before authentication; otherwise session-backed API routes can return `401` before reading a valid browser session, especially when Sanctum does not classify the production host as stateful.

## 2026-08-23 - Checkout voucher suggestion state

- Keep voucher eligibility and claim state server-authoritative. Reuse checkout pricing and `ShippingVoucherService` so payment suggestions cannot advertise a logistics discount outside Shop-owned coverage.

## 2026-08-15 - Shop module route catalog parity

- A new named Shop Owner route is incomplete until its authoritative route bucket, method override, and module `supporting_routes` entry are updated together; `ShopModuleCatalogTest` detects drift between route registration and the capability catalog.

## 2026-08-22 - Centralized approval contract

- When a workflow is presented through a shared Action Center, freeze the approval policy at submission and let the existing domain service remain authoritative. Queue adapters, notifications, detail panels, and mutations should all derive responsibility from that same persisted snapshot.
- A named route added for a shared detail or summary surface must be added to the authoritative route catalog in the same change, including tenant-scoped `show` routes; route-catalog parity tests catch this boundary before deployment.
