# Student ID Manual Review Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Student ID registration with required front/back images, no OCR, and mandatory secure admin review.

**Architecture:** Reuse the existing registration document options, image fingerprinting, private identity-document storage, classifier, and admin review UI. Add one config flag for manual-only documents and enforce it server-side.

**Tech Stack:** Laravel 12, PHP 8.2, React 18, TypeScript, Vitest, PHPUnit.

---

- [x] Add Student ID to the shared frontend document option and screening pipeline; bypass OCR while retaining duplicate-image checks.
- [x] Add the Student ID document definition and server-side manual-review enforcement.
- [x] Update the admin review label/message without exposing public document URLs.
- [x] Add focused frontend and backend regression tests before implementation changes, then make them pass.
- [x] Run diff hygiene, focused tests, and the frontend build; review changed files for dead code and security regressions.
