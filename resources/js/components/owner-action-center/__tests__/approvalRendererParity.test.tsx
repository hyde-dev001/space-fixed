import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import RefundApprovalDetails from "../approvals/RefundApprovalDetails";
import PriceApprovalDetails from "../approvals/PriceApprovalDetails";
import PayslipApprovalDetails from "../approvals/PayslipApprovalDetails";
import SalaryAdjustmentApprovalDetails from "../approvals/SalaryAdjustmentApprovalDetails";
import PurchaseRequestApprovalDetails from "../approvals/PurchaseRequestApprovalDetails";
import ExpenseApprovalDetails from "../approvals/ExpenseApprovalDetails";
import RepairRejectApprovalDetails from "../approvals/RepairRejectApprovalDetails";
import SuspensionApprovalDetails from "../approvals/SuspensionApprovalDetails";
import type { OwnerAttentionItem } from "../../../types/ownerActionCenter";

const item = (overrides: Partial<OwnerAttentionItem> = {}): OwnerAttentionItem => ({
  attention_key: "approval:1:owner_approval",
  source_type: "expense",
  source_id: 1,
  category: "approval",
  primary_bucket: "needs_my_decision",
  module: "finance",
  title: "Approval #1",
  concise_summary: "Review this approval.",
  priority_tier: "normal",
  materiality_tier: "normal",
  comparable_monetary_exposure: null,
  urgency_at: null,
  actionable_since: "2026-08-22T09:00:00+08:00",
  waiting_on: "shop_owner",
  owner_action_required: true,
  coverage_source: "expenses",
  destination_url: "/shop-owner/action-center",
  ...overrides,
});

describe("owner approval renderer parity", () => {
  it("renders the transformed refund detail fields", () => {
    render(
      <RefundApprovalDetails
        item={item({ source_type: "order_refund", title: "Refund approval" })}
        detail={{
          orderNumber: "SO-2026-0012",
          customerName: "Jane Customer",
          refundAmountValue: 1250,
          refundMethod: "GCash",
          requestDate: "2026-08-22",
          refundReason: "Wrong item received",
          refundNote: "Wrong color variant delivered",
          rawStatus: "pending_approval",
          approvalStage: "shop_owner",
        }}
      />,
    );

    expect(screen.getByText("SO-2026-0012")).toBeInTheDocument();
    expect(screen.getByText("Jane Customer")).toBeInTheDocument();
    expect(screen.getByText("GCash")).toBeInTheDocument();
    expect(screen.getByText("Wrong item received")).toBeInTheDocument();
  });

  it("renders the owner detail shapes for prices, payroll, salary, purchasing, expenses, and repairs", () => {
    const cases = [
      {
        renderer: <PriceApprovalDetails item={item({ source_type: "product_price_change", title: "Product price" })} detail={{ product_name: "Trail Shoe", current_price: 1000, proposed_price: 1250, status: "finance_approved" }} />,
        values: ["Trail Shoe", "₱1,000.00", "₱1,250.00"],
      },
      {
        renderer: <PayslipApprovalDetails item={item({ source_type: "payslip", title: "Payslip" })} detail={{ employee_name: "Jane Customer", gross_pay: 25000, net_pay: 22000, pay_period: "August 2026" }} />,
        values: ["Jane Customer", "August 2026", "₱25,000.00", "₱22,000.00"],
      },
      {
        renderer: <SalaryAdjustmentApprovalDetails item={item({ source_type: "salary_change", title: "Salary change" })} detail={{ employee: { first_name: "Jane", last_name: "Customer" }, previous_salary: 20000, new_salary: 22000, proposer: { name: "Manager" }, status: "pending" }} />,
        values: ["Jane Customer", "₱20,000.00", "₱22,000.00", "Manager"],
      },
      {
        renderer: <PurchaseRequestApprovalDetails item={item({ source_type: "purchase_request", title: "Purchase request" })} detail={{ pr_number: "PR-12", product_name: "Trail Shoe", quantity: 3, requested_size: "42", supplier: { name: "Supplier Co." }, total_cost: 4500, status: "pending_shop_owner" }} />,
        values: ["PR-12", "Trail Shoe", "Supplier Co.", "₱4,500.00"],
      },
      {
        renderer: <SuspensionApprovalDetails item={item({ source_type: "suspension_request", title: "Employee suspension" })} detail={{ id: 42, name: "Alex Employee", email: "alex@example.com", position: "Technician", requested_by: "Manager", requested_at: "2026-08-25T09:00:00+08:00", reason: "Repeated policy violations", manager_status: "approved", status: "pending" }} />,
        values: ["Alex Employee", "alex@example.com", "Technician", "Repeated policy violations"],
      },
      {
        renderer: <ExpenseApprovalDetails item={item({ source_type: "expense", title: "Expense" })} detail={{ reference: "EXP-12", category: "Operations", amount: 450, description: "Submitted supplier expense", receipt_path: "receipts/exp-12.pdf", status: "submitted" }} />,
        values: ["EXP-12", "Operations", "Submitted supplier expense", "receipts/exp-12.pdf"],
      },
      {
        renderer: <RepairRejectApprovalDetails item={item({ source_type: "repair_rejection", title: "Repair rejection" })} detail={{ request_number: "RR-12", user: { first_name: "Jane", last_name: "Customer" }, services: [{ name: "Sole repair" }], repairer: { first_name: "Alex", last_name: "Repairer" }, repairer_rejection_reason: "Cannot restore sole", status: "owner_approval_pending" }} />,
        values: ["RR-12", "Jane Customer", "Sole repair", "Alex Repairer", "Cannot restore sole"],
      },
    ];

    for (const testCase of cases) {
      const { unmount } = render(testCase.renderer);
      for (const value of testCase.values) expect(screen.getAllByText(value).length).toBeGreaterThan(0);
      unmount();
    }
  });
});
