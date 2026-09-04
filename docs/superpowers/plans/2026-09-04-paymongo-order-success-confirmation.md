# PayMongo Order Success Confirmation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Keep customers on a verified PayMongo order-success confirmation screen instead of automatically sending them to My Orders.

**Architecture:** Preserve the existing `OrderSuccess.tsx` verification and cancellation flow. Add a small local success-state model that is populated only from the verified API response, then render an explicit confirmation view with links to My Orders and the storefront. No backend or payment-provider changes are required.

**Tech Stack:** Laravel 12, Inertia 2, React 18, TypeScript 5.7, Vite 7, Vitest, Tailwind CSS 4.

## Global Constraints

- Keep the existing signed PayMongo return parameters and CSRF-protected verification request.
- Show success only when the API returns both `success: true` and `payment_verified: true`.
- Keep failed/unverifiable returns from being presented as paid.
- Modify only the OrderSuccess page and its focused test; do not add dependencies or change backend settlement behavior.
- Preserve unrelated worktree changes and stage only intended files.

---

### Task 1: Add the failing OrderSuccess regression contract

**Files:**
- Create: `resources/js/Pages/UserSide/Orders/__tests__/OrderSuccess.contract.test.ts`

**Interfaces:**
- Consumes: the current source of `resources/js/Pages/UserSide/Orders/OrderSuccess.tsx`.
- Produces: an executable contract requiring a verified success state and no success-path auto-redirect.

- [x] **Step 1: Write the failing test**

```ts
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const source = readFileSync(
  resolve(process.cwd(), 'resources/js/Pages/UserSide/Orders/OrderSuccess.tsx'),
  'utf8',
);

describe('OrderSuccess PayMongo confirmation', () => {
  it('renders a verified confirmation with explicit navigation actions', () => {
    expect(source).toContain("data?.success && data?.payment_verified");
    expect(source).toContain('Payment Successful!');
    expect(source).toContain('View My Orders');
    expect(source).toContain('Continue Shopping');
    expect(source).toContain('data?.order?.order_number');
  });

  it('does not auto-redirect after verified payment', () => {
    const verifiedBranchStart = source.indexOf(
      'if (data?.success && data?.payment_verified)',
    );
    const verifiedBranchEnd = source.indexOf('} else {', verifiedBranchStart);
    const verifiedBranch = source.slice(verifiedBranchStart, verifiedBranchEnd);

    expect(verifiedBranch).not.toContain('router.visit(postReturnDestination');
  });
});
```

- [x] **Step 2: Run it to verify it fails**

Run:

```powershell
.\node_modules\.bin\vitest.cmd run resources/js/Pages/UserSide/Orders/__tests__/OrderSuccess.contract.test.ts
```

Expected: FAIL because the current success branch redirects to `/my-orders` and the page has no success confirmation or action links.

### Task 2: Render the verified confirmation state

**Files:**
- Modify: `resources/js/Pages/UserSide/Orders/OrderSuccess.tsx`
- Test: `resources/js/Pages/UserSide/Orders/__tests__/OrderSuccess.contract.test.ts`

**Interfaces:**
- Consumes: `data.order.order_number` from the existing successful verification response.
- Produces: a stable `/order-success` view with loading, success, and existing non-success flow behavior.

- [x] **Step 1: Add a typed local success state and page metadata**

Add `Head` and `Link` to the existing Inertia import, define a small verified-order type containing `order_number`, and track `verifiedOrderNumber` plus a `status` state initialized to `loading`.

- [x] **Step 2: Change only the verified branch**

In the existing `data?.success && data?.payment_verified` branch, remove the automatic `router.visit(postReturnDestination, { replace: true })`, store `data?.order?.order_number` with a pending-order fallback, set the status to `success`, and return. Keep session cleanup and all non-success cancellation/return paths intact.

- [x] **Step 3: Replace the spinner-only return with explicit states**

Keep the existing spinner/message while `status === 'loading'`. When `status === 'success'`, render a confirmation card with `Payment Successful!`, the verified order number, a `View My Orders` link to `/my-orders`, and a `Continue Shopping` link to `/`. Include `Head title="Payment Successful"` and preserve `Navigation`.

- [x] **Step 4: Run the focused regression test**

Run:

```powershell
.\node_modules\.bin\vitest.cmd run resources/js/Pages/UserSide/Orders/__tests__/OrderSuccess.contract.test.ts
```

Expected: PASS with 2 tests.

### Task 3: Verify the complete change

**Files:**
- Verify: `resources/js/Pages/UserSide/Orders/OrderSuccess.tsx`
- Verify: `resources/js/Pages/UserSide/Orders/__tests__/OrderSuccess.contract.test.ts`

- [x] **Step 1: Run the full frontend suite**

Run:

```powershell
.\node_modules\.bin\vitest.cmd run
```

Expected: exit code 0 with all frontend tests passing.

- [x] **Step 2: Build the frontend bundle**

Run:

```powershell
.\node_modules\.bin\vite.cmd build
```

Expected: exit code 0 and a fresh `public/build` output.

- [x] **Step 3: Check diff hygiene**

Run:

```powershell
git diff --check
```

Expected: no output and exit code 0.

- [x] **Step 4: Review the scoped diff**

Run:

```powershell
git diff -- resources/js/Pages/UserSide/Orders/OrderSuccess.tsx resources/js/Pages/UserSide/Orders/__tests__/OrderSuccess.contract.test.ts
```

Confirm that the signed verification request, retry loop, cancellation behavior, and unrelated worktree changes remain unchanged.
