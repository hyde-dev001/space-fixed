# PayMongo Order Success Confirmation Design

## Problem

The PayMongo return URL opens `/order-success` with a signed order reference. `OrderSuccess.tsx` renders only a verification spinner and then automatically navigates to `/my-orders` after the server confirms payment. The customer therefore sees a mostly empty loading state instead of a payment confirmation. The existing `PaymentSuccess.tsx` page is not connected to this route.

## Goal

After a successful PayMongo return, keep the customer on `/order-success` and show a clear confirmation containing the verified order number and explicit actions for My Orders or continued shopping.

## Design

`OrderSuccess.tsx` remains responsible for the return flow. It will keep parsing the existing signed query parameters, calling the existing `verify-payment-return` endpoint with its retry behavior, and clearing the pending order session value only after the flow has been handled. The server-side PayMongo verification, settlement, invoice progression, authorization, and signature checks are not changed.

When the endpoint returns both `success: true` and `payment_verified: true`, the page will store the returned order number and transition from `loading` to `success`. It will no longer redirect automatically in this branch. The success state will render a confirmation card with a verified-payment message, the order number, a `View My Orders` link to `/my-orders`, and a `Continue Shopping` link to `/`.

Non-success handling remains conservative: failed or unverifiable returns continue using the existing cancellation and return behavior, so an unpaid order is not presented as paid. The page must never show success based only on the URL query; it requires the verified API response.

## Scope and constraints

- Modify only `resources/js/Pages/UserSide/Orders/OrderSuccess.tsx` and its focused test.
- Reuse the existing `Navigation`, Inertia `Link`, and Tailwind conventions.
- Do not change PayMongo credentials, webhook handling, order settlement, routes, database schema, or dependencies.
- Keep the signed return parameters and CSRF-protected verification request intact.
- Avoid changing the separate repair-payment return flow.

## Verification

- Add a regression contract test proving the success state uses the verified order response and does not auto-navigate to `/my-orders`.
- Run the focused OrderSuccess test, then the full frontend suite.
- Run the Vite production build and `git diff --check`.
