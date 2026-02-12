import { useCallback, useEffect, useRef, useState } from "react";
import { Link, usePage } from "@inertiajs/react";
import { route } from "ziggy-js";

// Assume these icons are imported from an icon library
import {
  CheckLineIcon,
  ChevronDownIcon,
  HorizontaLDots,
  CurrencyDollarIcon,
} from "../icons";
import { useSidebar } from "../context/SidebarContext";

type NavItem = {
  name: string;
  icon: React.ReactNode;
  route?: string; // Changed from path to route
  params?: Record<string, any>;
  extraPaths?: string[];
  subItems?: { name: string; route: string; params?: Record<string, any>; icon?: React.ReactNode; pro?: boolean; new?: boolean }[];
};

const attendanceItem: NavItem = {
  icon: (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
      <path strokeLinecap="round" strokeLinejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
    </svg>
  ),
  name: "Log Attendance",
  route: "erp.time-in",
};

const navItems: NavItem[] = [
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M21 12c0-4.97-4.03-9-9-9S3 7.03 3 12"></path>
        <path d="M3 12h18"></path>
        <path d="M12 3v9l4 2"></path>
      </svg>
    ),
    name: "Dashboard",
    route: "erp.hr",
    params: { section: "overview" },
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
        <circle cx="9" cy="7" r="4"></circle>
        <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
        <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
      </svg>
    ),
    name: "Employees",
    route: "erp.hr",
    params: { section: "employees" },
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path>
        <rect x="8" y="2" width="8" height="4"></rect>
        <path d="M9 14h6"></path>
        <path d="M9 10h6"></path>
      </svg>
    ),
    name: "Attendance Monitoring",
    subItems: [
      {
        name: "View Attendance",
        route: "erp.hr",
        params: { section: "attendance" },
        icon: (
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
            <polyline points="9 22 9 12 15 12 15 22"></polyline>
          </svg>
        ),
        pro: false,
      },
      {
        name: "Leave Requests",
        route: "erp.hr",
        params: { section: "leaves" },
        icon: (
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm3.5-9c.83 0 1.5-.67 1.5-1.5S16.33 8 15.5 8 14 8.67 14 9.5s.67 1.5 1.5 1.5zm-7 0c.83 0 1.5-.67 1.5-1.5S9.33 8 8.5 8 7 8.67 7 9.5 7.67 11 8.5 11zm3.5 6.5c2.33 0 4.31-1.46 5.11-3.5H6.89c.8 2.04 2.78 3.5 5.11 3.5z"></path>
          </svg>
        ),
        pro: false,
      },
      {
        name: "Overtime Requests",
        route: "erp.hr",
        params: { section: "overtime" },
        icon: (
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
            <path strokeLinecap="round" strokeLinejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
        ),
        pro: false,
      },
    ],
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <circle cx="12" cy="12" r="1"></circle>
        <path d="M12 1v6m0 6v6"></path>
        <path d="M4.22 4.22l4.24 4.24m5.08 0l4.24-4.24"></path>
        <path d="M1 12h6m6 0h6"></path>
        <path d="M4.22 19.78l4.24-4.24m5.08 0l4.24 4.24"></path>
      </svg>
    ),
    name: "Payroll",
    subItems: [
      {
        name: "View Slip",
        route: "erp.hr",
        params: { section: "payroll-view" },
        icon: (
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
            <path d="M6 4h11a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"></path>
            <path d="M8 2v4"></path>
            <path d="M12 11h4"></path>
            <path d="M8 15h8"></path>
          </svg>
        ),
      },
      {
        name: "Generate Slip",
        route: "erp.hr",
        params: { section: "payroll-generate" },
        icon: (
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
            <path d="M4 4h16v6H4z"></path>
            <path d="M4 14h16v6H4z"></path>
            <path d="M9 8h6"></path>
            <path d="M9 18h6"></path>
          </svg>
        ),
      },
    ],
  },
];

const financeItems: NavItem[] = [
  // REMOVED: Enterprise features not needed for SMEs
  // Chart of Accounts - System auto-creates accounts
  // Journal Entries - Invoices/expenses auto-post behind the scenes
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
      </svg>
    ),
    name: "Dashboard",
    route: "finance.dashboard",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
        <polyline points="14 2 14 8 20 8"></polyline>
        <line x1="16" y1="13" x2="8" y2="13"></line>
        <line x1="16" y1="17" x2="8" y2="17"></line>
        <polyline points="10 9 9 9 8 9"></polyline>
      </svg>
    ),
    name: "Invoices",
    route: "finance.index",
    params: { section: "invoice-generation" },
    extraPaths: ["/create-invoice"],
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path>
      </svg>
    ),
    name: "Pricing Approvals",
    subItems: [
      {
        name: "Repair Pricing",
        route: "finance.index",
        params: { section: "repair-pricing" },
        icon: (
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
            <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"></path>
          </svg>
        ),
      },
      {
        name: "Shoe Pricing",
        route: "finance.index",
        params: { section: "shoe-pricing" },
        icon: (
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
            <path d="M2 17s.5-3.5 4-3.5 4 3.5 4 3.5m6 0s.5-3.5 4-3.5 4 3.5 4 3.5M2 17h20v4H2z"></path>
          </svg>
        ),
      },
    ],
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <circle cx="12" cy="12" r="10"></circle>
        <path d="M12 6v6l4 2"></path>
      </svg>
    ),
    name: "Expenses",
    route: "finance.index",
    params: { section: "expense-tracking" },
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M21 8V7a2 2 0 0 0-2-2h-4V3H9v2H5a2 2 0 0 0-2 2v1"></path>
        <rect x="3" y="8" width="18" height="13" rx="2"></rect>
        <path d="M16 3v4"></path>
        <path d="M8 3v4"></path>
      </svg>
    ),
    name: "Payslip Approvals",
    route: "finance.index",
    params: { section: "payslip-approvals" },
    extraPaths: ["/finance?payslip-approvals", "/finance?section=payslip-approvals"],
  },
  // REMOVED: Enterprise features not needed for SMEs
  // Financial Reporting - Data shown in Dashboard
  // Budget Analysis - Too complex for SMEs
  // Bank Reconciliation - Rarely used by SMEs
  // Recurring Transactions - Manual entry is simpler
  // Cost Centers - Enterprise allocation feature
  // Approval Workflow removed
];

const crmItems: NavItem[] = [
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20z"></path>
      </svg>
    ),
    name: "CRM Dashboard",
    route: "crm.dashboard",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
        <circle cx="12" cy="7" r="4"></circle>
      </svg>
    ),
    name: "Opportunities",
    route: "crm.opportunities",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M21 10a8 8 0 1 0-18 0"></path>
        <circle cx="12" cy="14" r="3"></circle>
      </svg>
    ),
    name: "Leads",
    route: "crm.leads",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <rect x="3" y="4" width="18" height="16" rx="2"></rect>
        <path d="M16 2v4"></path>
        <path d="M8 2v4"></path>
      </svg>
    ),
    name: "Customers",
    route: "crm.customers",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M21 21H3a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h18"></path>
        <path d="M23 7l-8.97 5.7a1.94 1.94 0 0 1-2.06 0L1 7"></path>
      </svg>
    ),
    name: "Customer Support",
    route: "crm.customer-support",
  },
];

const scmItems: NavItem[] = [];

const othersItems: NavItem[] = [];

const managerItems: NavItem[] = [
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M21 16V8l-9-5-9 5v8l9 5 9-5z"></path>
        <path d="M12 3v13"></path>
        <path d="M3 8l9 5 9-5"></path>
      </svg>
    ),
    name: "Manager Dashboard",
    route: "erp.manager.dashboard",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M21 16V8a2 2 0 0 0-1-1.73L12 3 4 6.27A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73L12 21l8-4.27A2 2 0 0 0 21 16z"></path>
        <path d="M12 12v9"></path>
      </svg>
    ),
    name: "Inventory Overview",
    route: "erp.manager.inventory-overview",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path>
        <line x1="7" y1="7" x2="7.01" y2="7"></line>
      </svg>
    ),
    name: "Reports",
    route: "erp.manager.reports",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
      </svg>
    ),
    name: "Audit Logs",
    route: "erp.manager.audit-logs",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
      </svg>
    ),
    name: "Suspend Approval",
    route: "erp.manager.suspend-approval",
  },
];

const staffItems: NavItem[] = [
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M3 11l9-7 9 7"></path>
        <path d="M5 11v8a2 2 0 002 2h10a2 2 0 002-2v-8"></path>
        <path d="M9 21v-6h6v6"></path>
      </svg>
    ),
    name: "Staff Dashboard",
    route: "erp.staff.dashboard",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" strokeLinejoin="round" xmlns="http://www.w3.org/2000/svg">
        <path d="M6 6h15l-1.5 9h-12z" />
        <circle cx="9" cy="19" r="1.5" />
        <circle cx="18" cy="19" r="1.5" />
        <path d="M6 6L4 2" />
      </svg>
    ),
    name: "Job Orders Retail",
    route: "erp.staff.job-orders",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" strokeLinejoin="round" xmlns="http://www.w3.org/2000/svg">
        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
        <circle cx="12" cy="12" r="3"></circle>
      </svg>
    ),
    name: "Product",
    route: "erp.staff.products",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path>
        <line x1="7" y1="7" x2="7.01" y2="7"></line>
      </svg>
    ),
    name: "Shoe Pricing",
    route: "erp.staff.shoe-pricing",
  },
];

const repairItems: NavItem[] = [
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" strokeLinejoin="round" xmlns="http://www.w3.org/2000/svg">
        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
        <circle cx="12" cy="12" r="3"></circle>
      </svg>
    ),
    name: "Job Orders Repair",
    route: "erp.staff.job-orders-repair",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path strokeLinecap="round" strokeLinejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
      </svg>
    ),
    name: "Upload Services",
    route: "erp.staff.upload-services",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path>
        <line x1="7" y1="7" x2="7.01" y2="7"></line>
      </svg>
    ),
    name: "Repair Pricing",
    route: "erp.manager.pricing-services",
  },
];

const AppSidebar_ERP: React.FC = () => {
  const { isExpanded, isMobileOpen, isHovered, setIsHovered, openSubmenu, toggleSubmenu } = useSidebar();
  const { url, props } = usePage();
  const role = (props as any)?.auth?.user?.role;
  const permissions = (props as any)?.auth?.permissions || [];

  const [subMenuHeight, setSubMenuHeight] = useState<Record<string, number>>({});
  const subMenuRefs = useRef<Record<string, HTMLDivElement | null>>({});
  const sidebarScrollRef = useRef<HTMLDivElement | null>(null);

  // Initialize with collapsed menus
  useEffect(() => {
    // Clear any previously stored open submenu on initial load
    if (typeof window !== 'undefined') {
      const stored = localStorage.getItem('sidebarOpenSubmenu');
      if (stored) {
        localStorage.removeItem('sidebarOpenSubmenu');
        // Don't toggle, just let it stay closed
      }
    }
  }, []);

  // Save scroll position on scroll - using sessionStorage for persistence
  useEffect(() => {
    const scrollContainer = sidebarScrollRef.current;
    if (!scrollContainer) return;

    const handleScroll = () => {
      sessionStorage.setItem('sidebarScrollPosition', scrollContainer.scrollTop.toString());
    };

    scrollContainer.addEventListener('scroll', handleScroll, { passive: true });
    return () => scrollContainer.removeEventListener('scroll', handleScroll);
  }, []);

  // Restore scroll position after navigation
  useEffect(() => {
    const scrollContainer = sidebarScrollRef.current;
    if (!scrollContainer) return;

    const savedPosition = sessionStorage.getItem('sidebarScrollPosition');
    if (savedPosition) {
      const scrollTop = parseInt(savedPosition, 10);
      
      // Use multiple RAF and setTimeout to ensure DOM is fully rendered
      requestAnimationFrame(() => {
        requestAnimationFrame(() => {
          setTimeout(() => {
            if (scrollContainer) {
              scrollContainer.scrollTop = scrollTop;
            }
          }, 50);
        });
      });
    }
  }, [url]);

  // Map staff route names to static frontend paths (fallback when Ziggy route names are not available)
  const staffRouteMap: Record<string, string> = {
    "erp.staff.dashboard": "/erp/staff/dashboard",
    "erp.staff.job-orders": "/erp/staff/job-orders",
    "erp.staff.job-orders-repair": "/erp/staff/job-orders-repair",
    "erp.staff.upload-services": "/erp/staff/upload-services",
    "erp.staff.repair-status": "/erp/staff/repair-status",
    "erp.staff.products": "/erp/staff/products",
    "erp.staff.shoe-pricing": "/erp/staff/shoe-pricing",
    "erp.staff.attendance": "/erp/staff/attendance",
    "erp.staff.customers": "/erp/staff/customers",
    "erp.time-in": "/erp/time-in",
  };

  // Map all route names to their paths for comprehensive active state matching
  const allRoutePaths: Record<string, string> = {
    // Time & Attendance
    "erp.time-in": "/erp/time-in",
    // HR section routes
    "erp.hr": "/erp/hr",
    "erp.hr.audit-logs": "/erp/hr/audit-logs",
    // Finance section routes
    "finance.index": "/finance",
    "finance.dashboard": "/finance/dashboard",
    "finance.create-invoice": "/create-invoice",
    "erp.finance.audit-logs": "/erp/finance/audit-logs",
    // CRM section routes
    "crm.dashboard": "/crm",
    "crm.opportunities": "/crm/opportunities",
    "crm.leads": "/crm/leads",
    "crm.customers": "/crm/customers",
    "crm.customer-support": "/crm/customer-support",
    // Manager section routes
    "erp.manager.dashboard": "/erp/manager/dashboard",
    "erp.manager.reports": "/erp/manager/reports",
    "erp.manager.pricing-services": "/erp/manager/pricing-and-services",
    "erp.manager.shoe-pricing": "/erp/manager/shoe-pricing",
    "erp.manager.products": "/erp/manager/products",
    "erp.manager.inventory-overview": "/erp/manager/inventory-overview",
    "erp.manager.user-management": "/erp/manager/user-management",
    "erp.manager.audit-logs": "/erp/manager/audit-logs",
    "erp.manager.suspend-approval": "/erp/manager/suspend-approval",
    // Staff section routes
    "erp.staff.dashboard": "/erp/staff/dashboard",
    "erp.staff.job-orders": "/erp/staff/job-orders",
    "erp.staff.job-orders-repair": "/erp/staff/job-orders-repair",
    "erp.staff.upload-services": "/erp/staff/upload-services",
    "erp.staff.repair-status": "/erp/staff/repair-status",
    "erp.staff.products": "/erp/staff/products",
    "erp.staff.shoe-pricing": "/erp/staff/shoe-pricing",
    "erp.staff.attendance": "/erp/staff/attendance",
    "erp.staff.customers": "/erp/staff/customers",
  };

  const isActive = useCallback(
    (routeName: string, params?: Record<string, any>, extraPaths?: string[]) => {
      try {
        // Get the base URL without query string
        const urlParts = url.split('?');
        const baseUrl = urlParts[0];
        const queryString = urlParts[1] || '';

        // Frontend-only staff routes: compare against mapped static paths
        if (routeName && routeName.startsWith && routeName.startsWith("erp.staff.")) {
          const staffPath = staffRouteMap[routeName] || "";
          if (!staffPath) return false;
          // Exact match first
          if (baseUrl === staffPath) return true;
          // For partial matches, ensure we don't match prefixes (e.g., /job-orders shouldn't match /job-orders-repair)
          if (baseUrl.startsWith(staffPath + "/")) return true;
          if (extraPaths && extraPaths.some((path) => baseUrl.startsWith(path))) return true;
          return false;
        }

        // Try using allRoutePaths map first
        if (allRoutePaths[routeName]) {
          const mappedPath = allRoutePaths[routeName];
          // Exact match for base URL
          if (baseUrl === mappedPath) {
            // If item has a section parameter, it must match the query string
            if (params?.section) {
              return queryString.includes(`section=${params.section}`);
            }
            // If no section parameter, match if there's no query string or any query string on this route
            // This handles both direct navigation and initial page loads
            return true;
          }
          
          // Prefix match only for deep nested routes (e.g., /erp/manager/dashboard)
          // Don't prefix match for shallow routes like /crm to avoid matching /crm/leads with /crm
          const isDeepNestedPath = mappedPath.split('/').filter(Boolean).length >= 2;
          if (isDeepNestedPath && baseUrl.startsWith(mappedPath + "/")) return true;
        }

        // Fallback: compare resolved route URL to current page URL
        try {
          const routeUrl = route(routeName, params || undefined);
          // Remove query string from both URLs for comparison
          const routeUrlBase = routeUrl.split('?')[0];
          
          if (baseUrl === routeUrlBase) {
            // If route has a query string but we don't, try matching with params
            if (params?.section && !queryString && routeUrl.includes(`?`)) {
              const routeQueryPart = routeUrl.split('?')[1] || '';
              if (routeQueryPart.includes(`section=${params.section}`)) return true;
            }
            return true;
          }
          
          // Only do prefix matching for deep nested routes to avoid false positives
          const isDeepNestedPath = routeUrlBase.split('/').filter(Boolean).length >= 2;
          if (isDeepNestedPath && baseUrl.startsWith(routeUrlBase + "/")) return true;
          
          // Special handling for routes with query parameters
          if (routeUrl.includes('?')) {
            // If the route() helper generates a URL with query string, compare them
            if (url === routeUrl) return true;
          }
        } catch {
          // ignore route errors
        }

        if (extraPaths && extraPaths.some((path) => baseUrl.startsWith(path))) return true;
        return false;
      } catch {
        return false;
      }
    },
    [url]
  );

  const getHrefByRoute = (routeName?: string, params?: Record<string, any>) => {
    if (!routeName) return "#";
    
    // First check if it's in our route paths map
    if (allRoutePaths[routeName]) {
      let url = allRoutePaths[routeName];
      // Add query parameters if they exist
      if (params && Object.keys(params).length > 0) {
        const queryParams = new URLSearchParams(params).toString();
        url += `?${queryParams}`;
      }
      return url;
    }
    
    // Fallback to staff route map for legacy compatibility
    if (routeName.startsWith && routeName.startsWith("erp.staff.")) {
      return staffRouteMap[routeName] || "#";
    }
    
    // Last resort: try to use route() helper
    try {
      let url = route(routeName, params || undefined);
      // If params exist but weren't processed by route(), add them manually
      if (params && Object.keys(params).length > 0 && !url.includes('?')) {
        const queryParams = new URLSearchParams(params).toString();
        url += `?${queryParams}`;
      }
      return url;
    } catch {
      return "#";
    }
  };

  const isMenuActive = useCallback(
    (nav: NavItem) => {
      if (nav.route && isActive(nav.route, nav.params, nav.extraPaths)) return true;
      if (nav.subItems) {
        return nav.subItems.some(sub => isActive(sub.route, sub.params));
      }
      return false;
    },
    [isActive]
  );

  useEffect(() => {
    // Don't auto-expand menus on page load - let users manually toggle
    // This prevents the menu from staying expanded when using localStorage
  }, [url, isActive, openSubmenu, toggleSubmenu]);

  useEffect(() => {
    if (openSubmenu !== null) {
      const key = openSubmenu;
      if (subMenuRefs.current[key]) {
        setSubMenuHeight((prevHeights) => ({
          ...prevHeights,
          [key]: subMenuRefs.current[key]?.scrollHeight || 0,
        }));
      }
    }
  }, [openSubmenu]);

  const handleSubmenuToggle = (index: number, menuType: "attendance" | "staff" | "repair" | "manager" | "hr" | "finance" | "crm" | "main" | "others") => {
    const key = `${menuType}-${index}`;
    toggleSubmenu(key);
  };

  // Filter finance items based on user permissions
  const getFilteredFinanceItems = () => {
    return financeItems.filter((item) => {
      // Dashboard - show if user has any finance permission
      if (item.route === "finance.dashboard") {
        return hasFinanceAccess();
      }
      
      // Invoices - requires invoice permissions
      if (item.route === "finance.index" && item.params?.section === "invoice-generation") {
        return permissions.includes('view-invoices') || permissions.includes('create-invoices') || 
               permissions.includes('edit-invoices') || permissions.includes('delete-invoices') ||
               permissions.includes('send-invoices');
      }
      
      // Expenses - requires expense permissions
      if (item.route === "finance.index" && item.params?.section === "expense-tracking") {
        return permissions.includes('view-expenses') || permissions.includes('create-expenses') || 
               permissions.includes('edit-expenses') || permissions.includes('delete-expenses') ||
               permissions.includes('approve-expenses');
      }
      
      // Pricing Approvals - requires pricing permissions (view/edit-pricing or approve-expenses)
      if (item.name === "Pricing Approvals") {
        return permissions.includes('view-pricing') || permissions.includes('edit-pricing') || 
               permissions.includes('manage-service-pricing') || permissions.includes('approve-expenses');
      }
      
      // Show other items by default
      return true;
    });
  };

  // Check if user has any finance permissions
  const hasFinanceAccess = () => {
    const financePermissions = [
      'view-expenses', 'create-expenses', 'edit-expenses', 'delete-expenses', 'approve-expenses',
      'view-invoices', 'create-invoices', 'edit-invoices', 'delete-invoices', 'send-invoices'
    ];
    return financePermissions.some(perm => permissions.includes(perm));
  };

  // Check if user has any HR permissions
  const hasHRAccess = () => {
    const hrPermissions = [
      'view-employees', 'create-employees', 'edit-employees', 'delete-employees',
      'view-attendance', 'mark-attendance', 'edit-attendance',
      'view-leave-requests', 'approve-leave-requests', 'manage-leave-requests',
      'generate-payroll', 'edit-payroll'
    ];
    return hrPermissions.some(perm => permissions.includes(perm));
  };

  // Check if user has any CRM permissions
  const hasCRMAccess = () => {
    const crmPermissions = [
      'view-crm-dashboard', 'view-opportunities', 'create-opportunities', 'edit-opportunities',
      'view-leads', 'create-leads', 'edit-leads', 'delete-leads',
      'view-customers', 'create-customers', 'edit-customers', 'delete-customers'
    ];
    return crmPermissions.some(perm => permissions.includes(perm));
  };

  // Filter staff items based on user permissions
  const getFilteredStaffItems = () => {
    return staffItems.filter((item) => {
      // Dashboard - always show
      if (item.route === "erp.staff.dashboard") {
        return true;
      }
      
      // Job Orders - requires job order permissions
      if (item.route === "erp.staff.job-orders") {
        return permissions.includes('view-job-orders') || permissions.includes('create-job-orders') || 
               permissions.includes('edit-job-orders') || permissions.includes('complete-job-orders');
      }
      
      // Products - requires product permissions
      if (item.route === "erp.staff.products") {
        return permissions.includes('view-products') || permissions.includes('create-products') || 
               permissions.includes('edit-products') || permissions.includes('delete-products');
      }
      
      // Shoe Pricing - requires pricing permissions
      if (item.route === "erp.staff.shoe-pricing") {
        return permissions.includes('view-pricing') || permissions.includes('edit-pricing');
      }
      
      // Hide other items by default (no permissions)
      return false;
    });
  };

  // Filter repair items based on user permissions
  const getFilteredRepairItems = () => {
    return repairItems.filter((item) => {
      // Job Orders Repair - requires job order permissions
      if (item.route === "erp.staff.job-orders-repair") {
        return permissions.includes('view-job-orders') || permissions.includes('create-job-orders') || 
               permissions.includes('edit-job-orders') || permissions.includes('complete-job-orders');
      }
      
      // Upload Services - accessible to all staff and managers
      if (item.route === "erp.staff.upload-services") {
        return true;
      }
      
      // Repair Pricing - requires pricing or service pricing permissions
      if (item.route === "erp.manager.pricing-services") {
        return permissions.includes('view-pricing') || permissions.includes('edit-pricing') || 
               permissions.includes('manage-service-pricing');
      }
      
      // Hide other items by default (no permissions)
      return false;
    });
  }

  const renderMenuItems = (items: NavItem[], menuType: "attendance" | "staff" | "repair" | "manager" | "hr" | "finance" | "crm" | "main" | "others") => {
    return (
      <ul className="flex flex-col gap-4">
        {items.map((nav, index) => {
          const subItems = nav.subItems?.filter((s) => s.name !== "Create Admin") || nav.subItems;
          if (nav.subItems && (!subItems || subItems.length === 0)) {
            return null;
          }

          return (
            <li key={nav.name}>
              {subItems ? (
                <button
                  onClick={() => handleSubmenuToggle(index, menuType)}
                  className={`menu-item group ${
                    isMenuActive(nav) || openSubmenu === `${menuType}-${index}`
                      ? "menu-item-active"
                      : "menu-item-inactive"
                  } cursor-pointer ${
                    !isExpanded && !isHovered
                      ? "lg:justify-center"
                      : "lg:justify-start"
                  }`}
                >
                  <span
                    className={`menu-item-icon-size w-6 h-6 ${
                      isMenuActive(nav) || openSubmenu === `${menuType}-${index}`
                        ? "menu-item-icon-active"
                        : "menu-item-icon-inactive"
                    }`}
                  >
                    {nav.icon}
                  </span>
                  {(isExpanded || isHovered || isMobileOpen) && (
                    <span className="menu-item-text">{nav.name}</span>
                  )}
                  {(isExpanded || isHovered || isMobileOpen) && (
                    <ChevronDownIcon
                      className={`ml-auto w-5 h-5 transition-transform duration-200 ${
                        openSubmenu === `${menuType}-${index}`
                          ? "rotate-180"
                          : ""
                      }`}
                    />
                  )}
                </button>
              ) : (
                nav.route && (
                  <Link
                    href={getHrefByRoute(nav.route, nav.params || undefined)}
                    className={`menu-item group ${
                      isActive(nav.route, nav.params, nav.extraPaths) ? "menu-item-active" : "menu-item-inactive"
                    }`}
                  >
                    <span
                      className={`menu-item-icon-size w-6 h-6 ${
                        isActive(nav.route, nav.params, nav.extraPaths)
                          ? "menu-item-icon-active"
                          : "menu-item-icon-inactive"
                      }`}
                    >
                      {nav.icon}
                    </span>
                    {(isExpanded || isHovered || isMobileOpen) && (
                      <span className="menu-item-text">{nav.name}</span>
                    )}
                  </Link>
                )
              )}

              {subItems && (isExpanded || isHovered || isMobileOpen) && (
                <div
                  ref={(el) => {
                    subMenuRefs.current[`${menuType}-${index}`] = el;
                  }}
                  className="overflow-hidden transition-all duration-300"
                  style={{
                    height:
                      openSubmenu === `${menuType}-${index}`
                        ? `${subMenuHeight[`${menuType}-${index}`]}px`
                        : "0px",
                  }}
                >
                  <ul className="mt-2 space-y-1 ml-9">
                    {subItems.map((subItem, subIndex) => (
                      <li key={`${subItem.route}-${subIndex}`}>
                        <Link
                          href={getHrefByRoute(subItem.route, subItem.params || undefined)}
                          className={`menu-dropdown-item ${
                            isActive(subItem.route, subItem.params)
                              ? "menu-dropdown-item-active"
                              : "menu-dropdown-item-inactive"
                          }`}
                        >
                          {subItem.name}
                          <span className="flex items-center gap-1 ml-auto">
                            {isActive(subItem.route) && (
                              <CheckLineIcon className="w-4 h-4 text-green-500" />
                            )}
                            {subItem.new && (
                              <span
                                className={`${
                                  isActive(subItem.route)
                                    ? "menu-dropdown-badge-active"
                                    : "menu-dropdown-badge-inactive"
                                } menu-dropdown-badge`}
                              >
                                new
                              </span>
                            )}
                            {subItem.pro && (
                              <span
                                className={`${
                                  isActive(subItem.route)
                                    ? "menu-dropdown-badge-active"
                                    : "menu-dropdown-badge-inactive"
                                } menu-dropdown-badge`}
                              >
                                pro
                              </span>
                            )}
                          </span>
                        </Link>
                      </li>
                    ))}
                  </ul>
                </div>
              )}
            </li>
          );
        })}
      </ul>
    );
  };

  return (
    <aside
      className={`fixed mt-16 flex flex-col lg:mt-0 top-0 px-5 left-0 bg-white dark:bg-gray-900 dark:border-gray-800 text-gray-900 h-screen transition-all duration-300 ease-in-out z-50 border-r border-gray-200 
        ${
          isExpanded || isMobileOpen
            ? "w-[290px]"
            : isHovered
            ? "w-[290px]"
            : "w-[90px]"
        }
        ${isMobileOpen ? "translate-x-0" : "-translate-x-full"}
        lg:translate-x-0`}
      onMouseEnter={() => !isExpanded && setIsHovered(true)}
      onMouseLeave={() => setIsHovered(false)}
    >
      <div
        className={`py-8 flex ${
          !isExpanded && !isHovered ? "lg:justify-center" : "justify-start"
        }`}
      >
        <Link href={route("landing")} className="flex items-center gap-2 hover:scale-105 transition-transform duration-200">
          {isExpanded || isHovered || isMobileOpen ? (
            <span className="text-xl font-bold bg-gradient-to-r from-blue-600 to-purple-600 bg-clip-text text-transparent">
              SoleSpace
            </span>
          ) : (
            <span className="text-lg font-bold bg-gradient-to-r from-blue-600 to-purple-600 bg-clip-text text-transparent">SS</span>
          )}
        </Link>
      </div>
      <div 
        ref={sidebarScrollRef}
        className="flex flex-col overflow-y-auto duration-300 ease-linear no-scrollbar"
      >
        {/* Standalone Attendance - Requires view-attendance permission */}
        {permissions.includes('view-attendance') && (
          <nav className="mb-6">
            <div className="flex flex-col gap-4">
              {renderMenuItems([attendanceItem], "attendance")}
            </div>
          </nav>
        )}

        {(role === "STAFF" || role === "MANAGER") && (
          <nav className="mb-6">
            <div className="flex flex-col gap-4">
              <div>
                <h2
                  className={`mb-4 text-xs uppercase flex leading-[20px] text-gray-400 ${
                    !isExpanded && !isHovered
                      ? "lg:justify-center"
                      : "justify-start"
                  }`}
                >
                  {isExpanded || isHovered || isMobileOpen ? (
                    "STAFF"
                  ) : (
                    <HorizontaLDots className="size-6" />
                  )}
                </h2>
                {renderMenuItems(getFilteredStaffItems(), "staff")}
              </div>
            </div>
          </nav>
        )}
        {(role === "STAFF" || role === "MANAGER") && (
          <nav className="mb-6">
            <div className="flex flex-col gap-4">
              <div>
                <h2
                  className={`mb-4 text-xs uppercase flex leading-[20px] text-gray-400 ${
                    !isExpanded && !isHovered
                      ? "lg:justify-center"
                      : "justify-start"
                  }`}
                >
                  {isExpanded || isHovered || isMobileOpen ? (
                    "REPAIR"
                  ) : (
                    <HorizontaLDots className="size-6" />
                  )}
                </h2>
                {renderMenuItems(
                  role === "MANAGER"
                    ? getFilteredRepairItems()
                    : getFilteredRepairItems().filter((item) => 
                        item.route === "erp.staff.job-orders-repair" || 
                        item.route === "erp.staff.upload-services"
                      ),
                  "repair"
                )}
              </div>
            </div>
          </nav>
        )}
        {role === "MANAGER" && (
          <nav className="mb-6">
            <div className="flex flex-col gap-4">
              <div>
                <h2
                  className={`mb-4 text-xs uppercase flex leading-[20px] text-gray-400 ${
                    !isExpanded && !isHovered
                      ? "lg:justify-center"
                      : "justify-start"
                  }`}
                >
                  {isExpanded || isHovered || isMobileOpen ? (
                    "MANAGER"
                  ) : (
                    <HorizontaLDots className="size-6" />
                  )}
                </h2>
                {renderMenuItems(managerItems, "manager")}
              </div>
            </div>
          </nav>
        )}
        {hasHRAccess() && (
          <nav className="mb-6">
            <div className="flex flex-col gap-4">
              <div>
                <h2
                  className={`mb-4 text-xs uppercase flex leading-[20px] text-gray-400 ${
                    !isExpanded && !isHovered
                      ? "lg:justify-center"
                      : "justify-start"
                  }`}
                >
                  {isExpanded || isHovered || isMobileOpen ? (
                    "HR"
                  ) : (
                    <HorizontaLDots className="size-6" />
                  )}
                </h2>
                {renderMenuItems(navItems, "hr")}
              </div>
            </div>
          </nav>
        )}
        {hasFinanceAccess() && (
          <nav className="mb-6">
            <div className="flex flex-col gap-4">
              <div>
                <h2
                  className={`mb-4 text-xs uppercase flex leading-[20px] text-gray-400 ${
                    !isExpanded && !isHovered
                      ? "lg:justify-center"
                      : "justify-start"
                  }`}
                >
                  {isExpanded || isHovered || isMobileOpen ? (
                    "Finance"
                  ) : (
                    <HorizontaLDots className="size-6" />
                  )}
                </h2>
                {renderMenuItems(getFilteredFinanceItems(), "finance")}
              </div>
            </div>
          </nav>
        )}
        {hasCRMAccess() && (
          <nav className="mb-6">
            <div className="flex flex-col gap-4">
              <div>
                <h2
                  className={`mb-4 text-xs uppercase flex leading-[20px] text-gray-400 ${
                    !isExpanded && !isHovered
                      ? "lg:justify-center"
                      : "justify-start"
                  }`}
                >
                  {isExpanded || isHovered || isMobileOpen ? (
                    "CRM"
                  ) : (
                    <HorizontaLDots className="size-6" />
                  )}
                </h2>
                {renderMenuItems(crmItems, "crm")}
              </div>
            </div>
          </nav>
        )}
        {othersItems.length > 0 && hasFinanceAccess() && (
          <nav className="mb-6">
            <div className="flex flex-col gap-4">
              <div>
                <h2
                  className={`mb-4 text-xs uppercase flex leading-[20px] text-gray-400 ${
                    !isExpanded && !isHovered
                      ? "lg:justify-center"
                      : "justify-start"
                  }`}
                >
                  {isExpanded || isHovered || isMobileOpen ? (
                    "Others"
                  ) : (
                    <HorizontaLDots className="size-6" />
                  )}
                </h2>
                {renderMenuItems(othersItems, "others")}
              </div>
            </div>
          </nav>
        )}
      </div>
    </aside>
  );
};

export default AppSidebar_ERP;
