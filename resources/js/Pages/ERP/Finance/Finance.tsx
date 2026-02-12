import { useMemo, useState, useEffect } from "react";
import { Head, usePage } from "@inertiajs/react";
import AppLayoutERP from "../../../layout/AppLayout_ERP";
import { hasPermission, hasAnyPermission } from "../../../utils/permissions";
// REMOVED: Enterprise-level features not needed for SMEs
// import ChartoOfAccounts from "../Finance/ChartoOfAccounts";
// import { JournalEntries } from "../Finance/JournalEntries";
import Invoice from "../Finance/Invoice";
import Expense from "../Finance/Expense";
// import FinancialReporting from "../Finance/FinancialReporting";
// import BudgetAnalysis from "../Finance/BudgetAnalysis";
// import Reconciliation from "../Finance/Reconciliation";
import CreateInvoice from "../Finance/createInvoice";
import RepairPriceApproval from "../Finance/repairPriceApproval";
import ShoePriceApproval from "../Finance/shoePriceApproval";
import PayslipApproval from "../Finance/payslipApproval";
import ErrorModal from "../../../components/common/ErrorModal";

// Simplified for SMEs - only essential features
type Section =
  | "invoice-generation"
  | "create-invoice"
  | "expense-tracking"
  | "repair-pricing"
  | "shoe-pricing"
  | "payslip-approvals";

export default function FinancePage() {
  const { auth, url } = usePage().props as any;
  const userRole = auth?.user?.role;
  const [error, setError] = useState<string | null>(null);

  // Mark page as successfully loaded
  useEffect(() => {
    sessionStorage.setItem('finance_page_loaded', Date.now().toString());
  }, []);

  const section: Section = useMemo(() => {
    // Extract section from the URL query parameter
    if (typeof window !== 'undefined') {
      const urlParams = new URLSearchParams(window.location.search);
      const value = urlParams.get("section") || "invoice-generation";
      if (["invoice-generation", "create-invoice", "expense-tracking", "repair-pricing", "shoe-pricing", "payslip-approvals"].includes(value)) return value as Section;
      return "invoice-generation";
    }
    return "invoice-generation";
  }, []);

  const headTitle = useMemo(() => {
    if (section === "invoice-generation") return "Invoices - Solespace ERP";
    if (section === "create-invoice") return "Create Invoice - Solespace ERP";
    if (section === "expense-tracking") return "Expense Tracking - Solespace ERP";
    if (section === "repair-pricing") return "Repair Price Approval - Solespace ERP";
    if (section === "shoe-pricing") return "Shoe Price Approval - Solespace ERP";
    if (section === "payslip-approvals") return "Payslip Approvals - Solespace ERP";
    return "Finance - Solespace ERP";
  }, [section]);

  const renderContent = () => {
    try {
      switch (section) {
        case "invoice-generation":
          // Check invoice permissions
          if (!hasAnyPermission(auth, ['view-invoices', 'create-invoices', 'edit-invoices', 'delete-invoices', 'send-invoices'])) {
            return (
              <div className="flex items-center justify-center h-96">
                <div className="text-center">
                  <div className="text-red-500 text-6xl mb-4">🚫</div>
                  <h2 className="text-2xl font-bold text-gray-800 dark:text-gray-200 mb-2">Access Denied</h2>
                  <p className="text-gray-600 dark:text-gray-400">You don't have permission to view invoices.</p>
                </div>
              </div>
            );
          }
          return <Invoice />;
          
        case "create-invoice":
          // Check create invoice permission
          if (!hasPermission(auth, 'create-invoices')) {
            return (
              <div className="flex items-center justify-center h-96">
                <div className="text-center">
                  <div className="text-red-500 text-6xl mb-4">🚫</div>
                  <h2 className="text-2xl font-bold text-gray-800 dark:text-gray-200 mb-2">Access Denied</h2>
                  <p className="text-gray-600 dark:text-gray-400">You don't have permission to create invoices.</p>
                </div>
              </div>
            );
          }
          return <CreateInvoice />;
          
        case "expense-tracking":
          // Check expense permissions
          if (!hasAnyPermission(auth, ['view-expenses', 'create-expenses', 'edit-expenses', 'delete-expenses', 'approve-expenses'])) {
            return (
              <div className="flex items-center justify-center h-96">
                <div className="text-center">
                  <div className="text-red-500 text-6xl mb-4">🚫</div>
                  <h2 className="text-2xl font-bold text-gray-800 dark:text-gray-200 mb-2">Access Denied</h2>
                  <p className="text-gray-600 dark:text-gray-400">You don't have permission to view expenses.</p>
                </div>
              </div>
            );
          }
          return <Expense />;
          
        case "repair-pricing":
          // Check pricing permissions
          if (!hasAnyPermission(auth, ['view-pricing', 'edit-pricing', 'manage-service-pricing'])) {
            return (
              <div className="flex items-center justify-center h-96">
                <div className="text-center">
                  <div className="text-red-500 text-6xl mb-4">🚫</div>
                  <h2 className="text-2xl font-bold text-gray-800 dark:text-gray-200 mb-2">Access Denied</h2>
                  <p className="text-gray-600 dark:text-gray-400">You don't have permission to manage repair pricing.</p>
                </div>
              </div>
            );
          }
          return <RepairPriceApproval />;
          
        case "shoe-pricing":
          // Check pricing permissions
          if (!hasAnyPermission(auth, ['view-pricing', 'edit-pricing', 'manage-service-pricing'])) {
            return (
              <div className="flex items-center justify-center h-96">
                <div className="text-center">
                  <div className="text-red-500 text-6xl mb-4">🚫</div>
                  <h2 className="text-2xl font-bold text-gray-800 dark:text-gray-200 mb-2">Access Denied</h2>
                  <p className="text-gray-600 dark:text-gray-400">You don't have permission to manage shoe pricing.</p>
                </div>
              </div>
            );
          }
          return <ShoePriceApproval />;
        case "payslip-approvals":
          // payroll / payslip view permissions
          if (!hasAnyPermission(auth, ['view-payroll', 'approve-payroll'])) {
            return (
              <div className="flex items-center justify-center h-96">
                <div className="text-center">
                  <div className="text-red-500 text-6xl mb-4">🚫</div>
                  <h2 className="text-2xl font-bold text-gray-800 dark:text-gray-200 mb-2">Access Denied</h2>
                  <p className="text-gray-600 dark:text-gray-400">You don't have permission to view payslip approvals.</p>
                </div>
              </div>
            );
          }
          return <PayslipApproval />;
          
        default:
          return <Invoice />;
      }
    } catch (e: any) {
      setError(e?.message || "An unexpected error occurred. Please try again later.");
      return null;
    }
  };

  return (
    <AppLayoutERP>
      <Head title={headTitle} />
      {error && (
        <ErrorModal message={error} onClose={() => setError(null)} />
      )}
      <div className="w-full">{renderContent()}</div>
    </AppLayoutERP>
  );
}
