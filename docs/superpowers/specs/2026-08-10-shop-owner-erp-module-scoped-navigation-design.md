# Shop Owner ERP Module-Scoped Navigation Design

**Date:** 2026-08-10

**Status:** Draft after design approval; pending written-spec review and user review

## Goal

Make the shop-owner ERP Workspace a true module boundary. The Workspace remains the module picker, each selected module opens inside the shared ERP shell at a stable module-scoped URL, and the ERP sidebar shows only pages belonging to that module.

The normal shop-owner portal must not duplicate ERP navigation. Its Employee Modules group and its standalone Logistics accordion are removed for all shop owners. Those capabilities remain available through the ERP Workspace when the corresponding module is selected.

## Problem

The current owner ERP flow has two navigation contracts that do not agree:

- the portal sidebar is filtered by globally accessible modules and exposes Logistics and Employee Modules directly;
- owner mode in `AppSidebar_ERP` delegates to the full shop-owner sidebar, so opening the ERP Workspace displays every enabled owner page instead of one module;
- `WorkspaceController` gives each module a single entry URL, and Retail currently points to `shop-owner.products`, which exits the ERP shell and opens the regular Product Management page;
- no active module is carried through the route or shared Inertia state, so the sidebar cannot scope itself to the selected module.

This is primarily a routing and navigation-scope defect. Existing server authorization remains the security boundary and must continue to deny ineligible, disabled, cross-tenant, or otherwise unauthorized access.

## Approved user experience

### Portal state

The regular shop-owner portal keeps its existing core navigation and the single **ERP Workspace** entry. Core portal items include Dashboard, Audit Logs, User Access Control, Suspend Accounts, Assist Center, Vouchers & Discount, and Approval Pages. It no longer renders:

- the **Employee Modules** group containing HR & Employees, Finance, Inventory, Procurement, CRM, and Logistics;
- the separate **Logistics** accordion containing Dashboard, Shipments, and Riders.

This removal applies to all shop owners, including Individual accounts. The change removes redundant sidebar navigation; it does not by itself delete existing backend routes or database capabilities.

### Workspace state

`/shop-owner/erp/workspace` remains the module picker. Its module cards link to readable, stable module routes:

```text
/shop-owner/erp/retail
/shop-owner/erp/repair
/shop-owner/erp/hr
/shop-owner/erp/finance
/shop-owner/erp/crm
/shop-owner/erp/inventory
/shop-owner/erp/procurement
/shop-owner/erp/logistics
```

The readable slugs map server-side to the existing module keys, for example `retail` maps to `retail_operations` and `hr` maps to `hr_employees`.

While the picker is open and no module is active, the owner shell may retain the core portal items alongside **ERP Workspace**. It must not render the **Employee Modules** group or the standalone **Logistics** accordion in this state. After a module is selected, the scoped ERP sidebar replaces those core items with only the selected module's pages and the ERP Workspace link.

### Module state

Each module route renders a lightweight landing page inside `AppLayoutERP` containing:

- the selected module name and description;
- links to the available pages in that module;
- the scoped ERP sidebar;
- a link back to the main ERP Workspace.

The landing page does not duplicate domain logic. The sidebar remains the primary navigation, and page links use owner ERP routes under `/shop-owner/erp/*`.

When the owner opens Retail Operations, for example, the owner sees the Retail landing page and Retail pages only. Opening Finance, CRM, or Logistics produces the equivalent isolated view for that module.

## Route and active-module contract

Add a stable owner route family under `/shop-owner/erp/{module}` while keeping the static `/shop-owner/erp/workspace` route distinct and registered first. The route must:

1. resolve the authenticated owner and tenant through the existing ERP actor context;
2. resolve the readable slug to a known module key;
3. evaluate eligibility, enabled state, and accessibility with `ShopModuleAccessService`;
4. render the module landing page with the active module and canonical page links;
5. share the active module with the ERP shell so a refresh or direct link preserves scope.

The active module is server-derived. A client-provided module value is never treated as authorization. Unknown, malformed, disabled, or ineligible modules use the existing safe denial behavior.

The Workspace payload replaces direct portal entry links with module-scoped URLs. Every page displayed by an owner ERP module navigation group must have an owner ERP route under `/shop-owner/erp/*`. A portal-only entry such as the current Retail `shop-owner.products` target must receive an owner ERP counterpart or an actor-aware ERP wrapper so opening the module does not switch layouts.

Existing portal URLs remain intact initially for backward compatibility. This design removes their sidebar exposure and changes Workspace navigation to the owner ERP route namespace; a separate redirect/retirement decision is not required for this fix.

## Navigation architecture

### Owner portal sidebar

`AppSidebar_shopOwner` remains responsible for the normal portal shell. Its portal-mode navigation must no longer render the Employee Modules or standalone Logistics groups. It must continue to render core portal items and the ERP Workspace entry where the existing account/feature rules allow it.

### Owner ERP sidebar

`AppSidebar_ERP` remains the shell entry point. In owner mode it must render a scoped owner ERP navigation state rather than the full portal sidebar:

- always show ERP Workspace;
- show one section for the active module;
- show only navigation items whose module key matches the active module;
- hide core portal pages, approvals, settings, and all other module groups;
- do not show employee self-service pages to the owner.

Reuse the existing navigation metadata and module keys where possible. Do not create a second authorization system or a parallel collection of unrelated page rules. Any navigation item intended for an owner ERP module must have an explicit module key and an owner ERP route. Ambiguous or untagged operational items are excluded until classified.

When the owner is outside the ERP shell, the portal sidebar behavior remains separate from the scoped ERP sidebar. Employee ERP users keep the existing employee navigation and permission filtering.

## Owner operation boundary

The feature is not complete when the owner can only see module links. For every enabled module exposed by the Workspace, the owner must be able to:

- open the module landing page;
- open its owner ERP page routes;
- complete at least one representative operation supported by that module’s existing owner capability contract;
- receive the correct tenant-scoped data and action result;
- remain inside the owner ERP shell while navigating the module.

Representative operation tests must use the existing route catalog, controllers, Form Requests, policies, and services. They must authenticate the `shop_owner` guard, derive tenant authority from `ErpActorContext::tenantOwner()`, and prove that a client-supplied shop identifier cannot change scope. High-risk operations continue to require their existing domain authorization, maker/checker, validation, payment, upload, and audit controls.

This design does not grant new owner mutations merely to make the UI visible. Any operation not already safe and classified for owner access remains unavailable and must not be presented as an actionable page.

## Authorization and security

- `ShopModuleAccessService` remains the source for module eligibility and enabled-state decisions.
- ERP actor resolution and tenant isolation remain server-side and request-scoped.
- Route slugs are lookup values, not authority.
- Disabled or ineligible modules fail closed.
- Unknown routes and unsupported module/page combinations use the existing ERP denial response.
- Cross-shop identifiers are ignored as authority or denied according to the existing contract.
- Client-side filtering is a UX convenience only.
- Employee routes, employee permissions, and employee self-service boundaries remain unchanged.
- Removing sidebar items must not remove the underlying authorization checks from existing routes or APIs.

## Testing and acceptance criteria

### Backend contract tests

Extend the owner ERP feature coverage to verify:

- every accessible Workspace card links to its readable module route;
- every valid module route renders the module landing component and active module state;
- module page links use owner ERP routes rather than portal-only URLs;
- Retail no longer resolves to the normal shop-owner Product Management shell;
- disabled, ineligible, unknown, and malformed module routes are denied;
- direct module URLs and refreshes preserve the active module scope;
- owner operation requests use the shop-owner guard and correct tenant;
- cross-shop identifiers cannot change the resolved tenant;
- employee ERP route behavior remains unchanged.

### Frontend component tests

Add or extend tests for:

- Workspace card URLs;
- module landing content and page links;
- active-module filtering in the owner ERP sidebar;
- Retail showing only Retail pages plus ERP Workspace;
- equivalent Finance, CRM, Inventory, Procurement, Repair, HR, and Logistics scoping;
- the regular shop-owner sidebar not rendering Employee Modules;
- the regular shop-owner sidebar not rendering the standalone Logistics accordion;
- the employee ERP sidebar retaining its existing behavior;
- owner operation controls and state transitions being present only for the selected, authorized module.

### Browser verification

Using an approved company shop owner, verify this sequence:

```text
Shop Owner Dashboard
→ ERP Workspace
→ Retail Operations
→ Retail landing page and Retail-only sidebar
→ open and complete a representative Retail operation
→ return to ERP Workspace
→ Finance
→ Finance landing page and Finance-only sidebar
→ complete a representative Finance operation permitted by the existing owner contract
```

Also verify direct URL refresh, browser back navigation, disabled-module denial, and that the normal shop-owner sidebar contains neither Employee Modules nor the standalone Logistics accordion.

## Likely implementation areas

The implementation plan should inspect and modify only the relevant existing contracts:

- `routes/shop-owner-erp.php`
- `app/Http/Controllers/Erp/WorkspaceController.php` and the module landing controller/action boundary
- `app/Services/ErpRouteCatalog.php` and module configuration/slug metadata where required
- ERP actor/context and shared Inertia module state
- `resources/js/Pages/ERP/Workspace.tsx`
- a new focused `resources/js/Pages/ERP/ModuleLanding.tsx` if no existing landing component can be reused
- `resources/js/layout/AppSidebar_ERP.tsx`
- `resources/js/layout/AppSidebar_shopOwner.tsx`
- existing owner-capable ERP page wrappers and route aliases, especially Retail
- focused backend and frontend tests in the existing BusinessScaling and layout test locations

No database migration, new dependency, visual redesign, or employee navigation rewrite is required by this design.

## Non-goals

- Replacing the existing module-access authorization model.
- Deleting existing portal routes or APIs as part of the sidebar change.
- Giving owners access to employee self-service actions.
- Adding new business modules.
- Redesigning the overall SoleSpace visual system.
- Rewriting unrelated logistics, finance, inventory, or approval workflows.

## Definition of done

- The design is implemented on the business-scaling feature branch.
- The Workspace remains the module picker.
- Each module opens at a readable scoped ERP URL and renders inside the ERP shell.
- The owner ERP sidebar shows only the selected module plus ERP Workspace.
- The regular shop-owner sidebar no longer shows Employee Modules or the standalone Logistics accordion for any shop owner.
- Retail and every other exposed module use owner ERP route links and do not fall back to the portal shell.
- Representative owner operations work with tenant isolation and existing authorization intact.
- Disabled, ineligible, unknown, and cross-shop access is denied safely.
- Employee ERP navigation and behavior remain unchanged.
- Focused backend/frontend tests and browser verification pass.
- `git diff --check` and the relevant project quality gates pass.
