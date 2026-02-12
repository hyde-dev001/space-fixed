import { useMemo, useState } from "react";
import { Head, usePage } from "@inertiajs/react";
import AppLayoutERP from "../../../layout/AppLayout_ERP";
import { Dashboard, EmployeeDirectory, LeaveApprovals, OvertimeApprovals, AttendanceRecords, GenerateSlip, ViewSlip } from "./index";
import ErrorModal from "../../../components/common/ErrorModal";

type Section = "dashboard" | "employees" | "attendance" | "leaves" | "overtime" | "payroll-generate" | "payroll-view";

const Placeholder: React.FC<{ title: string; description?: string }> = ({ title, description }) => (
  <div className="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl p-8 shadow-sm">
    <h2 className="text-xl font-semibold text-gray-900 dark:text-white mb-2">{title}</h2>
    <p className="text-gray-600 dark:text-gray-400">{description || "This section is coming soon."}</p>
  </div>
);

export default function HRPage() {
  const [error, setError] = useState<string | null>(null);
  const { auth, url } = usePage().props as any;
  const userRole = auth?.user?.role;
  const permissions = auth?.permissions || [];

  // Check if user has any HR permissions
  const hasHRAccess = () => {
    const hrPermissions = [
      'view-employees', 'create-employees', 'edit-employees', 'delete-employees',
      'view-attendance', 'mark-attendance', 'edit-attendance',
      'view-leave-requests', 'approve-leave-requests', 'manage-leave-requests',
      'view-payroll', 'generate-payroll', 'edit-payroll', 'approve-payroll'
    ];
    const hasAccess = userRole === 'Manager' || hrPermissions.some(perm => permissions.includes(perm));
    
    // Debug: Log user info (remove this after testing)
    console.log('HR Access Debug:', {
      userRole,
      permissions,
      hasAccess,
      matchingPermissions: hrPermissions.filter(perm => permissions.includes(perm))
    });
    
    return hasAccess;
  };

  const section: Section = useMemo(() => {
    // Extract section from the full URL including query parameters
    const fullUrl = typeof window !== 'undefined' ? window.location.href : url || "";
    const urlObj = new URL(fullUrl, 'http://localhost');
    const value = urlObj.searchParams.get("section") || "dashboard";
    if (["dashboard", "employees", "attendance", "leaves", "overtime", "payroll-generate", "payroll-view"].includes(value)) {
      return value as Section;
    }
    return "dashboard";
  }, [url]);

  const headTitle = useMemo(() => {
    if (section === "employees") return "Employee Directory - Solespace ERP";
    if (section === "attendance") return "Attendance Records - Solespace ERP";
    if (section === "leaves") return "Leave Approvals - Solespace ERP";
    if (section === "overtime") return "Overtime Approvals - Solespace ERP";
    if (section === "payroll-generate") return "Generate Payslip - Solespace ERP";
    if (section === "payroll-view") return "View Payslips - Solespace ERP";
    return "HR Dashboard - Solespace ERP";
  }, [section]);

  const renderContent = () => {
    try {
      switch (section) {
        case "employees":
          return <EmployeeDirectory />;
        case "attendance":
          return <AttendanceRecords />;
        case "leaves":
          return <LeaveApprovals />;
        case "overtime":
          return <OvertimeApprovals />;
        case "payroll-generate":
          return <GenerateSlip />;
        case "payroll-view":
          return <ViewSlip />;
        default:
          return <Dashboard />;
      }
    } catch (e: any) {
      setError(e?.message || "An unexpected error occurred. Please try again later.");
      return null;
    }
  };

  if (!hasHRAccess()) {
    return (
      <AppLayoutERP>
        <div className="max-w-xl mx-auto mt-24 text-center p-8 bg-white dark:bg-gray-900 rounded-xl shadow">
          <h2 className="text-2xl font-bold mb-2 text-red-600">Access Denied</h2>
          <p className="text-gray-700 dark:text-gray-300">You do not have permission to view the HR module.</p>
        </div>
      </AppLayoutERP>
    );
  }

  return (
    <AppLayoutERP>
      <Head title={headTitle} />
      {error && (
        <ErrorModal message={error} onClose={() => setError(null)} />
      )}
      <div className="w-full">
        {renderContent()}
      </div>
    </AppLayoutERP>
  );
}
