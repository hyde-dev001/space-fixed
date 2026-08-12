<?php

declare(strict_types=1);

/*
 * Shop module catalog and route-classification contract.
 *
 * The route names below are the checked-in inventory of all named internal
 * owner/employee routes loaded by bootstrap/app.php. Unnamed internal routes
 * are tracked in the architecture inventory and receive unique names before
 * enforcement is enabled. Runtime code never falls back to method/URI matching.
 */

$modules = [
    'retail_operations' => [
        'label' => 'Retail Operations',
        'registration_types' => ['individual', 'company'],
        'business_types' => ['retail', 'both'],
        'default_enabled' => true,
        'backfill_enabled' => true,
        'actor_scope' => ['shop_owner', 'user'],
    ],
    'repair_operations' => [
        'label' => 'Repair Operations',
        'registration_types' => ['individual', 'company'],
        'business_types' => ['repair', 'both'],
        'default_enabled' => true,
        'backfill_enabled' => true,
        'actor_scope' => ['shop_owner', 'user'],
    ],
    'hr_employees' => [
        'label' => 'HR and Employees',
        'registration_types' => ['company'],
        'business_types' => ['retail', 'repair', 'both'],
        'default_enabled' => true,
        'backfill_enabled' => true,
        'actor_scope' => ['shop_owner', 'user'],
    ],
    'finance' => [
        'label' => 'Finance',
        'registration_types' => ['company'],
        'business_types' => ['retail', 'repair', 'both'],
        'default_enabled' => true,
        'backfill_enabled' => true,
        'actor_scope' => ['shop_owner', 'user'],
    ],
    'crm' => [
        'label' => 'CRM',
        'registration_types' => ['company'],
        'business_types' => ['retail', 'repair', 'both'],
        'default_enabled' => true,
        'backfill_enabled' => true,
        'actor_scope' => ['shop_owner', 'user'],
    ],
    'inventory' => [
        'label' => 'Inventory',
        'registration_types' => ['company'],
        'business_types' => ['retail', 'repair', 'both'],
        'default_enabled' => true,
        'backfill_enabled' => true,
        'actor_scope' => ['shop_owner', 'user'],
    ],
    'procurement' => [
        'label' => 'Procurement',
        'registration_types' => ['company'],
        'business_types' => ['retail', 'repair', 'both'],
        'default_enabled' => true,
        'backfill_enabled' => true,
        'actor_scope' => ['shop_owner', 'user'],
    ],
    'logistics' => [
        'label' => 'Logistics',
        'registration_types' => ['company'],
        'business_types' => ['retail', 'repair', 'both'],
        'default_enabled' => true,
        'backfill_enabled' => true,
        'actor_scope' => ['shop_owner', 'user'],
    ],
];

$routeEntry = static function (
    array $modules,
    string $classification,
    ?string $mode,
    array $moduleKeys,
    array $methods,
    string $audience,
    ?string $actorGuard,
    string $action,
    string $ownerDenialReason,
    ?string $navigationGroup,
    bool $selfService,
): array {
    $registrationTypes = [];
    $businessTypes = [];

    foreach ($moduleKeys as $moduleKey) {
        $registrationTypes = array_merge($registrationTypes, $modules[$moduleKey]['registration_types'] ?? []);
        $businessTypes = array_merge($businessTypes, $modules[$moduleKey]['business_types'] ?? []);
    }

    return [
        'methods' => array_values(array_unique(array_map('strtoupper', $methods))),
        'classification' => $classification,
        'audience' => $audience,
        'actor_guard' => $actorGuard,
        'module_keys' => array_values(array_unique($moduleKeys)),
        'mode' => $mode,
        'registration_types' => array_values(array_unique($registrationTypes)),
        'business_types' => array_values(array_unique($businessTypes)),
        'action' => $action,
        'owner_access' => 'denied',
        'owner_denial_reason' => $ownerDenialReason,
        'domain_rule' => null,
        'risk_tier' => 'normal',
        'paired_route' => null,
        'navigation_group' => $navigationGroup,
        'navigation_label' => null,
        'navigation_order' => null,
        'navigation_page_group' => null,
        'navigation_page_group_label' => null,
        'navigation_page_group_order' => null,
        'navigation_visible' => null,
        'self_service' => $selfService,
        'supporting_routes' => [],
        'actor_persistence' => 'not_applicable',
    ];
};

$routeBuckets = [
    'core' => [
        'api.manager.analytics',
        'api.manager.audit-logs',
        'api.manager.dashboard.stats',
        'api.manager.dss-insights',
        'api.manager.reports.download',
        'api.manager.reports.generate',
        'api.manager.reports.index',
        'api.manager.reports.send',
        'api.manager.staff-performance',
        'erp.hr',
        'erp.hr.audit-logs',
        'erp.logistics.settings',
        'erp.manager.audit-logs',
        'erp.manager.dashboard',
        'erp.manager.dss-insights',
        'erp.manager.reports',
        'erp.manager.user-management',
        'erp.notifications.destroy',
        'erp.notifications.index',
        'erp.notifications.mark-all-read',
        'erp.notifications.mark-read',
        'erp.notifications.page',
        'erp.notifications.preferences',
        'erp.notifications.recent',
        'erp.notifications.settings',
        'erp.notifications.stats',
        'erp.notifications.unread-count',
        'erp.notifications.update-preferences',
        'erp.password.update',
        'erp.profile',
        'erp.staff.',
        'erp.staff.dashboard',
        'erp.staff.pricing-services',
        'erp.time-in',
        'finance.dashboard',
        'hr.dashboard',
        'hr.employees.reset_password',
        'hr.notifications.clear_read',
        'hr.notifications.destroy',
        'hr.notifications.index',
        'hr.notifications.mark_all_as_read',
        'hr.notifications.mark_as_read',
        'hr.notifications.preferences',
        'hr.notifications.recent',
        'hr.notifications.stats',
        'hr.notifications.unread_count',
        'hr.notifications.update_preferences',
        'inventory.dashboard',
        'procurement.settings.show',
        'procurement.settings.update',
        'shop-owner.audit-logs',
        'shop-owner.dashboard',
        'shop-owner.dss-insights',
        'shop-owner.documents.show',
        'shop-owner.compliance-documents.renewals.store',
        'shop-owner.email-verification.send-code',
        'shop-owner.email-verification.verify-code',
        'shop-owner.history-rejection',
        'shop-owner.login',
        'shop-owner.login.form',
        'shop-owner.logistics.dashboard',
        'shop-owner.logout',
        'shop-owner.me',
        'shop-owner.notifications.index',
        'shop-owner.notifications.settings',
        'shop-owner.password.setup',
        'shop-owner.password.setup.store',
        'shop-owner.pending-approval',
        'shop-owner.pending-approval.public',
        'shop-owner.permissions.available',
        'shop-owner.premium-benefits',
        'shop-owner.premium-benefits-legacy',
        'shop-owner.premium-cancel',
        'shop-owner.premium-success',
        'shop-owner.register',
        'shop-owner.resubmission.form',
        'shop-owner.resubmission.document',
        'shop-owner.resubmission.submit',
        'shop-owner.roles.allowed',
        'shop-owner.roles.available',
        'shop-owner.settings',
        'shop-owner.upgrade-requests.store',
        'shop-owner.modules.update',
        'shop-owner.settings.geofence',
        'shop-owner.settings.paymongo-key',
        'shop-owner.settings.paymongo-key.remove',
        'shop-owner.settings.policies',
        'shop-owner.settings.policies.draft',
        'shop-owner.settings.policies.publish',
        'shop-owner.settings.update',
        'shop-owner.shop-profile',
        'shop-owner.shop-profile.password.update',
        'shop-owner.shop-profile.update',
        'shop-owner.two-factor.challenge',
        'shop-owner.two-factor.resend',
        'shop-owner.two-factor.verify',
        'shop_owner.audit.export',
        'shop_owner.audit.index',
        'shop_owner.audit.stats',
        'shop_owner.notifications.destroy',
        'shop_owner.notifications.index',
        'shop_owner.notifications.mark-all-read',
        'shop_owner.notifications.mark-read',
        'shop_owner.notifications.preferences',
        'shop_owner.notifications.recent',
        'shop_owner.notifications.stats',
        'shop_owner.notifications.unarchive',
        'shop_owner.notifications.unread-count',
        'shop_owner.notifications.update-preferences',
        'shop_owner.premium.auto-renew',
        'shop_owner.premium.cancel',
        'shop_owner.premium.checkout',
        'shop_owner.premium.downgrade.schedule',
        'shop_owner.premium.plans',
        'shop_owner.premium.subscription',
        'shop_owner.premium.upgrade.confirm',
        'shop_owner.premium.upgrade.preview',
    ],
    'retail_operations' => [
        'api.manager.products',
        'erp.cashier.point-of-sale',
        'erp.manager.products',
        'erp.staff.job-orders',
        'erp.staff.products',
        'shop-owner.job-orders-retail',
        'shop-owner.point-of-sale',
        'shop-owner.point-of-sale.legacy',
        'shop-owner.position-templates.index',
        'shop-owner.product-uploder',
        'shop-owner.products',
        'shop-owner.vouchers-discount',
        'shopOwner.ecommerce',
        'shop_owner.orders.activate-pickup',
        'shop_owner.orders.arrange-return-pickup',
        'shop_owner.orders.confirm-return-received',
        'shop_owner.orders.index',
        'shop_owner.orders.show',
        'shop_owner.orders.update-status',
        'shop_owner.price-changes.all',
        'shop_owner.price-changes.approve',
        'shop_owner.price-changes.pending',
        'shop_owner.price-changes.reject',
        'shop_owner.products.color-variants.destroy',
        'shop_owner.products.color-variants.images.destroy',
        'shop_owner.products.color-variants.images.reorder',
        'shop_owner.products.color-variants.images.store',
        'shop_owner.products.color-variants.images.update',
        'shop_owner.products.color-variants.index',
        'shop_owner.products.color-variants.store',
        'shop_owner.products.color-variants.update',
        'shop_owner.products.destroy',
        'shop_owner.products.index',
        'shop_owner.products.restore',
        'shop_owner.products.show',
        'shop_owner.products.showroom-entitlement',
        'shop_owner.products.store',
        'shop_owner.products.update',
        'shop_owner.products.upload-image',
        'shop_owner.products.variants',
        'shop_owner.promos.destroy',
        'shop_owner.promos.index',
        'shop_owner.promos.products',
        'shop_owner.promos.store',
        'shop_owner.promos.update',
        'shop_owner.promos.update-status',
    ],
    'repair_operations' => [
        'erp.staff.api.repair-dashboard',
        'erp.manager.repair-rejection-review',
        'erp.manager.shoe-pricing',
        'erp.repairer.point-of-sale',
        'erp.repairer.pricing-services',
        'erp.repairer.support',
        'erp.repairer.warranty-queue',
        'erp.staff.job-orders-repair',
        'erp.staff.repair-dashboard',
        'erp.staff.repair-status',
        'erp.staff.shoe-pricing',
        'erp.staff.upload-services',
        'erp.staff.warranty-queue',
        'erp.user.repair-reject-approval',
        'finance.repair-price-changes.approve',
        'finance.repair-price-changes.approve-final',
        'finance.repair-price-changes.index',
        'finance.repair-price-changes.reject',
        'shop-owner.job-orders-repair',
        'shop-owner.repair-reject-approval',
        'shop-owner.repair-support',
        'shop-owner.upload-services',
        'shop-owner.warranty-queue',
        'shop_owner.customers.repairs',
        'shop_owner.repair-materials.index',
        'shop_owner.repair-price-changes.all',
        'shop_owner.repair-price-changes.approve',
        'shop_owner.repair-price-changes.pending',
        'shop_owner.repair-price-changes.reject',
        'shop_owner.repair-refunds.approve',
        'shop_owner.repair-refunds.execute',
        'shop_owner.repair-refunds.index',
        'shop_owner.repair-refunds.reject',
        'shop_owner.repair-services.destroy',
        'shop_owner.repair-services.index',
        'shop_owner.repair-services.restore',
        'shop_owner.repair-services.show',
        'shop_owner.repair-services.store',
        'shop_owner.repair-services.update',
        'shop_owner.repairs.accept',
        'shop_owner.repairs.activate-payment',
        'shop_owner.repairs.activate-pickup',
        'shop_owner.repairs.approve-high-value',
        'shop_owner.repairs.high-value-pending',
        'shop_owner.repairs.index',
        'shop_owner.repairs.mark-completed',
        'shop_owner.repairs.mark-paid-in-shop',
        'shop_owner.repairs.mark-ready',
        'shop_owner.repairs.mark-received',
        'shop_owner.repairs.materials.destroy',
        'shop_owner.repairs.materials.index',
        'shop_owner.repairs.materials.store',
        'shop_owner.repairs.reject',
        'shop_owner.repairs.reject-high-value',
        'shop_owner.repairs.resume-work',
        'shop_owner.repairs.ship',
        'shop_owner.repairs.start-work',
    ],
    'hr_employees' => [
        'api.leave.approve',
        'api.leave.cancel',
        'api.leave.index',
        'api.leave.pending',
        'api.leave.reject',
        'api.leave.show',
        'api.leave.statistics',
        'api.leave.store',
        'api.manager.suspension_requests.index',
        'api.manager.suspension_requests.review',
        'api.manager.suspension_requests.show',
        'erp.staff.attendance',
        'hr.attendance.by_employee',
        'hr.attendance.checkin',
        'hr.attendance.checkout',
        'hr.attendance.destroy',
        'hr.attendance.index',
        'hr.attendance.lateness.daily',
        'hr.attendance.lateness.employee',
        'hr.attendance.lateness.stats',
        'hr.attendance.lateness.top',
        'hr.attendance.lateness.trends',
        'hr.attendance.patch',
        'hr.attendance.show',
        'hr.attendance.statistics',
        'hr.attendance.store',
        'hr.attendance.update',
        'hr.audit.critical',
        'hr.audit.employee_activity',
        'hr.audit.entity_history',
        'hr.audit.export',
        'hr.audit.filter_options',
        'hr.audit.index',
        'hr.audit.show',
        'hr.audit.statistics',
        'hr.audit.user_activity',
        'hr.departments.destroy',
        'hr.departments.index',
        'hr.departments.show',
        'hr.departments.statistics',
        'hr.departments.store',
        'hr.departments.update',
        'hr.documents.by_employee',
        'hr.documents.destroy',
        'hr.documents.download',
        'hr.documents.expired',
        'hr.documents.expiring',
        'hr.documents.index',
        'hr.documents.reject',
        'hr.documents.show',
        'hr.documents.statistics',
        'hr.documents.store',
        'hr.documents.types',
        'hr.documents.update',
        'hr.documents.verify',
        'hr.employees.activate',
        'hr.employees.apply-template',
        'hr.employees.destroy',
        'hr.employees.index',
        'hr.employees.permissions.get',
        'hr.employees.permissions.sync',
        'hr.employees.permissions.update',
        'hr.employees.regenerate_invite',
        'hr.employees.resend_invite',
        'hr.employees.roles.sync',
        'hr.employees.send_invitation_email',
        'hr.employees.show',
        'hr.employees.statistics',
        'hr.employees.store',
        'hr.employees.suspend',
        'hr.employees.update',
        'hr.holidays.destroy',
        'hr.holidays.index',
        'hr.holidays.store',
        'hr.holidays.sync_ph',
        'hr.holidays.update',
        'hr.leave.approve',
        'hr.leave.balance',
        'hr.leave.destroy',
        'hr.leave.index',
        'hr.leave.pending',
        'hr.leave.reject',
        'hr.leave.show',
        'hr.leave.store',
        'hr.leave.update',
        'hr.overtime.approve',
        'hr.overtime.assign',
        'hr.overtime.confirm_hours',
        'hr.overtime.index',
        'hr.overtime.reject',
        'hr.payroll.approve',
        'hr.payroll.batch.export',
        'hr.payroll.batch.generate',
        'hr.payroll.batch.preview',
        'hr.payroll.batch.retry',
        'shop_owner.hr.attendance.by_employee',
        'shop_owner.hr.payroll.periods',
        'shop_owner.hr.payroll.index',
        'shop_owner.hr.payroll.store',
        'shop_owner.hr.payroll.calculate_preview',
        'shop_owner.hr.payroll.show',
        'shop_owner.hr.payroll.batch.preview',
        'shop_owner.hr.payroll.batch.generate',
        'shop_owner.hr.payroll.batch.retry',
        'shop_owner.hr.payroll.batch.export',
        'hr.payroll.calculate_preview',
        'hr.payroll.components.add',
        'hr.payroll.components.delete',
        'hr.payroll.components.index',
        'hr.payroll.components.update',
        'hr.payroll.destroy',
        'hr.payroll.export',
        'hr.payroll.index',
        'hr.payroll.periods',
        'hr.payroll.process',
        'hr.payroll.recalculate',
        'hr.payroll.show',
        'hr.payroll.stats',
        'hr.payroll.store',
        'hr.payroll.summary',
        'hr.payroll.thirteenth.reconciliation',
        'hr.payroll.thirteenth.release',
        'hr.payroll.update',
        'hr.permissions.available',
        'hr.position-templates.index',
        'hr.suspension_requests.index',
        'hr.suspension_requests.show',
        'hr.suspension_requests.store',
        'shop-owner.suspend-accounts',
        'shopOwner.suspend-accounts',
        'shopOwner.user-access-control',
        'shop-owner.employees.activate',
        'shop-owner.employees.apply-template',
        'shop-owner.employees.destroy',
        'shop-owner.employees.permissions.get',
        'shop-owner.employees.permissions.sync',
        'shop-owner.employees.permissions.update',
        'shop-owner.employees.roles.sync',
        'shop-owner.employees.store',
        'shop-owner.employees.suspend',
        'shop-owner.employees.update',
        'shop_owner.employees.regenerate_invite',
        'shop_owner.employees.send_invitation_email',
        'shop_owner.suspension_requests.index',
        'shop_owner.suspension_requests.review',
        'shop_owner.suspension_requests.show',
        'staff.attendance.add_early_reason',
        'staff.attendance.add_lateness_reason',
        'staff.attendance.checkin',
        'staff.attendance.checkout',
        'staff.attendance.lunch_end',
        'staff.attendance.lunch_start',
        'staff.attendance.my_lateness_stats',
        'staff.attendance.my_records',
        'staff.attendance.status',
        'staff.leave.cancel',
        'staff.leave.my_requests',
        'staff.leave.request',
        'staff.overtime.cancel',
        'staff.overtime.check_in',
        'staff.overtime.check_out',
        'staff.overtime.my_requests',
        'staff.overtime.request',
        'staff.overtime.today_approved',
        'staff.shop_hours.today',
    ],
    'finance' => [
        'erp.finance.audit-logs',
        'erp.manager.suspend-approval',
        'erp.my-payslips',
        'finance.audit.export',
        'finance.audit.index',
        'finance.audit.show',
        'finance.audit.statistics',
        'finance.create-invoice',
        'finance.dashboard.summary',
        'finance.expenses.approve',
        'finance.expenses.destroy',
        'finance.expenses.index',
        'finance.expenses.receipt.upload',
        'finance.expenses.receipt.download',
        'finance.expenses.receipt.delete',
        'finance.expenses.settlements.index',
        'finance.expenses.settlements.store',
        'finance.expenses.settlements.reverse',
        'finance.expenses.reject',
        'finance.expenses.restore',
        'finance.expenses.show',
        'finance.expenses.store',
        'finance.expenses.update',
        'finance.index',
        'finance.invoices.destroy',
        'finance.invoices.from_job',
        'finance.invoices.index',
        'finance.invoices.mark_paid_compatibility',
        'finance.invoices.mark_sent',
        'finance.invoices.payments.index',
        'finance.invoices.payments.store',
        'finance.invoices.payments.reverse',
        'finance.invoices.post_compatibility',
        'finance.invoices.restore',
        'finance.invoices.send_compatibility',
        'finance.invoices.show',
        'finance.invoices.store',
        'finance.invoices.update',
        'finance.invoices.void',
        'finance.payslip_approval.approve',
        'finance.payslip_approval.batch_approve',
        'finance.payslip_approval.batch_preview',
        'finance.payslip_approval.disburse',
        'finance.payslip_approval.final_approve',
        'finance.payslip_approval.index',
        'finance.payslip_approval.reject',
        'finance.payslip_approval.show',
        'finance.price-changes.approve',
        'finance.price-changes.index',
        'finance.price-changes.reject',
        'finance.refunds.approve',
        'finance.refunds.execute',
        'finance.refunds.index',
        'finance.refunds.reject',
        'finance.tax-rates.index',
        'finance.tax-rates.store',
        'finance.tax-rates.effective',
        'finance.tax-rates.default',
        'finance.tax-rates.calculate',
        'finance.tax-rates.show',
        'finance.tax-rates.update',
        'finance.tax-rates.destroy',
        'hr.salary_changes.apply',
        'hr.salary_changes.approve',
        'hr.salary_changes.cancel',
        'hr.salary_changes.index',
        'hr.salary_changes.reject',
        'hr.salary_changes.show',
        'hr.salary_changes.store',
        'permission-audit-logs.compliance-report',
        'permission-audit-logs.export',
        'permission-audit-logs.index',
        'permission-audit-logs.stats',
        'permission-audit-logs.user-history',
        'shop-owner.expense-approvals',
        'shop-owner.payslip-approvals',
        'shop-owner.price-approvals',
        'shop-owner.refund-approvals',
        'shop-owner.salary-adjustment-approvals',
        'shopOwner.refund-approvals',
        'shop_owner.expenses.approve',
        'shop_owner.expenses.index',
        'shop_owner.expenses.reject',
        'shop_owner.finance.invoices.index',
        'shop_owner.finance.invoices.show',
        'shop_owner.finance.invoices.store',
        'shop_owner.finance.invoices.from_job',
        'shop_owner.finance.invoices.update',
        'shop_owner.finance.invoices.destroy',
        'shop_owner.finance.invoices.restore',
        'shop_owner.finance.invoices.send',
        'shop_owner.finance.invoices.void',
        'shop_owner.finance.invoices.mark_paid',
        'shop_owner.finance.invoices.post',
        'shop_owner.finance.expenses.index',
        'shop_owner.finance.expenses.show',
        'shop_owner.finance.expenses.store',
        'shop_owner.finance.expenses.update',
        'shop_owner.finance.expenses.destroy',
        'shop_owner.finance.expenses.restore',
        'shop_owner.finance.expenses.approve',
        'shop_owner.finance.expenses.reject',
        'shop_owner.finance.expenses.receipt.upload',
        'shop_owner.finance.expenses.receipt.download',
        'shop_owner.finance.expenses.receipt.delete',
        'shop_owner.finance.dashboard.summary',
        'shop_owner.finance.tax-rates.index',
        'shop_owner.payslip_approval.batch_final_approve',
        'shop_owner.payslip_approval.final_approve',
        'shop_owner.payslip_approval.index',
        'shop_owner.payslip_approval.show',
        'shop_owner.refunds.approve',
        'shop_owner.refunds.execute',
        'shop_owner.refunds.index',
        'shop_owner.refunds.reject',
        'shop_owner.salary-changes.approve',
        'shop_owner.salary-changes.index',
        'shop_owner.salary-changes.reject',
        'shop_owner.salary-changes.show',
        'staff.payslips.my',
    ],
    'crm' => [
        'crm.api.customers.index',
        'crm.api.customers.show',
        'crm.api.dashboard-stats',
        'crm.api.reviews.index',
        'erp.staff.api.customers',
        'crm.dashboard',
        'crm.customer-reviews',
        'crm.customer-support',
        'crm.customers',
        'crm.leads',
        'crm.opportunities',
        'erp.staff.customers',
        'permission-audit-logs.requires-review',
        'shop-owner.customer-reviews',
        'shop-owner.customer-support',
        'shop-owner.customers',
        'shop_owner.conversations.activate-payment',
        'shop_owner.conversations.index',
        'shop_owner.conversations.messages.store',
        'shop_owner.conversations.show',
        'shop_owner.conversations.transfer',
        'shop_owner.customers.index',
        'shop_owner.customers.orders',
        'shop_owner.customers.payments',
        'shop_owner.reviews.index',
        'shop_owner.reviews.report',
    ],
    'inventory' => [
        'api.manager.inventory-overview',
        'erp.inventory.inventory-dashboard',
        'erp.inventory.product-inventory',
        'erp.inventory.request-material-approval',
        'erp.inventory.stock-movement',
        'erp.inventory.stock-request',
        'erp.inventory.supplier-order-monitoring',
        'erp.inventory.upload-stocks',
        'erp.manager.inventory-dashboard',
        'erp.manager.inventory-overview',
        'erp.manager.product-inventory',
        'erp.manager.stock-movement',
        'erp.manager.upload-stocks',
        'erp.procurement.stock-request-approval',
        'erp.staff.inventory-overview',
        'erp.staff.request-material',
        'erp.staff.stocks-overview',
        'inventory.dashboard.chart',
        'inventory.dashboard.metrics',
        'inventory.dashboard.show',
        'inventory.items.colors.sizes.store',
        'inventory.items.colors.store',
        'inventory.items.destroy',
        'inventory.items.images.delete',
        'inventory.items.images.thumbnail',
        'inventory.items.images.upload',
        'inventory.items.index',
        'inventory.items.restore',
        'inventory.items.sizes.update',
        'inventory.items.store',
        'inventory.items.update',
        'inventory.monitoring.index',
        'inventory.monitoring.metrics',
        'inventory.monitoring.receive',
        'inventory.monitoring.show',
        'inventory.movements.export',
        'inventory.movements.index',
        'inventory.movements.metrics',
        'inventory.movements.store',
        'inventory.products.bulk-update',
        'inventory.products.index',
        'inventory.products.show',
        'inventory.products.update-quantity',
        'inventory.request-material-approvals.approve',
        'inventory.request-material-approvals.index',
        'inventory.request-material-approvals.metrics',
        'inventory.request-material-approvals.reject',
        'inventory.request-material-approvals.request-details',
        'inventory.request-material-approvals.show',
        'inventory.request-material-approvals.store',
        'inventory.stock-requests.index',
        'inventory.stock-requests.metrics',
        'inventory.stock-requests.show',
        'inventory.stock-requests.store',
        'inventory.supplier-orders.destroy',
        'inventory.supplier-orders.generate-po',
        'inventory.supplier-orders.index',
        'inventory.supplier-orders.receive',
        'inventory.supplier-orders.show',
        'inventory.supplier-orders.status',
        'inventory.supplier-orders.store',
        'inventory.supplier-orders.update',
        'inventory.suppliers.destroy',
        'inventory.suppliers.index',
        'inventory.suppliers.show',
        'inventory.suppliers.store',
        'inventory.suppliers.update',
        'procurement.stock-requests.approve',
        'procurement.stock-requests.index',
        'procurement.stock-requests.metrics',
        'procurement.stock-requests.reject',
        'procurement.stock-requests.request-details',
        'procurement.stock-requests.show',
        'procurement.stock-requests.store',
        'shop-owner.inventory-overview',
        'shop-owner.upload-stock-materials',
        'shop_owner.inventory.items.destroy',
        'shop_owner.inventory.items.images.delete',
        'shop_owner.inventory.items.images.thumbnail',
        'shop_owner.inventory.items.images.upload',
        'shop_owner.inventory.items.index',
        'shop_owner.inventory.items.restore',
        'shop_owner.inventory.items.store',
        'shop_owner.inventory.items.update',
        'shop_owner.inventory.overview',
    ],
    'procurement' => [
        'erp.procurement.purchase-orders',
        'erp.procurement.purchase-request',
        'erp.procurement.suppliers-management',
        'finance.purchase-request-approval',
        'finance.purchase-requests.approve',
        'finance.purchase-requests.index',
        'finance.purchase-requests.reject',
        'procurement.purchase-orders.cancel',
        'procurement.purchase-orders.destroy',
        'procurement.purchase-orders.index',
        'procurement.purchase-orders.metrics',
        'procurement.purchase-orders.receipts.index',
        'procurement.purchase-orders.receipts.store',
        'procurement.purchase-orders.receipts.void',
        'procurement.purchase-orders.send-supplier',
        'procurement.purchase-orders.show',
        'procurement.purchase-orders.store',
        'procurement.purchase-orders.update',
        'procurement.purchase-orders.update-status',
        'procurement.purchase-requests.approve',
        'procurement.purchase-requests.approved',
        'procurement.purchase-requests.destroy',
        'procurement.purchase-requests.index',
        'procurement.purchase-requests.metrics',
        'procurement.purchase-requests.reject',
        'procurement.purchase-requests.show',
        'procurement.purchase-requests.store',
        'procurement.purchase-requests.submit-finance',
        'procurement.purchase-requests.update',
        'procurement.replenishment-requests.accept',
        'procurement.replenishment-requests.destroy',
        'procurement.replenishment-requests.index',
        'procurement.replenishment-requests.metrics',
        'procurement.replenishment-requests.reject',
        'procurement.replenishment-requests.request-details',
        'procurement.replenishment-requests.show',
        'procurement.replenishment-requests.store',
        'procurement.replenishment-requests.update',
        'procurement.suppliers.destroy',
        'procurement.suppliers.index',
        'procurement.suppliers.restore',
        'procurement.suppliers.show',
        'procurement.suppliers.store',
        'procurement.suppliers.update',
        'shop-owner.purchase-request-approval',
        'shop_owner.purchase-requests.approve',
        'shop_owner.purchase-requests.index',
        'shop_owner.purchase-requests.reject',
    ],
    'logistics' => [
        'api.logistics.attempts.file',
        'api.logistics.incidents.evidence',
        'logistics.api.dashboard-stats',
        'logistics.api.riders.index',
        'logistics.api.shipments.index',
        'logistics.api.shipments.show',
        'erp.logistics.batches',
        'erp.logistics.dashboard',
        'erp.logistics.deliveries',
        'erp.logistics.riders',
        'erp.logistics.shipments',
        'shop-owner.logistics.riders',
        'shop-owner.logistics.shipments',
        'shop_owner.repairs.delivery-method',
    ],
    'excluded' => [
        'api.notifications.index',
        'api.notifications.unread-count',
        'api.notifications.recent',
        'api.notifications.stats',
        'api.notifications.mark-read',
        'api.notifications.mark-all-read',
        'api.notifications.destroy',
        'api.notifications.preferences',
        'api.notifications.update-preferences',
        'api.notifications.bulk-mark-read',
        'api.notifications.bulk-delete',
        'api.notifications.bulk-archive',
        'api.notifications.archive',
        'api.notifications.grouped',
        'api.notifications.export',
        'api.activity_logs',
        'api.shop_owner.dashboard.dss-insights',
        'api.products.vouchers.claim',
        'api.customer.repairs.warranty-claims',
        'api.customer.repairs.warranty-claims.latest',
        'api.shops.report',
    ],
];

$routeMethods = static function (string $routeName): array {
    $overrides = [
        'inventory.suppliers.update' => ['PATCH', 'PUT'],
        'finance.expenses.update' => ['PATCH'],
        'finance.expenses.settlements.reverse' => ['POST'],
        'finance.tax-rates.calculate' => ['POST'],
        'finance.invoices.update' => ['PATCH'],
        'finance.invoices.mark_sent' => ['POST'],
        'finance.invoices.mark_paid_compatibility' => ['POST'],
        'finance.invoices.send_compatibility' => ['POST'],
        'finance.invoices.post_compatibility' => ['POST'],
        'finance.invoices.payments.reverse' => ['POST'],
        'shop_owner.finance.invoices.update' => ['PATCH'],
        'shop_owner.finance.expenses.update' => ['PATCH'],
        'erp.password.update' => ['POST'],
        'shop-owner.shop-profile.update' => ['POST'],
        'shop-owner.shop-profile.password.update' => ['POST'],
        'shop-owner.compliance-documents.renewals.store' => ['POST'],
        'shop-owner.modules.update' => ['PATCH'],
        'api.leave.cancel' => ['DELETE'],
        'shop_owner.promos.update-status' => ['PATCH'],
        'shop_owner.orders.update-status' => ['PATCH'],
        'shop_owner.repairs.delivery-method' => ['PATCH'],
        'shop_owner.premium.auto-renew' => ['PATCH'],
        'shop-owner.employees.permissions.update' => ['POST'],
        'hr.employees.permissions.update' => ['POST'],
        'inventory.items.images.thumbnail' => ['PUT'],
        'shop_owner.inventory.items.images.thumbnail' => ['PUT'],
        'inventory.supplier-orders.status' => ['PUT'],
        'api.notifications.bulk-delete' => ['DELETE'],
        'staff.attendance.add_lateness_reason' => ['PATCH'],
        'staff.attendance.add_early_reason' => ['PATCH'],
        'staff.attendance.status' => ['GET'],
        'hr.attendance.patch' => ['PATCH'],
        'api.notifications.update-preferences' => ['PUT'],
        'hr.notifications.update_preferences' => ['PUT'],
        'erp.notifications.update-preferences' => ['PUT'],
        'shop_owner.notifications.update-preferences' => ['PUT'],
        'shop_owner.payslip_approval.batch_final_approve' => ['POST'],
        'shop_owner.settings.policies.publish' => ['POST'],
        'shop_owner.settings.paymongo-key' => ['POST'],
        'shop_owner.settings.geofence' => ['POST'],
        'api.products.vouchers.claim' => ['POST'],
        'api.customer.repairs.warranty-claims' => ['POST'],
        'shop_owner.repairs.approve-high-value' => ['POST'],
        'shop_owner.repairs.reject-high-value' => ['POST'],
        'inventory.products.update-quantity' => ['PUT'],
        'inventory.products.bulk-update' => ['POST'],
        'inventory.supplier-orders.receive' => ['POST'],
        'inventory.supplier-orders.generate-po' => ['POST'],
        'inventory.monitoring.receive' => ['POST'],
        'permission-audit-logs.compliance-report' => ['POST'],
        'finance.repair-price-changes.approve-final' => ['POST'],
        'hr.notifications.clear_read' => ['DELETE'],
    ];

    if (isset($overrides[$routeName])) {
        return $overrides[$routeName];
    }

    foreach (['destroy', 'delete', 'remove'] as $suffix) {
        if (str_ends_with($routeName, '.'.$suffix)) {
            return ['DELETE'];
        }
    }

    if (str_ends_with($routeName, '.cancel')) {
        return str_starts_with($routeName, 'staff.leave.') ? ['DELETE'] : ['POST'];
    }

    foreach ([
        'store',
        'approve',
        'reject',
        'restore',
        'send',
        'void',
        'post',
        'from_job',
        'submit-finance',
        'submit',
        'update-status',
        'send-supplier',
        'accept',
        'execute',
        'final_approve',
        'disburse',
        'batch_preview',
        'batch_approve',
        'mark-read',
        'mark-all-read',
        'bulk-mark-read',
        'bulk-archive',
        'archive',
        'suspend',
        'activate',
        'batch_final_approve',
        'claim',
        'warranty-claims',
        'update-quantity',
        'bulk-update',
        'status',
        'receive',
        'calculate_preview',
        'confirm_hours',
        'assign',
        'process',
        'recalculate',
        'batch.preview',
        'batch.generate',
        'batch.retry',
        'batch.export',
        'components.add',
        'apply',
        'release',
        'mark_as_read',
        'mark_all_as_read',
        'publish',
        'paymongo-key',
        'geofence',
        'request',
        'upload',
        'upload-image',
        'thumbnail',
        'reorder',
        'sync',
        'sync_ph',
        'apply-template',
        'reset_password',
        'regenerate_invite',
        'resend_invite',
        'send_invitation_email',
        'checkin',
        'checkout',
        'check_in',
        'check_out',
        'lunch_start',
        'lunch_end',
        'mark_paid',
        'mark-received',
        'mark-ready',
        'mark-completed',
        'mark-paid-in-shop',
        'activate-pickup',
        'activate-payment',
        'start-work',
        'resume-work',
        'confirm-return-received',
        'arrange-return-pickup',
        'request-price-change',
        'request-details',
        'upgrade',
        'downgrade',
        'review',
        'generate',
        'report',
        'ship',
        'transfer',
        'unarchive',
        'send-code',
        'verify-code',
        'register',
        'login',
        'logout',
        'verify',
        'resend',
        'upgrade.preview',
        'upgrade.confirm',
        'downgrade.schedule',
    ] as $suffix) {
        if (str_ends_with($routeName, '.'.$suffix)) {
            return ['POST'];
        }
    }

    foreach (['update', 'policies.draft'] as $suffix) {
        if (str_ends_with($routeName, '.'.$suffix)) {
            return ['PUT'];
        }
    }

    return ['GET'];
};

$isOwnerRoute = static fn (string $routeName): bool => str_starts_with($routeName, 'shop-owner.')
    || str_starts_with($routeName, 'shop_owner.')
    || str_starts_with($routeName, 'shopOwner.')
    || str_starts_with($routeName, 'api.shop_owner.');

$isPublicRoute = static fn (string $routeName): bool => in_array($routeName, [
    'shop-owner.email-verification.send-code',
    'shop-owner.email-verification.verify-code',
    'shop-owner.login',
    'shop-owner.login.form',
    'shop-owner.password.setup',
    'shop-owner.password.setup.store',
    'shop-owner.pending-approval.public',
    'shop-owner.register',
    'shop-owner.resubmission.form',
    'shop-owner.resubmission.document',
    'shop-owner.resubmission.submit',
    'shop-owner.two-factor.challenge',
    'shop-owner.two-factor.resend',
    'shop-owner.two-factor.verify',
], true);

$isSelfServiceRoute = static fn (string $routeName): bool => str_starts_with($routeName, 'staff.')
    || in_array($routeName, ['erp.time-in', 'erp.my-payslips', 'erp.profile', 'erp.password.update'], true);

$routeAction = static function (string $routeName): string {
    foreach (['approve', 'reject', 'checkout', 'assign', 'upload', 'delete', 'update', 'create'] as $action) {
        if (str_contains($routeName, $action)) {
            return $action;
        }
    }

    return 'view';
};

$routes = [];
foreach ($routeBuckets as $bucket => $routeNames) {
    foreach ($routeNames as $routeName) {
        $classification = 'module';
        $moduleKeys = [$bucket];

        if ($bucket === 'core' || $bucket === 'excluded') {
            $classification = 'core';
            $moduleKeys = [];
        }

        if ($bucket === 'excluded') {
            $classification = 'excluded';
        }

        $routes[$routeName] = $routeEntry(
            modules: $modules,
            classification: $classification,
            mode: $classification === 'module' ? 'single' : null,
            moduleKeys: $moduleKeys,
            methods: $routeMethods($routeName),
            audience: $isPublicRoute($routeName)
                ? 'public'
                : ($isOwnerRoute($routeName) ? 'shop_owner' : 'user'),
            actorGuard: $isPublicRoute($routeName)
                ? null
                : ($isOwnerRoute($routeName) ? 'shop_owner' : 'user'),
            action: $routeAction($routeName),
            ownerDenialReason: $classification === 'excluded'
                ? 'not_an_erp_route'
                : ($isSelfServiceRoute($routeName)
                ? 'employee_subject_required'
                : 'owner_operation_not_reviewed'),
            navigationGroup: $classification === 'module' ? $bucket : null,
            selfService: $isSelfServiceRoute($routeName),
        );
    }
}

$workspaceRoute = $routeEntry(
    modules: $modules,
    classification: 'core',
    mode: null,
    moduleKeys: [],
    methods: ['GET'],
    audience: 'shop_owner',
    actorGuard: 'shop_owner',
    action: 'view',
    ownerDenialReason: 'owner_workspace_not_exposed',
    navigationGroup: 'workspace',
    selfService: false,
);
$workspaceRoute['registration_types'] = ['company'];
$workspaceRoute['business_types'] = ['retail', 'repair', 'both'];
$workspaceRoute['owner_access'] = 'allowed';
$workspaceRoute['owner_denial_reason'] = null;
$workspaceRoute['supporting_routes'] = ['shop-owner.erp.api.workspace'];
$routes['shop-owner.erp.workspace'] = $workspaceRoute;

$workspaceApiRoute = $workspaceRoute;
$workspaceApiRoute['supporting_routes'] = ['shop-owner.erp.workspace'];
$routes['shop-owner.erp.api.workspace'] = $workspaceApiRoute;

$moduleLandingRoute = $workspaceRoute;
$moduleLandingRoute['supporting_routes'] = ['shop-owner.erp.api.workspace'];
$routes['shop-owner.erp.module'] = $moduleLandingRoute;

$ownerReadPairs = [
    'crm.dashboard' => [
        'shop-owner.erp.crm.dashboard',
        'shop-owner.erp.api.crm.dashboard-stats',
    ],
    'crm.customers' => [
        'shop-owner.erp.crm.customers',
        'shop-owner.erp.api.crm.customers.index',
    ],
    'crm.customer-reviews' => [
        'shop-owner.erp.crm.customer-reviews',
        'shop-owner.erp.api.crm.reviews.index',
    ],
    'erp.logistics.dashboard' => [
        'shop-owner.erp.logistics.dashboard',
        'shop-owner.erp.api.logistics.dashboard-stats',
    ],
    'erp.logistics.shipments' => [
        'shop-owner.erp.logistics.shipments',
        'shop-owner.erp.api.logistics.shipments.index',
    ],
    'erp.logistics.riders' => [
        'shop-owner.erp.logistics.riders',
        'shop-owner.erp.api.logistics.riders.index',
    ],
    'erp.hr.audit-logs' => [
        'shop-owner.erp.hr.audit-logs',
        'shop-owner.erp.api.hr.audit-logs',
    ],
    'erp.finance.audit-logs' => [
        'shop-owner.erp.finance.audit-logs',
        'shop-owner.erp.api.finance.audit-logs',
    ],
    'erp.manager.reports' => [
        'shop-owner.erp.manager.reports',
        'shop-owner.erp.api.manager.reports',
    ],
    'erp.manager.audit-logs' => [
        'shop-owner.erp.manager.audit-logs',
        'shop-owner.erp.api.manager.audit-logs',
    ],
    'erp.inventory.inventory-dashboard' => [
        'shop-owner.erp.inventory.inventory-dashboard',
        'shop-owner.erp.api.inventory.dashboard',
    ],
    'erp.inventory.product-inventory' => [
        'shop-owner.erp.inventory.product-inventory',
        'shop-owner.erp.api.inventory.products.index',
    ],
    'erp.inventory.stock-movement' => [
        'shop-owner.erp.inventory.stock-movement',
        'shop-owner.erp.api.inventory.movements.index',
    ],
    'erp.procurement.suppliers-management' => [
        'shop-owner.erp.procurement.suppliers-management',
        'shop-owner.erp.api.procurement.suppliers.index',
    ],
    'erp.staff.customers' => [
        'shop-owner.erp.staff.customers',
        'shop-owner.erp.api.staff.customers',
    ],
    'erp.staff.repair-dashboard' => [
        'shop-owner.erp.staff.repair-dashboard',
        'shop-owner.erp.api.staff.repair-dashboard',
    ],
];

foreach ($ownerReadPairs as $employeeRouteName => [$ownerRouteName, $supportingRouteName]) {
    if (! isset($routes[$employeeRouteName])) {
        continue;
    }

    $employeeRoute = $routes[$employeeRouteName];
    $employeeRoute['owner_access'] = 'allowed';
    $employeeRoute['owner_denial_reason'] = null;
    $employeeRoute['paired_route'] = $ownerRouteName;
    $employeeRoute['supporting_routes'] = [$supportingRouteName];
    $routes[$employeeRouteName] = $employeeRoute;

    $ownerRoute = $employeeRoute;
    $ownerRoute['audience'] = 'shop_owner';
    $ownerRoute['actor_guard'] = 'shop_owner';
    $ownerRoute['paired_route'] = $employeeRouteName;
    $ownerRoute['supporting_routes'] = [$supportingRouteName];
    $routes[$ownerRouteName] = $ownerRoute;
}

$ownerHrAuditRoute = $routeEntry(
    modules: $modules,
    classification: 'module',
    mode: 'single',
    moduleKeys: ['hr_employees'],
    methods: ['GET'],
    audience: 'shop_owner',
    actorGuard: 'shop_owner',
    action: 'view',
    ownerDenialReason: 'owner_operation_not_reviewed',
    navigationGroup: 'hr_employees',
    selfService: false,
);
$ownerHrAuditRoute['owner_access'] = 'allowed';
$ownerHrAuditRoute['owner_denial_reason'] = null;
$ownerHrAuditRoute['paired_route'] = 'erp.hr.audit-logs';
$ownerHrAuditRoute['supporting_routes'] = ['shop-owner.erp.api.hr.audit-logs'];
$routes['shop-owner.erp.hr.audit-logs'] = $ownerHrAuditRoute;

$ownerReadApiPairs = [
    'hr.audit.index' => 'shop-owner.erp.api.hr.audit-logs',
    'finance.audit.index' => 'shop-owner.erp.api.finance.audit-logs',
    'api.manager.reports.index' => 'shop-owner.erp.api.manager.reports',
    'api.manager.audit-logs' => 'shop-owner.erp.api.manager.audit-logs',
    'inventory.dashboard' => 'shop-owner.erp.api.inventory.dashboard',
    'inventory.products.index' => 'shop-owner.erp.api.inventory.products.index',
    'inventory.movements.index' => 'shop-owner.erp.api.inventory.movements.index',
    'procurement.suppliers.index' => 'shop-owner.erp.api.procurement.suppliers.index',
    'erp.staff.api.customers' => 'shop-owner.erp.api.staff.customers',
    'erp.staff.api.repair-dashboard' => 'shop-owner.erp.api.staff.repair-dashboard',
    'crm.api.dashboard-stats' => 'shop-owner.erp.api.crm.dashboard-stats',
    'crm.api.customers.index' => 'shop-owner.erp.api.crm.customers.index',
    'crm.api.customers.show' => 'shop-owner.erp.api.crm.customers.show',
    'crm.api.reviews.index' => 'shop-owner.erp.api.crm.reviews.index',
    'logistics.api.dashboard-stats' => 'shop-owner.erp.api.logistics.dashboard-stats',
    'logistics.api.shipments.index' => 'shop-owner.erp.api.logistics.shipments.index',
    'logistics.api.shipments.show' => 'shop-owner.erp.api.logistics.shipments.show',
    'logistics.api.riders.index' => 'shop-owner.erp.api.logistics.riders.index',
];

foreach ($ownerReadApiPairs as $employeeRouteName => $ownerRouteName) {
    if (! isset($routes[$employeeRouteName])) {
        continue;
    }

    $employeeRoute = $routes[$employeeRouteName];
    $employeeRoute['owner_access'] = 'allowed';
    $employeeRoute['owner_denial_reason'] = null;
    $employeeRoute['paired_route'] = $ownerRouteName;
    $employeeRoute['supporting_routes'] = [$ownerRouteName];
    $routes[$employeeRouteName] = $employeeRoute;

    $ownerRoute = $employeeRoute;
    $ownerRoute['audience'] = 'shop_owner';
    $ownerRoute['actor_guard'] = 'shop_owner';
    $ownerRoute['paired_route'] = $employeeRouteName;
    $ownerRoute['supporting_routes'] = [$employeeRouteName];
    $routes[$ownerRouteName] = $ownerRoute;
}

$retailProductsRoute = $routeEntry(
    modules: $modules,
    classification: 'module',
    mode: 'single',
    moduleKeys: ['retail_operations'],
    methods: ['GET'],
    audience: 'shop_owner',
    actorGuard: 'shop_owner',
    action: 'view',
    ownerDenialReason: 'owner_operation_not_reviewed',
    navigationGroup: 'retail_operations',
    selfService: false,
);
$retailProductsRoute['owner_access'] = 'allowed';
$retailProductsRoute['owner_denial_reason'] = null;
$retailProductsRoute['supporting_routes'] = [
    'shop_owner.products.index',
    'shop_owner.products.store',
    'shop_owner.products.show',
    'shop_owner.products.update',
    'shop_owner.products.destroy',
    'shop_owner.products.restore',
    'shop_owner.products.upload-image',
    'shop_owner.products.variants',
    'shop_owner.products.color-variants.index',
    'shop_owner.products.color-variants.store',
    'shop_owner.products.color-variants.update',
    'shop_owner.products.color-variants.destroy',
    'shop_owner.products.color-variants.images.store',
    'shop_owner.products.color-variants.images.update',
    'shop_owner.products.color-variants.images.destroy',
    'shop_owner.products.color-variants.images.reorder',
    'shop_owner.products.showroom-entitlement',
];
$retailProductsRoute['navigation_label'] = 'Products';
$retailProductsRoute['navigation_order'] = 20;
$routes['shop-owner.erp.retail.products'] = $retailProductsRoute;

if (isset($routes['shop-owner.erp.staff.repair-dashboard'])) {
    $routes['shop-owner.erp.staff.repair-dashboard']['navigation_label'] = 'Repair Dashboard';
    $routes['shop-owner.erp.staff.repair-dashboard']['navigation_order'] = 10;
}

$ownerOperationalPageRoute = static function (
    string $moduleKey,
    string $navigationLabel,
    int $navigationOrder,
    array $supportingRoutes = [],
    ?string $navigationPageGroup = null,
    ?string $navigationPageGroupLabel = null,
    ?int $navigationPageGroupOrder = null,
    bool $navigationVisible = true,
) use ($routeEntry, $modules): array {
    $route = $routeEntry(
        modules: $modules,
        classification: 'module',
        mode: 'single',
        moduleKeys: [$moduleKey],
        methods: ['GET'],
        audience: 'shop_owner',
        actorGuard: 'shop_owner',
        action: 'view',
        ownerDenialReason: 'owner_operation_not_reviewed',
        navigationGroup: $moduleKey,
        selfService: false,
    );
    $route['owner_access'] = 'allowed';
    $route['owner_denial_reason'] = null;
    $route['navigation_label'] = $navigationLabel;
    $route['navigation_order'] = $navigationOrder;
    $route['navigation_page_group'] = $navigationPageGroup;
    $route['navigation_page_group_label'] = $navigationPageGroupLabel;
    $route['navigation_page_group_order'] = $navigationPageGroupOrder;
    $route['navigation_visible'] = $navigationVisible;
    $route['supporting_routes'] = $supportingRoutes;

    return $route;
};

$retailOwnerPages = [
    'shop-owner.erp.retail.dashboard' => [
        'label' => 'Retail Dashboard',
        'order' => 10,
        'supporting_routes' => ['shop-owner.dashboard'],
    ],
    'shop-owner.erp.retail.orders' => [
        'label' => 'Orders',
        'order' => 20,
        'supporting_routes' => [
            'shop_owner.orders.index',
            'shop_owner.orders.show',
            'shop_owner.orders.update-status',
            'shop_owner.orders.activate-pickup',
            'shop_owner.orders.arrange-return-pickup',
            'shop_owner.orders.confirm-return-received',
        ],
    ],
    'shop-owner.erp.retail.point-of-sale' => [
        'label' => 'Point of Sale',
        'order' => 30,
        'supporting_routes' => ['shop-owner.point-of-sale'],
    ],
    'shop-owner.erp.retail.discounts' => [
        'label' => 'Vouchers and Discounts',
        'order' => 40,
        'supporting_routes' => [
            'shop_owner.promos.index',
            'shop_owner.promos.products',
            'shop_owner.promos.store',
            'shop_owner.promos.update',
            'shop_owner.promos.destroy',
            'shop_owner.promos.update-status',
        ],
    ],
];

foreach ($retailOwnerPages as $routeName => $page) {
    $routes[$routeName] = $ownerOperationalPageRoute(
        moduleKey: 'retail_operations',
        navigationLabel: $page['label'],
        navigationOrder: $page['order'],
        supportingRoutes: $page['supporting_routes'],
    );
}

$ownerModulePageGroups = [
    'hr_employees' => [
        'shop-owner.erp.hr.dashboard' => [
            'label' => 'Dashboard',
            'order' => 10,
            'supporting_routes' => ['erp.hr'],
        ],
        'shop-owner.erp.hr.employee-directory' => [
            'label' => 'Employees',
            'order' => 20,
            'supporting_routes' => [
                'shop-owner.employees.store',
                'shop-owner.employees.update',
                'shop-owner.employees.destroy',
                'shop-owner.employees.suspend',
                'shop-owner.employees.activate',
                'shop-owner.employees.permissions.get',
                'shop-owner.employees.permissions.update',
                'shop-owner.employees.permissions.sync',
                'shop-owner.employees.roles.sync',
                'shop-owner.employees.apply-template',
            ],
        ],
        'shop-owner.erp.hr.attendance' => [
            'label' => 'View Attendance',
            'order' => 30,
            'group' => 'attendance-monitoring',
            'group_label' => 'Attendance Monitoring',
            'group_order' => 20,
            'supporting_routes' => ['hr.attendance.index'],
        ],
        'shop-owner.erp.hr.leave-approvals' => [
            'label' => 'Leave Requests',
            'order' => 40,
            'group' => 'attendance-monitoring',
            'group_label' => 'Attendance Monitoring',
            'group_order' => 20,
            'supporting_routes' => ['api.leave.index'],
        ],
        'shop-owner.erp.hr.overtime-approvals' => [
            'label' => 'Overtime Requests',
            'order' => 50,
            'group' => 'attendance-monitoring',
            'group_label' => 'Attendance Monitoring',
            'group_order' => 20,
            'supporting_routes' => ['hr.overtime.index'],
        ],
        'shop-owner.erp.hr.payroll-view' => [
            'label' => 'View Slip',
            'order' => 60,
            'group' => 'payroll',
            'group_label' => 'Payroll',
            'group_order' => 30,
            'supporting_routes' => ['hr.payroll.index'],
        ],
        'shop-owner.erp.hr.payroll-generate' => [
            'label' => 'Generate Slip',
            'order' => 70,
            'group' => 'payroll',
            'group_label' => 'Payroll',
            'group_order' => 30,
            'supporting_routes' => [
                'hr.payroll.index',
                'hr.payroll.store',
                'hr.payroll.batch.preview',
                'hr.payroll.batch.generate',
            ],
        ],
        'shop-owner.erp.hr.salary-changes' => [
            'label' => 'Salary Changes',
            'order' => 80,
            'group' => 'payroll',
            'group_label' => 'Payroll',
            'group_order' => 30,
            'supporting_routes' => [
                'hr.salary_changes.index',
                'hr.salary_changes.store',
                'hr.salary_changes.approve',
                'hr.salary_changes.reject',
            ],
        ],
        'shop-owner.erp.hr.suspend-accounts' => [
            'label' => 'Suspend Accounts',
            'order' => 90,
            'supporting_routes' => [
                'shop_owner.suspension_requests.index',
                'shop_owner.suspension_requests.show',
                'shop_owner.suspension_requests.review',
            ],
        ],
    ],
    'finance' => [
        'shop-owner.erp.finance.dashboard' => [
            'label' => 'Dashboard',
            'order' => 10,
            'supporting_routes' => ['finance.dashboard'],
        ],
        'shop-owner.erp.finance.invoices' => [
            'label' => 'Invoices',
            'order' => 20,
            'supporting_routes' => [
                'finance.invoices.index',
                'finance.invoices.show',
                'finance.invoices.store',
                'finance.invoices.update',
                'finance.invoices.destroy',
                'finance.invoices.restore',
                'finance.invoices.send',
                'finance.invoices.void',
                'finance.invoices.mark_paid',
                'finance.invoices.post',
            ],
        ],
        'shop-owner.erp.finance.create-invoice' => [
            'label' => 'Create Invoice',
            'order' => 25,
            'visible' => false,
            'supporting_routes' => [
                'finance.create-invoice',
                'finance.invoices.store',
            ],
        ],
        'shop-owner.erp.finance.expenses' => [
            'label' => 'Expenses',
            'order' => 30,
            'supporting_routes' => [
                'finance.expenses.index',
                'finance.expenses.show',
                'finance.expenses.store',
                'finance.expenses.update',
                'finance.expenses.destroy',
                'finance.expenses.restore',
            ],
        ],
        'shop-owner.erp.finance.expense-approvals' => [
            'label' => 'Expense Approvals',
            'order' => 40,
            'group' => 'approvals',
            'group_label' => 'Approvals',
            'group_order' => 40,
            'supporting_routes' => [
                'shop_owner.expenses.index',
                'shop_owner.expenses.approve',
                'shop_owner.expenses.reject',
            ],
        ],
        'shop-owner.erp.finance.repair-pricing' => [
            'label' => 'Repair Pricing Approval',
            'order' => 50,
            'group' => 'approvals',
            'group_label' => 'Approvals',
            'group_order' => 40,
            'supporting_routes' => [
                'shop_owner.repair-price-changes.all',
                'shop_owner.repair-price-changes.approve',
                'shop_owner.repair-price-changes.reject',
            ],
        ],
        'shop-owner.erp.finance.shoe-pricing' => [
            'label' => 'Shoe Pricing Approval',
            'order' => 60,
            'group' => 'approvals',
            'group_label' => 'Approvals',
            'group_order' => 40,
            'supporting_routes' => [
                'shop_owner.price-changes.all',
                'shop_owner.price-changes.approve',
                'shop_owner.price-changes.reject',
            ],
        ],
        'shop-owner.erp.finance.purchase-request-review' => [
            'label' => 'Purchase Request Review',
            'order' => 70,
            'group' => 'approvals',
            'group_label' => 'Approvals',
            'group_order' => 40,
            'supporting_routes' => [
                'shop_owner.purchase-requests.index',
                'shop_owner.purchase-requests.approve',
                'shop_owner.purchase-requests.reject',
            ],
        ],
        'shop-owner.erp.finance.refund-approvals' => [
            'label' => 'Refund Approvals',
            'order' => 80,
            'group' => 'approvals',
            'group_label' => 'Approvals',
            'group_order' => 40,
            'supporting_routes' => [
                'shop_owner.refunds.index',
                'shop_owner.refunds.approve',
                'shop_owner.refunds.reject',
                'shop_owner.refunds.execute',
                'shop_owner.repair-refunds.index',
                'shop_owner.repair-refunds.approve',
                'shop_owner.repair-refunds.reject',
                'shop_owner.repair-refunds.execute',
            ],
        ],
        'shop-owner.erp.finance.payslip-approvals' => [
            'label' => 'Payslip Approvals',
            'order' => 90,
            'group' => 'approvals',
            'group_label' => 'Approvals',
            'group_order' => 40,
            'supporting_routes' => [
                'shop_owner.payslip_approval.index',
                'shop_owner.payslip_approval.show',
                'shop_owner.payslip_approval.final_approve',
                'shop_owner.payslip_approval.batch_final_approve',
            ],
        ],
        'shop-owner.erp.finance.salary-adjustment-approvals' => [
            'label' => 'Salary Adjustments',
            'order' => 100,
            'group' => 'approvals',
            'group_label' => 'Approvals',
            'group_order' => 40,
            'supporting_routes' => [
                'shop_owner.salary-changes.index',
                'shop_owner.salary-changes.approve',
                'shop_owner.salary-changes.reject',
            ],
        ],
    ],
    'crm' => [
        'shop-owner.erp.crm.customer-support' => [
            'label' => 'Customer Support',
            'order' => 50,
            'supporting_routes' => [
                'shop_owner.conversations.index',
                'shop_owner.conversations.show',
                'shop_owner.conversations.messages.store',
                'shop_owner.conversations.transfer',
            ],
        ],
    ],
    'inventory' => [
        'shop-owner.erp.inventory.upload-stocks' => [
            'label' => 'Upload Stocks',
            'order' => 20,
            'supporting_routes' => ['erp.inventory.upload-stocks'],
        ],
        'shop-owner.erp.inventory.stock-request' => [
            'label' => 'Stock Requests',
            'order' => 50,
            'supporting_routes' => ['erp.inventory.stock-request'],
        ],
        'shop-owner.erp.inventory.request-material-approval' => [
            'label' => 'Request Material Approval',
            'order' => 60,
            'supporting_routes' => ['erp.inventory.request-material-approval'],
        ],
        'shop-owner.erp.inventory.supplier-order-monitoring' => [
            'label' => 'Supplier Order Monitoring',
            'order' => 70,
            'supporting_routes' => ['erp.inventory.supplier-order-monitoring'],
        ],
        'shop-owner.erp.inventory.overview' => [
            'label' => 'Inventory Overview',
            'order' => 80,
            'supporting_routes' => ['shop_owner.inventory.overview'],
        ],
    ],
    'procurement' => [
        'shop-owner.erp.procurement.purchase-request' => [
            'label' => 'Purchase Requests',
            'order' => 10,
            'supporting_routes' => ['erp.procurement.purchase-request'],
        ],
        'shop-owner.erp.procurement.purchase-orders' => [
            'label' => 'Purchase Orders',
            'order' => 20,
            'supporting_routes' => ['erp.procurement.purchase-orders'],
        ],
        'shop-owner.erp.procurement.stock-request-approval' => [
            'label' => 'Stock Request Approval',
            'order' => 30,
            'supporting_routes' => ['erp.procurement.stock-request-approval'],
        ],
        'shop-owner.erp.procurement.purchase-request-approval' => [
            'label' => 'Purchase Request Approval',
            'order' => 50,
            'supporting_routes' => [
                'shop_owner.purchase-requests.index',
                'shop_owner.purchase-requests.approve',
                'shop_owner.purchase-requests.reject',
            ],
        ],
    ],
    'logistics' => [
        'shop-owner.erp.logistics.batches' => [
            'label' => 'Batches',
            'order' => 30,
            'supporting_routes' => ['erp.logistics.batches'],
        ],
        'shop-owner.erp.logistics.settings' => [
            'label' => 'Settings',
            'order' => 50,
            'supporting_routes' => ['erp.logistics.settings'],
        ],
    ],
];

foreach ($ownerModulePageGroups as $moduleKey => $pages) {
    foreach ($pages as $routeName => $page) {
        $routes[$routeName] = $ownerOperationalPageRoute(
            moduleKey: $moduleKey,
            navigationLabel: $page['label'],
            navigationOrder: $page['order'],
            supportingRoutes: $page['supporting_routes'],
            navigationPageGroup: $page['group'] ?? null,
            navigationPageGroupLabel: $page['group_label'] ?? null,
            navigationPageGroupOrder: $page['group_order'] ?? null,
            navigationVisible: $page['visible'] ?? true,
        );
    }
}

$existingOwnerPageMetadata = [
    'shop-owner.erp.crm.dashboard' => ['label' => 'Dashboard', 'order' => 10],
    'shop-owner.erp.crm.customers' => ['label' => 'Customers', 'order' => 20],
    'shop-owner.erp.crm.customer-reviews' => ['label' => 'Customer Reviews', 'order' => 30],
    'shop-owner.erp.staff.customers' => ['label' => 'Customer Directory', 'order' => 40],
    'shop-owner.erp.inventory.inventory-dashboard' => ['label' => 'Dashboard', 'order' => 10],
    'shop-owner.erp.inventory.product-inventory' => ['label' => 'Product Inventory', 'order' => 30],
    'shop-owner.erp.inventory.stock-movement' => ['label' => 'Stock Movement', 'order' => 40],
    'shop-owner.erp.inventory.overview' => ['label' => 'Inventory Overview', 'order' => 80],
    'shop-owner.erp.procurement.suppliers-management' => ['label' => 'Suppliers Management', 'order' => 40],
    'shop-owner.erp.hr.audit-logs' => ['label' => 'Audit Logs', 'order' => 100],
    'shop-owner.erp.finance.audit-logs' => ['label' => 'Audit Logs', 'order' => 110],
    'shop-owner.erp.logistics.dashboard' => ['label' => 'Dashboard', 'order' => 10],
    'shop-owner.erp.logistics.shipments' => ['label' => 'Shipments', 'order' => 20],
    'shop-owner.erp.logistics.riders' => ['label' => 'Riders', 'order' => 40],
    'shop-owner.erp.staff.repair-dashboard' => ['label' => 'Repair Dashboard', 'order' => 10],
];

foreach ($existingOwnerPageMetadata as $routeName => $metadata) {
    if (isset($routes[$routeName])) {
        $routes[$routeName]['navigation_label'] = $metadata['label'];
        $routes[$routeName]['navigation_order'] = $metadata['order'];
    }
}

$repairOwnerPages = [
    'shop-owner.erp.repair.job-orders' => [
        'label' => 'Repair Job Orders',
        'order' => 20,
        'supporting_routes' => [
            'shop_owner.repairs.index',
            'shop_owner.repairs.accept',
            'shop_owner.repairs.reject',
            'shop_owner.repairs.mark-received',
            'shop_owner.repairs.start-work',
            'shop_owner.repairs.resume-work',
            'shop_owner.repairs.mark-completed',
            'shop_owner.repairs.materials.index',
            'shop_owner.repairs.materials.store',
            'shop_owner.repairs.materials.destroy',
            'shop_owner.repairs.mark-ready',
            'shop_owner.repairs.activate-pickup',
            'shop_owner.repairs.activate-payment',
            'shop_owner.repairs.mark-paid-in-shop',
            'shop_owner.repairs.ship',
        ],
    ],
    'shop-owner.erp.repair.warranty-queue' => [
        'label' => 'Warranty Queue',
        'order' => 30,
        'supporting_routes' => ['shop-owner.warranty-queue'],
    ],
    'shop-owner.erp.repair.services' => [
        'label' => 'Services and Packages',
        'order' => 40,
        'supporting_routes' => [
            'shop_owner.repair-services.index',
            'shop_owner.repair-services.store',
            'shop_owner.repair-services.show',
            'shop_owner.repair-services.update',
            'shop_owner.repair-services.destroy',
            'shop_owner.repair-services.restore',
            'shop_owner.repair-materials.index',
        ],
    ],
    'shop-owner.erp.repair.stock-materials' => [
        'label' => 'Stock Materials',
        'order' => 50,
        'supporting_routes' => [
            'shop_owner.inventory.items.index',
            'shop_owner.inventory.items.store',
            'shop_owner.inventory.items.update',
            'shop_owner.inventory.items.destroy',
            'shop_owner.inventory.items.restore',
        ],
    ],
    'shop-owner.erp.repair.point-of-sale' => [
        'label' => 'Repair Point of Sale',
        'order' => 60,
        'supporting_routes' => ['shop-owner.point-of-sale'],
    ],
    'shop-owner.erp.repair.support' => [
        'label' => 'Repair Support',
        'order' => 70,
        'supporting_routes' => [
            'shop_owner.conversations.index',
            'shop_owner.conversations.show',
            'shop_owner.conversations.messages.store',
            'shop_owner.conversations.transfer',
            'shop_owner.conversations.activate-payment',
        ],
    ],
];

foreach ($repairOwnerPages as $routeName => $page) {
    $routes[$routeName] = $ownerOperationalPageRoute(
        moduleKey: 'repair_operations',
        navigationLabel: $page['label'],
        navigationOrder: $page['order'],
        supportingRoutes: $page['supporting_routes'],
    );
}

$ownerOperationalApiRouteGroups = [
    [
        'page_route' => 'shop-owner.erp.finance.invoices',
        'risk_tier' => 'financial',
        'domain_rule' => 'Owner invoice operations remain scoped to the authenticated company and Finance controller policy checks.',
        'routes' => [
            'shop_owner.finance.invoices.index',
            'shop_owner.finance.invoices.show',
            'shop_owner.finance.invoices.store',
            'shop_owner.finance.invoices.from_job',
            'shop_owner.finance.invoices.update',
            'shop_owner.finance.invoices.destroy',
            'shop_owner.finance.invoices.restore',
            'shop_owner.finance.invoices.send',
            'shop_owner.finance.invoices.void',
            'shop_owner.finance.invoices.mark_paid',
            'shop_owner.finance.invoices.post',
        ],
    ],
    [
        'page_route' => 'shop-owner.erp.finance.expenses',
        'risk_tier' => 'financial',
        'domain_rule' => 'Owner expense operations remain scoped to the authenticated company and Finance approval/settlement policy checks.',
        'routes' => [
            'shop_owner.finance.expenses.index',
            'shop_owner.finance.expenses.show',
            'shop_owner.finance.expenses.store',
            'shop_owner.finance.expenses.update',
            'shop_owner.finance.expenses.destroy',
            'shop_owner.finance.expenses.restore',
            'shop_owner.finance.expenses.approve',
            'shop_owner.finance.expenses.reject',
            'shop_owner.finance.expenses.receipt.upload',
            'shop_owner.finance.expenses.receipt.download',
            'shop_owner.finance.expenses.receipt.delete',
        ],
    ],
    [
        'page_route' => 'shop-owner.erp.finance.dashboard',
        'risk_tier' => 'financial',
        'domain_rule' => 'Owner Finance dashboard and tax-rate reads remain scoped to the authenticated company and Finance policy checks.',
        'routes' => [
            'shop_owner.finance.dashboard.summary',
            'shop_owner.finance.tax-rates.index',
        ],
    ],
    [
        'page_route' => 'shop-owner.erp.hr.attendance',
        'risk_tier' => 'sensitive',
        'domain_rule' => 'Owner attendance reads remain scoped to the authenticated company.',
        'routes' => ['shop_owner.hr.attendance.by_employee'],
    ],
    [
        'page_route' => 'shop-owner.erp.hr.payroll-view',
        'risk_tier' => 'sensitive',
        'domain_rule' => 'Owner payroll reads remain scoped to the authenticated company and payroll policy checks.',
        'routes' => [
            'shop_owner.hr.payroll.periods',
            'shop_owner.hr.payroll.index',
            'shop_owner.hr.payroll.show',
        ],
    ],
    [
        'page_route' => 'shop-owner.erp.hr.payroll-generate',
        'risk_tier' => 'sensitive',
        'domain_rule' => 'Owner payroll mutations remain scoped to the authenticated company and payroll approval/disbursement checks.',
        'routes' => [
            'shop_owner.hr.payroll.store',
            'shop_owner.hr.payroll.calculate_preview',
            'shop_owner.hr.payroll.batch.preview',
            'shop_owner.hr.payroll.batch.generate',
            'shop_owner.hr.payroll.batch.retry',
            'shop_owner.hr.payroll.batch.export',
        ],
    ],
];

foreach ($ownerOperationalApiRouteGroups as $group) {
    foreach ($group['routes'] as $routeName) {
        if (! isset($routes[$routeName])) {
            continue;
        }

        $routes[$routeName]['owner_access'] = 'allowed';
        $routes[$routeName]['owner_denial_reason'] = null;
        $routes[$routeName]['supporting_routes'] = [$group['page_route']];
        $routes[$routeName]['actor_persistence'] = 'existing_owner_ref';
        $routes[$routeName]['risk_tier'] = $group['risk_tier'];
        $routes[$routeName]['domain_rule'] = $group['domain_rule'];
    }
}

return [
    'enforcement_enabled' => (bool) env('SHOP_MODULE_ENFORCEMENT_ENABLED', false),
    'owner_erp_workspace_enabled' => (bool) env('SHOP_OWNER_ERP_WORKSPACE_ENABLED', false),
    'supported_gate_modes' => ['single', 'all_of', 'any_of'],
    'modules' => $modules,
    'routes' => $routes,
    'core_route_names' => array_keys(array_filter(
        $routes,
        static fn (array $route): bool => $route['classification'] === 'core',
    )),
];
