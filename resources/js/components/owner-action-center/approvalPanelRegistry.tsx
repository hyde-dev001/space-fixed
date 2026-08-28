import type { ComponentType } from "react";
import type { OwnerAttentionItem, OwnerAttentionSourceType } from "../../types/ownerActionCenter";
import type { ApprovalDetailRendererProps } from "./approvalDetails";
import ExpenseApprovalDetails from "./approvals/ExpenseApprovalDetails";
import PayslipApprovalDetails from "./approvals/PayslipApprovalDetails";
import PriceApprovalDetails from "./approvals/PriceApprovalDetails";
import PurchaseRequestApprovalDetails from "./approvals/PurchaseRequestApprovalDetails";
import RefundApprovalDetails from "./approvals/RefundApprovalDetails";
import RepairRejectApprovalDetails from "./approvals/RepairRejectApprovalDetails";
import SalaryAdjustmentApprovalDetails from "./approvals/SalaryAdjustmentApprovalDetails";
import SuspensionApprovalDetails from "./approvals/SuspensionApprovalDetails";

export type ApprovalAction = "approve" | "reject";

export interface ApprovalActionConfig {
  path: (id: number) => string;
  body?: (reason?: string) => Record<string, unknown>;
  minLength?: number;
  maxLength?: number;
}

export interface ApprovalPanelDefinition {
  sourceType: ApprovalSourceType;
  label: string;
  noun: string;
  detailPath: (id: number) => string;
  renderer: ComponentType<ApprovalDetailRendererProps>;
  approve?: ApprovalActionConfig;
  reject?: ApprovalActionConfig;
  consequence: string;
}

export type ApprovalSourceType = Exclude<
  OwnerAttentionSourceType,
  "compliance_document" | "logistics_failure"
>;

const noteBody = (key: string) => (reason?: string): Record<string, unknown> => ({
  [key]: reason ?? "",
});

const definition = (config: ApprovalPanelDefinition): ApprovalPanelDefinition => config;

export const approvalPanelRegistry: Record<ApprovalSourceType, ApprovalPanelDefinition> = {
  order_refund: definition({
    sourceType: "order_refund",
    label: "Order refund approval",
    noun: "Order refund",
    detailPath: (id) => `/api/shop-owner/refunds/${id}`,
    renderer: RefundApprovalDetails,
    approve: {
      path: (id) => `/api/shop-owner/refunds/${id}/approve`,
      body: noteBody("approval_note"),
    },
    reject: {
      path: (id) => `/api/shop-owner/refunds/${id}/reject`,
      body: noteBody("rejection_reason"),
      maxLength: 1000,
    },
    consequence: "send the refund to the next authoritative refund workflow stage",
  }),
  repair_refund: definition({
    sourceType: "repair_refund",
    label: "Repair refund approval",
    noun: "Repair refund",
    detailPath: (id) => `/api/shop-owner/repair-refunds/${id}`,
    renderer: RefundApprovalDetails,
    approve: {
      path: (id) => `/api/shop-owner/repair-refunds/${id}/approve`,
      body: noteBody("approval_note"),
    },
    reject: {
      path: (id) => `/api/shop-owner/repair-refunds/${id}/reject`,
      body: noteBody("reason"),
      maxLength: 255,
    },
    consequence: "send the repair refund to the next authoritative refund workflow stage",
  }),
  product_price_change: definition({
    sourceType: "product_price_change",
    label: "Product price approval",
    noun: "Product price change",
    detailPath: (id) => `/api/shop-owner/price-changes/${id}`,
    renderer: PriceApprovalDetails,
    approve: { path: (id) => `/api/shop-owner/price-changes/${id}/approve` },
    reject: {
      path: (id) => `/api/shop-owner/price-changes/${id}/reject`,
      body: noteBody("reason"),
      maxLength: 500,
    },
    consequence: "publish the approved product price through the authoritative pricing workflow",
  }),
  repair_price_change: definition({
    sourceType: "repair_price_change",
    label: "Repair service price approval",
    noun: "Repair service price change",
    detailPath: (id) => `/api/shop-owner/repair-price-changes/${id}`,
    renderer: PriceApprovalDetails,
    approve: {
      path: (id) => `/api/repair-services/${id}/owner/approve`,
      body: () => ({ request_type: "service" }),
    },
    reject: {
      path: (id) => `/api/repair-services/${id}/owner/reject`,
      body: (reason) => ({ request_type: "service", reason: reason ?? "" }),
      maxLength: 1000,
    },
    consequence: "publish the approved repair service price through the authoritative pricing workflow",
  }),
  repair_package_price_change: definition({
    sourceType: "repair_package_price_change",
    label: "Repair package price approval",
    noun: "Repair package price change",
    detailPath: (id) => `/api/repair-packages/${id}`,
    renderer: PriceApprovalDetails,
    approve: {
      path: (id) => `/api/repair-services/${id}/owner/approve`,
      body: () => ({ request_type: "package" }),
    },
    reject: {
      path: (id) => `/api/repair-services/${id}/owner/reject`,
      body: (reason) => ({ request_type: "package", reason: reason ?? "" }),
      maxLength: 1000,
    },
    consequence: "publish the approved repair package price through the authoritative pricing workflow",
  }),
  payslip: definition({
    sourceType: "payslip",
    label: "Payslip approval",
    noun: "Payslip",
    detailPath: (id) => `/api/shop-owner/payslip-approvals/${id}`,
    renderer: PayslipApprovalDetails,
    approve: {
      path: (id) => `/api/shop-owner/payslip-approvals/${id}/final-approve`,
      body: noteBody("notes"),
    },
    consequence: "move the payslip to the authoritative payroll disbursement workflow",
  }),
  salary_change: definition({
    sourceType: "salary_change",
    label: "Salary adjustment approval",
    noun: "Salary adjustment",
    detailPath: (id) => `/api/shop-owner/salary-changes/${id}`,
    renderer: SalaryAdjustmentApprovalDetails,
    approve: {
      path: (id) => `/api/shop-owner/salary-changes/${id}/approve`,
      body: noteBody("notes"),
    },
    reject: {
      path: (id) => `/api/shop-owner/salary-changes/${id}/reject`,
      body: noteBody("notes"),
      maxLength: 1000,
    },
    consequence: "move the salary adjustment to the authoritative HR workflow stage",
  }),
  purchase_request: definition({
    sourceType: "purchase_request",
    label: "Purchase request approval",
    noun: "Purchase request",
    detailPath: (id) => `/api/shop-owner/purchase-requests/${id}`,
    renderer: PurchaseRequestApprovalDetails,
    approve: {
      path: (id) => `/api/shop-owner/purchase-requests/${id}/approve`,
      body: noteBody("approval_notes"),
    },
    reject: {
      path: (id) => `/api/shop-owner/purchase-requests/${id}/reject`,
      body: noteBody("rejection_reason"),
      maxLength: 1000,
    },
    consequence: "move the purchase request to the next authoritative procurement workflow stage",
  }),
  suspension_request: definition({
    sourceType: "suspension_request",
    label: "Employee suspension approval",
    noun: "Employee suspension",
    detailPath: (id) => `/api/shop-owner/suspension-requests/${id}`,
    renderer: SuspensionApprovalDetails,
    approve: {
      path: (id) => `/api/shop-owner/suspension-requests/${id}/review`,
      body: noteBody("note"),
    },
    reject: {
      path: (id) => `/api/shop-owner/suspension-requests/${id}/review`,
      body: noteBody("note"),
      maxLength: 1000,
    },
    consequence: "suspend or keep the employee active through the authoritative HR workflow",
  }),
  expense: definition({
    sourceType: "expense",
    label: "Expense approval",
    noun: "Expense",
    detailPath: (id) => `/api/shop-owner/expenses/${id}`,
    renderer: ExpenseApprovalDetails,
    approve: {
      path: (id) => `/api/shop-owner/expenses/${id}/approve`,
      body: noteBody("approval_notes"),
    },
    reject: {
      path: (id) => `/api/shop-owner/expenses/${id}/reject`,
      body: noteBody("rejection_reason"),
      maxLength: 1000,
    },
    consequence: "move this record to the next authoritative workflow stage",
  }),
  repair_rejection: definition({
    sourceType: "repair_rejection",
    label: "Repair rejection approval",
    noun: "Repair rejection",
    detailPath: (id) => `/api/shop-owner/repairs/rejection-pending/${id}`,
    renderer: RepairRejectApprovalDetails,
    approve: {
      path: (id) => `/api/shop-owner/repairs/${id}/approve-rejection`,
      body: noteBody("notes"),
    },
    reject: {
      path: (id) => `/api/shop-owner/repairs/${id}/reject-rejection`,
      body: noteBody("notes"),
      minLength: 10,
      maxLength: 500,
    },
    consequence: "send the repair rejection to the authoritative repair workflow decision stage",
  }),
};

export const approvalDefinitionFor = (
  sourceType: OwnerAttentionSourceType,
): ApprovalPanelDefinition | null => {
  if (sourceType === "compliance_document" || sourceType === "logistics_failure") return null;
  return approvalPanelRegistry[sourceType];
};

export const approvalRecordLabel = (item: Pick<OwnerAttentionItem, "source_type" | "source_id">): string => {
  const definition = approvalDefinitionFor(item.source_type);
  return `${definition?.noun ?? "Approval"} #${item.source_id}`;
};
