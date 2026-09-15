# Procurement Dashboard Implementation Plan

> For agentic workers: REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox syntax for tracking.

**Goal:** Add a tenant-scoped procurement dashboard for employee accounts and upgrade the canonical Shop Owner procurement dashboard with shared six-month analytics, workflow summaries, and recent activity.

**Architecture:** A new ProcurementDashboardService::forShopOwner(int $shopOwnerId) will build one read-only view model from the existing purchase-request and purchase-order models. The employee route /erp/procurement/dashboard and the canonical owner route /shop-owner/oversee/procurement will render the existing ERP/Procurement/Dashboard Inertia component with actor-specific links and the same service data. No mutation workflow, schema, or new API namespace will be introduced.

**Tech Stack:** Laravel 12, PHP 8.2, Eloquent, Inertia 2, React 18, TypeScript 5.7, Tailwind CSS 4, Lucide icons, ApexCharts/react-apexcharts, PHPUnit, Vitest, Playwright browser verification.

## Global Constraints

- Use the existing DESIGN.md visual language: neutral black/white/cloud-gray surfaces, an 8px spacing rhythm, restrained borders, limited shadows, and semantic colors only for state.
- Use the Data-Dense Dashboard pattern selected by ui-ux-pro-max for procurement analytics.
- Use the current tenant owner resolved by the actor-specific route boundary; never accept a client-provided shop_owner_id, shop_id, or actor override.
- Keep /shop-owner/oversee/procurement as the canonical Shop Owner dashboard route and keep its current owner shell and tabs.
- Keep employee procurement routes, permissions, workflows, and existing URLs unchanged; only add the employee dashboard route and navigation item.
- Use the current ApexCharts dependency; do not add a package, migration, polling loop, or new API namespace.
- Follow TDD: every production change must follow a focused failing test and a passing verification.
- Preserve unrelated changes in the parent checkout; all implementation changes stay in the isolated .worktrees/procurement-dashboard worktree.

## File map

- Create app/Services/ProcurementDashboardService.php for the tenant-scoped dashboard read model.
- Create tests/Feature/Procurement/ProcurementDashboardServiceTest.php for counts, status groups, trend buckets, recent activity, and cross-tenant isolation.
- Create tests/Feature/Procurement/ProcurementDashboardRouteTest.php for employee and owner page contracts and permission boundaries.
- Modify app/Http/Controllers/Erp/ReadPageController.php to render the employee dashboard using the service.
- Modify app/Services/OwnerShell/CanonicalOwnerOverviewService.php to delegate only the procurement branch to the service.
- Modify app/Services/OwnerShell/CanonicalOwnerDashboardService.php to add owner-safe procurement quick-link URLs.
- Modify routes/web.php to register erp.procurement.dashboard under the existing auth:user procurement group.
- Modify config/shop_modules.php to classify the new employee route in the procurement bucket.
- Regenerate resources/js/ziggy.js with Laravel's route generator after route registration.
- Modify resources/js/types/procurement.ts with explicit dashboard view-model interfaces.
- Modify resources/js/Pages/ERP/Procurement/Dashboard.tsx with the KPI, trend, status, recent-activity, empty-state, and refresh UI.
- Modify resources/js/Pages/ERP/Procurement/__tests__/Dashboard.test.tsx with the new Inertia payload and UI behavior tests.
- Modify resources/js/layout/AppSidebar_ERP.tsx to add the employee Procurement Dashboard item and active-path fallback.
- Modify resources/js/layout/__tests__/AppSidebar_ERP.test.tsx to lock the new employee navigation link without changing owner or inventory behavior.

---

### Task 1: Lock the procurement dashboard read model with failing backend tests

**Files:**

- Create: tests/Feature/Procurement/ProcurementDashboardServiceTest.php

**Interfaces:**

- Produces the expected contract for App\Services\ProcurementDashboardService::forShopOwner(int $shopOwnerId): array.
- Uses PurchaseRequestFactory, PurchaseOrderFactory, ShopOwnerFactory, and SupplierFactory; no production code is changed in the red phase.

- [x] Step 1: Write the failing service contract test

Create a RefreshDatabase test class that freezes time to 2026-09-02 12:00:00 and creates records for two shop owners. Assert that the selected tenant's data produces four summary values, all six calendar buckets, every supported status row, and only the five newest records in recent activity.

The core test data and assertions must include this behavior:

~~~php
$this->travelTo(now()->setDate(2026, 9, 2)->setTime(12, 0));

$shop = ShopOwner::factory()->create();
$foreignShop = ShopOwner::factory()->create();
$supplier = Supplier::factory()->create(['shop_owner_id' => $shop->id]);

PurchaseRequest::factory()->create([
    'shop_owner_id' => $shop->id,
    'status' => 'pending_finance',
    'total_cost' => 1250,
    'created_at' => now()->subMonths(2)->setDay(5),
]);
PurchaseRequest::factory()->create([
    'shop_owner_id' => $shop->id,
    'status' => 'approved',
    'total_cost' => 900,
    'created_at' => now()->subMonth()->setDay(8),
]);
PurchaseRequest::factory()->create([
    'shop_owner_id' => $foreignShop->id,
    'status' => 'pending_finance',
    'created_at' => now()->subMonth()->setDay(10),
]);

PurchaseOrder::factory()->create([
    'shop_owner_id' => $shop->id,
    'supplier_id' => $supplier->id,
    'status' => 'in_transit',
    'total_cost' => 2400,
    'created_at' => now()->subMonth()->setDay(12),
]);
PurchaseOrder::factory()->create([
    'shop_owner_id' => $shop->id,
    'supplier_id' => $supplier->id,
    'status' => 'cancelled',
    'total_cost' => 500,
    'created_at' => now()->subMonths(3)->setDay(14),
]);
PurchaseOrder::factory()->create([
    'shop_owner_id' => $foreignShop->id,
    'supplier_id' => Supplier::factory()->create(['shop_owner_id' => $foreignShop->id])->id,
    'status' => 'in_transit',
    'total_cost' => 9900,
]);

$dashboard = app(ProcurementDashboardService::class)->forShopOwner($shop->id);

$this->assertSame(2, $dashboard['summary']['purchase_requests']);
$this->assertSame(1, $dashboard['summary']['awaiting_review']);
$this->assertSame(2, $dashboard['summary']['purchase_orders']);
$this->assertSame('2400.00', (string) $dashboard['summary']['open_order_value']);
$this->assertSame('Last 6 months', $dashboard['trend']['period_label']);
$this->assertCount(6, $dashboard['trend']['months']);
$this->assertSame(1, $dashboard['trend']['months'][3]['purchase_requests']);
$this->assertSame(1, $dashboard['trend']['months'][4]['purchase_orders']);
$this->assertSame(1, collect($dashboard['request_statuses'])->firstWhere('key', 'pending_finance')['count']);
$this->assertSame(1, collect($dashboard['order_statuses'])->firstWhere('key', 'in_transit')['count']);
$this->assertCount(4, $dashboard['recent_activity']);
$this->assertTrue(collect($dashboard['recent_activity'])->every(
    static fn (array $record): bool => $record['url'] === null
));
~~~

Add a second test for an empty shop that asserts summary values are zero, trend.months contains six zero-valued entries, and every status count is zero.

- [x] Step 2: Run the focused test and verify the expected red failure

Run:

~~~powershell
php artisan test tests/Feature/Procurement/ProcurementDashboardServiceTest.php
~~~

Expected result: FAIL because App\Services\ProcurementDashboardService does not exist. If the test errors for a fixture or assertion typo instead, correct the test and rerun until the failure is caused by the missing service.

- [x] Step 3: Commit the red contract test

~~~powershell
git add tests/Feature/Procurement/ProcurementDashboardServiceTest.php
git commit -m "test: define procurement dashboard read model"
~~~

### Task 2: Implement the shared tenant-scoped backend read model

**Files:**

- Create: app/Services/ProcurementDashboardService.php
- Test: tests/Feature/Procurement/ProcurementDashboardServiceTest.php

**Interfaces:**

- Consumes PurchaseRequest::byShopOwner(), PurchaseOrder::byShopOwner(), PurchaseOrder::active(), and existing status accessors/scopes.
- Produces forShopOwner(int $shopOwnerId): array with summary, trend, request_statuses, order_statuses, recent_activity, and refreshed_at.

- [x] Step 1: Implement the smallest service that satisfies the red tests

Implement ProcurementDashboardService as a final service with these fixed status maps:

~~~php
private const REQUEST_STATUSES = [
    'draft' => 'Draft',
    'pending_finance' => 'Pending Finance',
    'pending_shop_owner' => 'Pending Shop Owner',
    'pending_finance_final' => 'Pending Finance Final',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
];

private const ORDER_STATUSES = [
    'draft' => 'Draft',
    'sent' => 'Sent',
    'confirmed' => 'Confirmed',
    'in_transit' => 'In Transit',
    'partially_received' => 'Partially Received',
    'delivered' => 'Delivered',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
];

private const ACTIVE_ORDER_STATUSES = [
    'sent', 'confirmed', 'in_transit', 'partially_received',
];
~~~

Use grouped status queries filtered by shop_owner_id, a separate active-order sum filtered by the same tenant and ACTIVE_ORDER_STATUSES, and two bounded date queries for the six monthly buckets. Build month buckets in PHP with now()->startOfMonth()->subMonths(5) through now()->endOfMonth() so SQLite and MariaDB use the same behavior. Fetch at most five recent requests and five recent orders, merge by created_at, sort descending, and take five. Select only the fields needed for the payload: reference number, product name, status, total cost, and created_at.

Return url => null for recent rows in this first read model; the page's actor-specific quick links will be supplied by the route renderers, so a row can never point an employee at an owner route or an owner at an employee route.

- [x] Step 2: Run the service tests and verify green

Run:

~~~powershell
php artisan test tests/Feature/Procurement/ProcurementDashboardServiceTest.php
~~~

Expected result: all service tests pass. Confirm the foreign shop's 9900 order and foreign pending request do not affect any selected-shop value.

- [x] Step 3: Commit the shared read model

~~~powershell
git add app/Services/ProcurementDashboardService.php tests/Feature/Procurement/ProcurementDashboardServiceTest.php
git commit -m "feat: add procurement dashboard read model"
~~~

### Task 3: Lock employee and owner route contracts with failing tests

**Files:**

- Create: tests/Feature/Procurement/ProcurementDashboardRouteTest.php

**Interfaces:**

- Employee route: GET /erp/procurement/dashboard, named erp.procurement.dashboard.
- Owner route: GET /shop-owner/oversee/procurement, named shop-owner.shell.oversee.procurement.
- Both render ERP/Procurement/Dashboard and expose the rich dashboard payload.

- [x] Step 1: Write the failing employee route test

Create a RefreshDatabase test that creates a shop owner and user, creates the access-procurement-dashboard permission, grants it to the user, and asserts:

~~~php
$this->actingAs($user, 'user')
    ->get('/erp/procurement/dashboard')
    ->assertOk()
    ->assertInertia(fn (Assert $page) => $page
        ->component('ERP/Procurement/Dashboard', false)
        ->has('dashboard.summary.purchase_requests')
        ->has('dashboard.trend.months', 6)
        ->where('dashboard.links.purchase_requests', url('/erp/procurement/purchase-request'))
        ->where('dashboard.links.purchase_orders', url('/erp/procurement/purchase-orders'))
    );
~~~

Add a second test that uses a user with no procurement permission and asserts GET /erp/procurement/dashboard returns 403.

- [x] Step 2: Write the failing canonical owner test

Create an approved company Shop Owner, enable its procurement ShopOwnerModule, create one purchase request, and assert:

~~~php
$this->actingAs($owner, 'shop_owner')
    ->get('/shop-owner/oversee/procurement')
    ->assertOk()
    ->assertInertia(fn (Assert $page) => $page
        ->component('ERP/Procurement/Dashboard', false)
        ->where('activeModule.key', 'procurement')
        ->where('navigationMode', 'module')
        ->where('dashboard.summary.purchase_requests', 1)
        ->where('dashboard.links.purchase_requests', url('/shop-owner/erp/procurement/purchase-request'))
        ->where('dashboard.links.purchase_orders', url('/shop-owner/erp/procurement/purchase-orders'))
    );
~~~

- [x] Step 3: Run the focused route tests and verify the expected red failure

Run:

~~~powershell
php artisan test tests/Feature/Procurement/ProcurementDashboardRouteTest.php
~~~

Expected result: FAIL because the employee dashboard route and rich owner payload are not implemented yet.

- [x] Step 4: Commit the red route contract tests

~~~powershell
git add tests/Feature/Procurement/ProcurementDashboardRouteTest.php
git commit -m "test: define procurement dashboard route contracts"
~~~

### Task 4: Wire the backend routes and owner-safe renderers

**Files:**

- Modify: app/Http/Controllers/Erp/ReadPageController.php
- Modify: app/Services/OwnerShell/CanonicalOwnerOverviewService.php
- Modify: app/Services/OwnerShell/CanonicalOwnerDashboardService.php
- Modify: routes/web.php
- Modify: config/shop_modules.php
- Regenerate: resources/js/ziggy.js
- Test: tests/Feature/Procurement/ProcurementDashboardRouteTest.php

**Interfaces:**

- ReadPageController::procurementDashboard(): Response|RedirectResponse calls the existing employee password redirect, resolves the existing employee tenant ID, and passes it to ProcurementDashboardService.
- CanonicalOwnerOverviewService::forModule('procurement', $shopOwnerId) returns the service's rich dashboard view model while all other module branches remain unchanged.
- CanonicalOwnerDashboardService adds owner-only quick links without changing the canonical component or route.

- [x] Step 1: Add the employee controller method and route

Inject ProcurementDashboardService into ReadPageController alongside the existing inventory read service. Add this method before the existing procurement list methods:

~~~php
public function procurementDashboard(): Response|RedirectResponse
{
    if ($redirect = $this->employeePasswordRedirect()) {
        return $redirect;
    }

    $dashboard = $this->procurementDashboard->forShopOwner($this->shopOwnerId());
    $dashboard['links'] = [
        'purchase_requests' => route('erp.procurement.purchase-request'),
        'purchase_orders' => route('erp.procurement.purchase-orders'),
    ];

    return Inertia::render('ERP/Procurement/Dashboard', compact('dashboard'));
}
~~~

Register the route before the existing procurement page routes:

~~~php
Route::get('/dashboard', [ReadPageController::class, 'procurementDashboard'])
    ->middleware('permission:view-procurement|access-procurement-dashboard')
    ->name('dashboard');
~~~

- [x] Step 2: Delegate only the owner procurement overview branch

Inject ProcurementDashboardService into CanonicalOwnerOverviewService and replace only the current procurement match arm with:

~~~php
'procurement' => $this->procurementDashboard->forShopOwner($shopOwnerId),
~~~

Leave every other module card branch and the inventory controller dependency unchanged.

In CanonicalOwnerDashboardService, assign the procurement dashboard before rendering and add owner-safe list URLs:

~~~php
$dashboard = $this->overview->forModule($moduleKey, $shopOwnerId);
$dashboard['links'] = [
    'purchase_requests' => route('shop-owner.erp.procurement.purchase-request'),
    'purchase_orders' => route('shop-owner.erp.procurement.purchase-orders'),
];

$response = Inertia::render('ERP/Procurement/Dashboard', compact('dashboard'));
~~~

Keep the existing with([...]) owner shell props and canonical route unchanged.

- [x] Step 3: Register the route in the catalog and regenerate Ziggy

Add erp.procurement.dashboard to the existing config/shop_modules.php procurement route bucket. Do not add an owner page group entry because the canonical owner dashboard is the existing shop-owner.shell.oversee.procurement route and the owner tabs already provide its Dashboard entry.

Regenerate the tracked route map through Laravel:

~~~powershell
php artisan ziggy:generate resources/js/ziggy.js
~~~

Do not hand-edit the generated one-line route map.

- [x] Step 4: Run route tests and existing procurement authorization tests

Run:

~~~powershell
php artisan test tests/Feature/Procurement/ProcurementDashboardRouteTest.php tests/Feature/Procurement/ProcurementAuthorizationTest.php
~~~

Expected result: the new route tests and the existing 19 procurement authorization tests pass. Confirm users with only access-procurement-dashboard can open the read-only dashboard but cannot approve purchase requests or stock requests.

- [x] Step 5: Commit the backend route integration

~~~powershell
git add app/Http/Controllers/Erp/ReadPageController.php app/Services/OwnerShell/CanonicalOwnerOverviewService.php app/Services/OwnerShell/CanonicalOwnerDashboardService.php routes/web.php config/shop_modules.php resources/js/ziggy.js
git commit -m "feat: wire procurement dashboard routes"
~~~

### Task 5: Define the typed frontend payload and write failing dashboard UI tests

**Files:**

- Modify: resources/js/types/procurement.ts
- Modify: resources/js/Pages/ERP/Procurement/__tests__/Dashboard.test.tsx

**Interfaces:**

- ProcurementDashboard is the TypeScript representation of the backend payload.
- Dashboard.tsx consumes dashboard?: ProcurementDashboard and treats missing optional arrays as empty arrays.

- [x] Step 1: Add explicit dashboard types

Add these exported interfaces to resources/js/types/procurement.ts without changing existing procurement record interfaces:

~~~ts
export interface ProcurementDashboardSummary {
    purchase_requests: number;
    awaiting_review: number;
    purchase_orders: number;
    open_order_value: number | string;
}

export interface ProcurementDashboardMonth {
    label: string;
    start: string;
    end: string;
    purchase_requests: number;
    purchase_orders: number;
}

export interface ProcurementDashboardStatus {
    key: string;
    label: string;
    count: number;
}

export interface ProcurementDashboardActivity {
    type: 'Purchase request' | 'Purchase order';
    reference: string;
    description: string;
    status: string;
    amount: number | string;
    occurred_at: string;
    url: string | null;
}

export interface ProcurementDashboard {
    title: string;
    description: string;
    summary: ProcurementDashboardSummary;
    trend: { period_label: string; months: ProcurementDashboardMonth[] };
    request_statuses: ProcurementDashboardStatus[];
    order_statuses: ProcurementDashboardStatus[];
    recent_activity: ProcurementDashboardActivity[];
    refreshed_at: string;
    links?: { purchase_requests?: string; purchase_orders?: string };
}
~~~

- [x] Step 2: Replace the old two-card test payload with the rich Inertia payload

Mock router.reload and react-apexcharts, then supply four summary values, six trend months, status rows, recent activity, and employee links. Add assertions for:

~~~tsx
expect(screen.getByText('Awaiting review')).toBeInTheDocument();
expect(screen.getByText('Open order value')).toBeInTheDocument();
expect(screen.getByTestId('procurement-activity-chart')).toBeInTheDocument();
expect(screen.getByRole('heading', { name: 'Purchase request status' })).toBeInTheDocument();
expect(screen.getByRole('heading', { name: 'Purchase order status' })).toBeInTheDocument();
expect(screen.getByRole('heading', { name: 'Recent activity' })).toBeInTheDocument();
expect(screen.getByText('PR-000001')).toBeInTheDocument();
expect(screen.getByRole('link', { name: /view purchase requests/i })).toHaveAttribute(
    'href',
    '/erp/procurement/purchase-request',
);
~~~

Add a test that clicks the Refresh data button and expects router.reload to receive { only: ['dashboard'], preserveScroll: true }. Add an empty payload test asserting the page still renders six zeroed trend points and the empty activity state without throwing.

- [x] Step 3: Run the focused UI test and verify the expected red failure

Run:

~~~powershell
C:/programmers/xampp/files/htdocs/solespace-master/node_modules/.bin/vitest.cmd run resources/js/Pages/ERP/Procurement/__tests__/Dashboard.test.tsx --reporter=dot
~~~

Expected result: FAIL because the current page does not render the new KPI, chart, status, activity, and refresh elements.

- [x] Step 4: Commit the red frontend contract test and types

~~~powershell
git add resources/js/types/procurement.ts resources/js/Pages/ERP/Procurement/__tests__/Dashboard.test.tsx
git commit -m "test: define procurement dashboard ui contract"
~~~

### Task 6: Implement the procurement dashboard UI

**Files:**

- Modify: resources/js/Pages/ERP/Procurement/Dashboard.tsx
- Test: resources/js/Pages/ERP/Procurement/__tests__/Dashboard.test.tsx

**Interfaces:**

- Consumes typed ProcurementDashboard props and Inertia router.
- Produces the same component for employee and canonical owner routes; links are read from dashboard.links and never reconstructed from a tenant ID.

- [x] Step 1: Implement safe formatting and chart data helpers

Use formatNumber with Intl.NumberFormat, formatMoney with Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }), and default empty arrays. Build chart series from trend.months:

~~~tsx
const chartSeries = [
    { name: 'Purchase requests', data: months.map((month) => month.purchase_requests) },
    { name: 'Purchase orders', data: months.map((month) => month.purchase_orders) },
];
~~~

Configure ApexCharts as a responsive area chart with no toolbar/zoom, visible legend, two neutral accent colors, category labels from month labels, count tooltips, and reduced-motion-safe CSS around the chart. Render a visually hidden or screen-reader-only summary containing the six labels and series values.

- [x] Step 2: Implement the page sections from the approved design

Keep the existing AppLayoutERP and page title. Render:

- header with ERP module, Procurement Dashboard, operational snapshot, and a labeled Refresh data button;
- four KPI cards with stable data-testid="procurement-summary-card" markers;
- full-width Procurement activity panel with data-testid="procurement-activity-chart" around the chart;
- two status panels with aria-labelledby and proportional bars whose numeric counts remain visible;
- Recent activity table/list with non-clickable rows when url is null;
- empty state with View purchase requests and View purchase orders links from dashboard.links when available.

Use static Tailwind class maps for status bar colors so all classes are discoverable at build time. Use semantic HTML (section, headings, table or list roles), visible focus rings, no emoji, no icon-only unlabelled controls, and dark-mode variants consistent with DESIGN.md. Use motion-reduce utilities for hover/chart wrappers.

- [x] Step 3: Verify the focused frontend tests pass

Run:

~~~powershell
C:/programmers/xampp/files/htdocs/solespace-master/node_modules/.bin/vitest.cmd run resources/js/Pages/ERP/Procurement/__tests__/Dashboard.test.tsx --reporter=dot
~~~

Expected result: all procurement dashboard UI tests pass with no test errors. If an ApexCharts import fails in the test environment, keep the test-only react-apexcharts mock and do not add a runtime dependency.

- [x] Step 4: Commit the dashboard UI

~~~powershell
git add resources/js/Pages/ERP/Procurement/Dashboard.tsx resources/js/Pages/ERP/Procurement/__tests__/Dashboard.test.tsx resources/js/types/procurement.ts
git commit -m "feat: build procurement dashboard analytics ui"
~~~

### Task 7: Add employee sidebar navigation with a failing regression test

**Files:**

- Modify: resources/js/layout/__tests__/AppSidebar_ERP.test.tsx
- Modify: resources/js/layout/AppSidebar_ERP.tsx

**Interfaces:**

- Employee Procurement section receives a first item named Dashboard with route erp.procurement.dashboard and fallback path /erp/procurement/dashboard.
- Existing owner module filtering, Inventory supplier-order placement, procurement permission gates, and attendance/payslip behavior remain unchanged.

- [x] Step 1: Add the failing sidebar regression test

Set the existing sidebar test state to a PROCUREMENT MANAGER with view-procurement, render AppSidebarERP, and assert:

~~~tsx
const dashboardLink = document.querySelector('a[href="/erp/procurement/dashboard"]');
expect(dashboardLink).toBeInTheDocument();
expect(dashboardLink).toHaveTextContent('Dashboard');
expect(document.querySelector('a[href="/erp/procurement/purchase-request"]')).toBeInTheDocument();
expect(document.querySelector('a[href="/erp/procurement/purchase-orders"]')).toBeInTheDocument();
~~~

Also assert that the existing Inventory Manager test still does not render Purchase Requests, Purchase Orders, or Suppliers Management.

- [x] Step 2: Run the focused sidebar tests and verify the expected red failure

Run:

~~~powershell
C:/programmers/xampp/files/htdocs/solespace-master/node_modules/.bin/vitest.cmd run resources/js/layout/__tests__/AppSidebar_ERP.test.tsx --reporter=dot
~~~

Expected result: the new dashboard-link assertion fails because the route is not in procurementItems or the fallback path map.

- [x] Step 3: Add the sidebar item and active-path fallback

Add a simple dashboard SVG item before Purchase Requests:

~~~tsx
{
  icon: (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
      <rect x="3" y="3" width="7" height="7" rx="1" />
      <rect x="14" y="3" width="7" height="7" rx="1" />
      <rect x="3" y="14" width="7" height="7" rx="1" />
      <rect x="14" y="14" width="7" height="7" rx="1" />
    </svg>
  ),
  name: "Dashboard",
  route: "erp.procurement.dashboard",
  moduleKey: "procurement",
},
~~~

Add the same route to allRoutePaths as:

~~~tsx
"erp.procurement.dashboard": "/erp/procurement/dashboard",
~~~

Keep hasProcurementAccess() unchanged because it already includes access-procurement-dashboard, individual procurement page permissions, view-procurement, and the Procurement Manager role.

- [x] Step 4: Verify sidebar tests pass

Run the focused sidebar command again. Expected result: all existing sidebar tests plus the new dashboard test pass.

- [x] Step 5: Commit the employee navigation change

~~~powershell
git add resources/js/layout/AppSidebar_ERP.tsx resources/js/layout/__tests__/AppSidebar_ERP.test.tsx
git commit -m "feat: add procurement dashboard navigation"
~~~

### Task 8: Review owner regression coverage and run the full quality gates

**Files:**

- Review: tests/Feature/BusinessScaling/OwnerErpPageContractTest.php
- Review: tests/Feature/ShopOwner/CanonicalShell/CanonicalOwnerRouteContractTest.php
- Review: tests/Feature/ShopOwner/CanonicalShell/CanonicalOwnerRouteParityTest.php
- Review: tests/Feature/ShopOwner/CanonicalShell/CanonicalOwnerCapabilityParityTest.php
- Review: all changed files from git diff.

- [x] Step 1: Run focused backend and frontend suites

~~~powershell
php artisan test tests/Feature/Procurement/ProcurementDashboardServiceTest.php tests/Feature/Procurement/ProcurementDashboardRouteTest.php tests/Feature/Procurement/ProcurementAuthorizationTest.php tests/Feature/BusinessScaling/OwnerErpPageContractTest.php
C:/programmers/xampp/files/htdocs/solespace-master/node_modules/.bin/vitest.cmd run resources/js/Pages/ERP/Procurement/__tests__/Dashboard.test.tsx resources/js/layout/__tests__/AppSidebar_ERP.test.tsx --reporter=dot
~~~

Expected result: all focused backend and frontend tests pass; the existing owner page arrays remain unchanged because the owner dashboard is canonical and not an owner page-group item.

- [x] Step 2: Run the full frontend suite and production build

~~~powershell
C:/programmers/xampp/files/htdocs/solespace-master/node_modules/.bin/vitest.cmd run --reporter=dot
C:/programmers/xampp/files/htdocs/solespace-master/node_modules/.bin/vite.cmd build
~~~

Expected result: the full Vitest suite passes and Vite produces a successful build. pnpm.cmd run test:frontend and pnpm.cmd run build may be retried if the package manager becomes responsive; report the direct-binary commands as the actual evidence when pnpm remains blocked.

- [x] Step 3: Run Laravel procurement and BusinessScaling suites

~~~powershell
php artisan test tests/Feature/Procurement tests/Feature/BusinessScaling
~~~

Expected result: all relevant Laravel tests pass. Do not run destructive database commands; PHPUnit's in-memory SQLite configuration provides test isolation.

- [x] Step 4: Perform sequential review gates

Review the diff in this order:

1. Simplify: remove duplicate queries, unused imports, stale old-card types, and any unnecessary abstraction; do not introduce a second metrics API.
2. Standards/spec: confirm every design-spec section has an implementation or test, especially the six-month period, empty state, owner route preservation, and actor-specific links.
3. TypeScript/React: confirm typed props, no new any, stable chart data, no unnecessary effects, and no direct client authorization logic.
4. Security/Laravel: confirm all backend aggregation queries are tenant-filtered, the employee route retains auth:user plus permission middleware, and owner data comes from canonical actor context.
5. Reuse/dead code: confirm existing layout, route helpers, model scopes, chart dependency, and owner shell are reused; remove only orphans created by this change.

- [x] Step 5: Run final hygiene and inspect generated changes

~~~powershell
git diff --check
git status --short
git diff --stat HEAD~6..HEAD
~~~

Inspect resources/js/ziggy.js and any public/build output as generated artifacts. Do not hand-edit generated build files. Confirm the parent checkout's unrelated changes were never included in the feature branch.

- [x] Step 6: Verify the browser-visible employee and owner flows

With the local Laravel/Vite application running, use Playwright/browser verification to confirm:

- an employee with procurement access can open Procurement > Dashboard;
- the page shows four KPI cards, the six-month graph, both status panels, and recent activity or the empty state;
- refresh reloads the dashboard data without changing the current route;
- an approved Shop Owner can open /shop-owner/oversee/procurement and sees the same dashboard sections inside the owner shell;
- the owner tabs still show Dashboard, Purchase Requests, Purchase Orders, and Suppliers;
- an Inventory Manager still sees Supplier Orders under Inventory and cannot see Procurement pages without procurement access;
- no horizontal scrolling occurs at 375px, 768px, 1024px, or 1440px widths.

- [x] Step 7: Commit only after all checks pass

~~~powershell
git add app config routes resources/js tests
git commit -m "feat: add procurement dashboards"
~~~

Report exact test counts, build output, browser evidence, unmeasured performance/bundle baseline, and the final feature commit. Do not claim TypeScript lint or type-check success because no repository scripts/configuration currently provide those checks.
