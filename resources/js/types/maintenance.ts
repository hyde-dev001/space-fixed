export type MaintenanceLifecycleState = 'operational' | 'scheduled' | 'active' | 'unavailable';

export type MaintenanceStatus = {
  state: 'scheduled' | 'active';
  id: number;
  title: string;
  message: string;
  starts_at: string;
  ends_at: string;
  notify_before_minutes: number;
  transaction_freeze_minutes: number | null;
  progress_stage: string | null;
  update_message: string | null;
  update_message_updated_at: string | null;
  server_time: string;
};

export type OperationalMaintenanceStatus = {
  state: 'operational';
  server_time: string;
};

export type UnavailableMaintenanceStatus = {
  state: 'unavailable';
  server_time: string;
};

export type MaintenanceSnapshot =
  | MaintenanceStatus
  | OperationalMaintenanceStatus
  | UnavailableMaintenanceStatus;

export const CRITICAL_MAINTENANCE_ROUTE_NAMES = new Set([
  'checkout.create-order',
  'orders.request-refund',
  'payments.paymongo.create',
  'api.orders.update-payment-link',
  'api.orders.retry-payment-session',
  'api.customer.repairs.update-payment-link',
  'api.customer.repairs.retry-payment-session',
  'api.customer.repairs.refunds.store',
  'api.repair-pos.checkout',
  'api.repair-pos.refunds.store',
  'api.retail-pos.checkout',
  'api.retail-pos.refunds.store',
  'api.repairer.conversations.activate-payment',
  'api.repairer.repairs.activate-payment',
  'shop_owner.repairs.activate-payment',
  'shop_owner.conversations.activate-payment',
  'shop_owner.premium.checkout',
  'shop_owner.premium.upgrade.confirm',
  'hr.payroll.process',
  'hr.payroll.thirteenth.release',
  'hr.payroll.batch.generate',
  'hr.payroll.batch.retry',
  'finance.payslip_approval.disburse',
  'procurement.purchase-requests.store',
  'procurement.purchase-requests.submit-finance',
  'logistics.api.batches.store',
  'logistics.api.legs.schedule',
  'logistics.api.batches.offer',
  'api.finance.approvals.approve',
  'api.finance.approvals.reject',
  'api.leave.approve',
  'api.leave.reject',
  'api.manager.suspension_requests.review',
  'finance.expenses.approve',
  'finance.expenses.reject',
  'finance.payslip_approval.approve',
  'finance.payslip_approval.batch_approve',
  'finance.payslip_approval.final_approve',
  'finance.payslip_approval.reject',
  'finance.price-changes.approve',
  'finance.price-changes.reject',
  'finance.purchase-requests.approve',
  'finance.purchase-requests.reject',
  'finance.refunds.approve',
  'finance.refunds.reject',
  'finance.repair-price-changes.approve',
  'finance.repair-price-changes.approve-final',
  'finance.repair-price-changes.reject',
  'hr.leave.approve',
  'hr.leave.reject',
  'hr.overtime.approve',
  'hr.overtime.reject',
  'hr.payroll.approve',
  'hr.salary_changes.approve',
  'hr.salary_changes.reject',
  'inventory.request-material-approvals.approve',
  'inventory.request-material-approvals.reject',
  'procurement.purchase-requests.approve',
  'procurement.purchase-requests.reject',
  'procurement.replenishment-requests.accept',
  'procurement.replenishment-requests.reject',
  'procurement.stock-requests.approve',
  'procurement.stock-requests.reject',
  'shop-owner.employees.activate',
  'shop_owner.expenses.approve',
  'shop_owner.expenses.reject',
  'shop_owner.finance.expenses.approve',
  'shop_owner.finance.expenses.reject',
  'shop_owner.payslip_approval.batch_final_approve',
  'shop_owner.payslip_approval.final_approve',
  'shop_owner.price-changes.approve',
  'shop_owner.price-changes.reject',
  'shop_owner.purchase-requests.approve',
  'shop_owner.purchase-requests.reject',
  'shop_owner.refunds.approve',
  'shop_owner.refunds.reject',
  'shop_owner.repair-price-changes.approve',
  'shop_owner.repair-price-changes.reject',
  'shop_owner.repair-services.owner.approve',
  'shop_owner.repair-services.owner.reject',
  'shop_owner.repair-refunds.approve',
  'shop_owner.repair-refunds.reject',
  'shop_owner.repairs.approve-rejection',
  'shop_owner.repairs.reject-rejection',
  'shop_owner.salary-changes.approve',
  'shop_owner.suspension_requests.review',
]);
