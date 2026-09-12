# Subscription Management UI Refresh Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Improve the page hierarchy and plan-management usability without changing behavior.

**Architecture:** Recompose the existing JSX and reuse its state/actions/components. Add only one local plan-status filter and Tailwind styling.

**Tech Stack:** React, TypeScript, Inertia, Tailwind CSS.

## Global Constraints

- Metrics appear directly below the page header.
- Preserve all create, edit, archive, reactivate, filtering, pagination, and subscription-detail behavior.
- Add no dependency and no backend change.

### Task 1: Refresh the page layout

**Files:**
- Modify: `resources/js/Pages/superAdmin/Shops/SubscriptionManagement.tsx`

- [ ] Move Create Plan into the header and metrics immediately below alerts.
- [ ] Remove fabricated metric percentage badges.
- [ ] Add Active/Archived plan tabs and compact equal-height cards.
- [ ] Add a clear Subscriptions heading above the existing filters/table.
- [ ] Run the available focused backend regression test and frontend build when the environment permits.
