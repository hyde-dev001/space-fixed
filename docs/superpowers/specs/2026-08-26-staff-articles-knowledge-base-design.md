# Staff Articles Knowledge Base Design

**Date:** 2026-08-26  
**Status:** Approved in conversation; awaiting written-spec review  
**Scope:** Regular retail Staff accounts and shared employee tasks only

## Goal

Add an authenticated, bilingual Staff Articles experience that documents the real SoleSpace workflows visible to regular retail Staff users. The articles must explain prerequisites, normal steps, approval and rejection outcomes, downstream ownership, recovery paths, and screenshot locations without introducing a database-backed content manager.

## User outcome

A Staff user can open **Articles** from the ERP sidebar, search common questions, filter by topic, switch between English and Tagalog, and follow an instruction page whose content matches the user's permissions and retail business context. Every applicable workflow explains what happens next, who acts next, where rejected work goes, what the customer sees, and how the Staff user can recover.

The shop operator supplies screenshots later by copying image files into predetermined public paths. Until an image exists, the article displays a clean placeholder containing the required path and filename.

## Scope boundaries

### Included

- Regular retail Staff permissions and pages.
- Shared employee tasks: profile/password, notifications, attendance, leave, overtime, and personal payslips.
- Retail orders, shipping/pickup actions present in Staff Job Orders, cancellations, customer refund review, returns, and item inspection.
- Retail product creation and maintenance, product imagery, Shoe Spin Viewer eligibility, and price-change requests.
- Read-only customer and product-inventory visibility.
- English and Tagalog content parity.
- Static, version-controlled content and screenshot files.
- Reusable article UI that later role catalogs can consume.

### Excluded

- Cashier workflows.
- Repairer workflows.
- Logistics dispatcher and rider workflows.
- Manager, HR, Finance, CRM, Inventory, Procurement, or Shop Owner catalogs.
- An admin content editor, database article tables, or runtime uploads.
- Marketing or public customer help-center content.
- Changes to the underlying operational workflows documented by the articles.

Although some cashier, repairer, and logistics users are technically stored as `STAFF` users, permission filtering and the separate role batches keep their documentation outside this Staff catalog.

## Source-of-truth policy

Article claims must be derived from current application behavior rather than assumptions. Each article records the relevant UI page, route or endpoint, permission, business-type constraint, controller or service behavior, and nearby tests. The content review checks these references before publication.

Documentation is not an authorization boundary. Laravel middleware, policies, permissions, tenant scoping, validation, and business rules remain authoritative for operational actions.

## Architecture

### Routes

- `GET /erp/articles` renders the Staff article hub.
- `GET /erp/articles/{slug}` renders or deep-links to one Staff article.

Both routes use the existing ERP employee authentication and suspension checks. Access requires an authenticated ERP employee with at least one regular Staff permission. Existing forced-password-change behavior remains authoritative before the user enters normal ERP pages.

An invalid slug renders a friendly article-not-found state. A valid article excluded by the current user's permissions or retail-business context renders an unavailable-for-this-account state and a link back to the accessible Staff hub.

### Content storage

The implementation uses a typed TypeScript catalog, not Markdown or PHP content arrays. The Staff catalog contains bilingual copy, search terms, access metadata, workflow outcomes, steps, screenshot specifications, recovery guidance, and related-article slugs.

The shared UI consumes the catalog through a small set of stable interfaces. Later role batches add their own catalogs without copying the hub and detail components.

### Proposed article schema

The exact names may follow nearby repository conventions, but the content contract contains:

- Stable unique `slug`.
- Category and display order.
- Required permissions, any-of permission rules, and allowed business types.
- English and Tagalog title, summary, keywords, estimated reading time, prerequisites, steps, outcomes, errors, and related-link labels.
- Workflow states and branches for normal, approved, rejected, cancelled, return, or recovery paths.
- Screenshot slot identifier, public path, English alt text, Tagalog alt text, and reserved aspect ratio.
- Source-coverage metadata used by tests and reviewers.

Both languages share the same structural identifiers so parity can be tested without relying on translated strings.

### Access filtering

The hub filters the Staff catalog using the authenticated user's shared permissions and the shop's business type. Search, category counts, related articles, and recommendations operate only on the already accessible result set.

The Staff catalog is limited to retail-capable shops and regular Staff permissions. It does not surface cashier, repairer, dispatcher, or rider content even when those accounts share the same technical user guard.

## Information architecture

### Sidebar

The ERP sidebar adds **Articles** in the shared Staff/account area. It appears for a regular Staff user with at least one applicable Staff permission. It receives the same active-state, collapsed-state, prefetch, responsive, and scroll-preservation behavior as nearby sidebar entries.

### Article hub

The hub contains:

- A high-contrast Staff knowledge-base header.
- A prominent search field.
- Permission-filtered category chips.
- Recommended/common-question articles.
- Search results with title, summary, category, and estimated reading time.
- English/Tagalog control.
- Clear no-results guidance and reset action.

Search covers accessible titles, summaries, keywords, step copy, status terms, rejection terms, and common English/Tagalog questions. Search does not expose excluded article titles.

### Article detail

Each article uses this sequence when applicable:

1. Title and common question.
2. Who can use the guide.
3. Before you start.
4. Normal workflow map.
5. Numbered steps with associated screenshot slots.
6. What happens next.
7. Approval and rejection branches.
8. Cancellation, return, or recovery path.
9. Common errors and recovery instructions.
10. Permission-filtered related articles.

Articles explicitly answer:

- What status results from the action?
- Who owns the next action?
- What does the Staff user need to monitor?
- What does the customer see?
- Where does a rejected request stop or return?
- Can the user correct, cancel, or resubmit it?

## Approved visual direction

The Articles pages apply the supplied `DESIGN.md` vocabulary within the existing ERP shell:

- Pure black, white, and soft-gray primary surfaces.
- Flat panels with hairline borders rather than decorative drop shadows.
- Pill-shaped search, filters, language control, and primary actions.
- Strong high-contrast typography for the article hub.
- Restrained readable typography and line length for instruction content.
- Existing ERP light and dark themes.
- Existing icon family and semantic interface patterns.

The responsive design starts at 375px and scales through tablet and desktop. Navigation remains predictable, article text does not create horizontal scrolling, and long content uses the main page scroll rather than nested scroll regions.

## Language behavior

Every article ships with English and Tagalog copy. The language switch preserves the current article and scroll context where practical. The selection is remembered in local storage and falls back to English when no preference exists.

Missing translation content is a catalog validation failure. The UI does not silently mix languages to hide incomplete content.

System labels remain recognizable in the instructions. Tagalog copy can retain the exact English button or status label in quotation marks when that is what the interface displays.

## Screenshot contract

Screenshot files live under:

```text
public/images/articles/staff/{category}/{article-slug}/{slot-filename}.webp
```

Example:

```text
public/images/articles/staff/orders/process-retail-order/
├── step-01-open-pending-order.webp
├── step-02-review-order-details.webp
├── step-03-mark-processing.webp
└── step-04-shipping-details.webp
```

When a file is absent or fails to load, the screenshot component shows a clean placeholder with the exact public path. Copying an image to that path and refreshing the page replaces the placeholder without changing article code.

Screenshot rules:

- WebP is preferred; PNG is acceptable when interface text is not legible after WebP compression.
- Screenshots must blur or remove customer names, addresses, phone numbers, email addresses, payment references, and other private data.
- Capture a consistent browser size and zoom.
- Crop to the relevant interface region while preserving enough context to identify the action.
- Every slot supplies English and Tagalog alt text.
- Images declare or reserve dimensions to prevent layout shift.
- Non-critical images lazy-load.

Clicking an image opens an accessible larger preview. Keyboard and pointer users can close it, `Escape` closes it, and focus returns to the triggering image control.

## Staff article inventory

### Getting started and account

1. **Staff workspace and permissions** — sidebar visibility, retail restrictions, and access-denied recovery.
2. **Using the Staff Dashboard** — customer and order summary information, searching, filtering, and opening details.
3. **Viewing and managing notifications** — read/unread, action-required navigation, filtering, bulk read, archive, unarchive, permanent deletion, and preferences.
4. **Profile and password** — account information, first-login password change, password rules, and incorrect-current-password recovery.

### Attendance, leave, overtime, and payslips

5. **Daily attendance workflow** — time in, lunch start, lunch end, time out, disabled actions, and approved-leave behavior.
6. **Early arrival, lateness, and early departure** — reason capture, too-early restriction, and stored attendance outcome.
7. **Requesting leave** — date range, reason, leave-balance validation, and pending, approved, or rejected outcomes.
8. **Requesting and completing overtime** — hours, reason, approval, approved overtime check-in/check-out, approved-leave restriction, and automatic calculation.
9. **Viewing and printing payslips** — period search, pending/approved/paid states, earnings, deductions, net salary, payment details, and print workflow.

### Retail orders, cancellation, refund, and return

10. **Understanding Retail Job Orders** — status tabs, search, order details, payment method, variants, revenue, and displayed statuses.
11. **Processing one or multiple pending orders** — verification, individual processing, bulk processing, and the resulting state.
12. **Shipping or activating pickup** — third-party shipping details, shop-owned coverage messaging, pickup activation, tracking, and customer-facing message.
13. **Understanding cancelled orders** — cancellation reasons and notes, delivery cancellation details, and why fulfilment stops.
14. **Reviewing a customer refund request** — evidence, requested lines, eligibility approval, required rejection reason, repeat-review protection, and shop scoping.
15. **What happens after Staff approves or rejects a refund** — pending Finance handoff, no immediate payout, rejection status and reason, next owner, and monitoring.
16. **Arranging a return** — Staff/shop and Finance prerequisites, customer shipment, Staff pickup, shop-owned logistics, third-party carrier fields, and tracking fields.
17. **Confirming and inspecting returned items** — allowed confirmation states, requested/approved quantities, resellable or damaged disposition, inventory implications, and failed-delivery exception.
18. **Reading refund and return statuses** — awaiting approval, pending Finance, pending customer shipment, pending Staff pickup, in transit, received, processing, succeeded, rejected, failed, and recovery guidance.
19. **Customer delivery receipt and dispute evidence** — confirmed/disputed receipt, approved proof visibility, and rejected or unapproved proof behavior.

### Product management

20. **Understanding Product Management** — list/search behavior, variants, quantities, pending pricing indicators, and available actions.
21. **Creating a product from inventory** — required inventory source, selecting retail stock, product slots, ownership, and business restrictions.
22. **Configuring product details** — name, description, category, brand, price, required fields, and category creation.
23. **Configuring colors, sizes, and quantities** — required color variant, required non-zero size quantity, inventory variants, and empty variants.
24. **Uploading product images** — color-variant images, ordering, upload failure, retry behavior, and existing-image removal.
25. **Using Shoe Spin Viewer images** — ordered frames, company-type eligibility, existing frames, new-upload restrictions, and the existing spin tutorial.
26. **Editing or deleting a product** — Staff edit limits, linked inventory behavior, variant deletion, and irreversible deletion warning.
27. **Fixing product creation errors** — unavailable inventory, missing color/size, zero quantity, unavailable image slot, missing spin target variant, upload failure, and rate limiting.

### Shoe pricing, customers, and inventory visibility

28. **Requesting a shoe price change** — current/proposed price, reason, duplicate pending restriction, and Finance submission.
29. **Understanding price-request outcomes** — Under Review, Awaiting Owner, Active after owner approval, Finance rejection, Owner rejection, and recorded reasons.
30. **Cancelling, correcting, and resubmitting a price request** — cancellable states, finalized-state restriction, rejection reason, correction, and resubmission.
31. **Viewing customer information** — search, active/inactive filter, order count, spending, last order, address, customer-since date, and read-only behavior.
32. **Monitoring product inventory** — search, In Stock/Low Stock/Out of Stock filters, item details, quantities, and the read-only boundary with the Inventory role.

## Key workflow maps

### Normal retail order

```text
Pending → Processing → Shipped → Delivered
```

The article explains the Staff action, validation, and next responsible party at each transition. Cancelled orders leave the normal path and cannot continue through fulfilment.

### Customer-requested refund

```text
Customer request
  → Staff eligibility review
    → rejected with required reason; flow stops
    → approved; Finance remains pending and no payout occurs
      → Finance rejected; flow stops with reason
      → Finance approved; return becomes pending customer shipment
        → customer shipment or Staff-arranged pickup
          → in transit
            → received and inspected
              → Finance executes eligible refund
                → succeeded or failed
```

Manual Staff return confirmation is not offered for the failed-delivery exhaustion flow, which has its own logistics-driven return path.

### Shoe price change

```text
Staff submits request
  → pending / Under Review
    → Finance rejected; reason returns to Staff
    → Finance approved / Awaiting Owner
      → Owner rejected; reason returns to Staff
      → Owner approved; product price is applied and becomes Active
```

The Staff user can cancel only while the request is in a cancellable non-final state. Rejected requests expose the recorded reason before correction and resubmission.

### Attendance and overtime

```text
Time in → optional lunch start → lunch end → time out
Overtime request → pending review → approved → overtime check-in → overtime check-out
```

Early and late attendance can require reasons. Too-early check-in is blocked. Approved leave prevents normal attendance and overtime actions where the current UI enforces that constraint.

## Empty and error states

- Search with no matches shows suggestions and a reset action.
- An empty category is removed after permission filtering.
- Invalid slug shows **Article not found** with a return link.
- Excluded article shows **This guide is not available for your account** without adding it to search or related links.
- Missing or failed screenshot shows the configured placeholder path.
- Content-loading failure provides a retry or return-to-hub action.
- Translation parity failures are caught by automated tests.

## Accessibility and interaction requirements

- Sequential heading hierarchy.
- Visible keyboard focus states.
- Semantic links and buttons.
- Accessible names for icon-only controls.
- Minimum 44px interaction targets.
- Search results and language changes announced appropriately without stealing focus.
- Article text line length remains readable on large screens.
- Color is not the only status indicator.
- Light- and dark-mode contrast meet WCAG AA for normal text.
- Reduced-motion preference is respected.
- Lightbox focus is contained while open and restored when closed.
- Browser Back preserves useful search/filter state where practical.

## Performance requirements

- No new runtime content dependency.
- Staff content is isolated from later role catalogs.
- Non-critical screenshots lazy-load with reserved space.
- Stable article slugs and list keys.
- Search operates on the filtered in-memory Staff catalog and does not require network calls.
- Route-level delivery follows the existing Vite/Inertia application structure.

## Verification strategy

### Backend

- Authenticated eligible Staff user can open the hub and an article route.
- Unauthenticated user cannot access Staff Articles.
- ERP user without applicable regular Staff access is rejected.
- Forced-password-change and suspension behavior remains intact.
- Invalid slug contract produces the intended page state.

### Frontend

- Sidebar Articles visibility and active state.
- Unique slugs and valid related-article references.
- Complete English/Tagalog structural parity.
- Permission and retail-business filtering.
- Search in English, Tagalog, status terms, and rejection terms.
- Category filtering and empty results.
- Direct article navigation and invalid/inaccessible states.
- Language preference persistence.
- Screenshot placeholder, successful image display, failed-image fallback, and lightbox behavior.
- Keyboard navigation and accessible names.

### Quality gates

- Narrow Staff Articles frontend tests.
- Relevant Laravel feature tests.
- Full frontend test command when practical.
- Production frontend build.
- `git diff --check`.
- Browser verification at 375px, tablet, and desktop widths in light and dark mode.

## Rollout and future role catalogs

This batch ships the shared article framework and the complete regular Staff catalog. Later account requests reuse the same schema and components but receive separate content catalogs, access filters, routes or guards where needed, screenshot directories, and verification coverage.

Shop Owner work remains a later batch and will use personalized recommendations plus an **All Articles** view, as previously selected. No later catalog is implemented or drafted as part of this Staff batch.

## Risks and mitigations

- **Operational behavior changes after publication:** source-coverage metadata and focused article tests make stale claims easier to locate.
- **Large bilingual content:** structural parity tests prevent silently incomplete translations.
- **Missing screenshots:** predetermined filenames and graceful placeholders allow text-first publication.
- **Sensitive data in screenshots:** explicit redaction rules are part of the upload contract.
- **Technical `STAFF` overlap with other roles:** regular Staff permission filters and catalog boundaries exclude cashier, repairer, and logistics documentation.
- **Articles mistaken for authorization:** the UI states that actual access and actions remain governed by the operational pages and backend permissions.

## Acceptance criteria

- Eligible regular retail Staff users see **Articles** in the ERP sidebar.
- Ineligible role-specific users do not receive the regular Staff catalog through normal navigation.
- The hub and detail experience match the approved high-fidelity direction and `DESIGN.md` vocabulary.
- All 32 approved Staff article topics exist in English and Tagalog.
- Applicable articles document normal, approval, rejection, cancellation, return, and recovery outcomes.
- Search, categories, related links, and direct routes respect the accessible Staff result set.
- Missing screenshots show clean placeholders with exact paths; correctly named images display without article-code changes.
- Automated tests cover access, filtering, bilingual parity, navigation, screenshot behavior, and major UI states.
- Relevant tests, production build, and diff hygiene pass before completion is claimed.
