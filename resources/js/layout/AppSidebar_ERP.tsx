import { useCallback, useEffect, useRef, useState } from "react";
import { Link, usePage } from "@inertiajs/react";
import { route } from "ziggy-js";

// Assume these icons are imported from an icon library
import {
  CheckLineIcon,
  HorizontaLDots,
} from "../icons";
import { useSidebar } from "../context/SidebarContext";
import type { ShopModuleKey } from "../types/shopModules";
import { canRenderShopModule } from "../utils/shopModuleAccess";
import AppSidebar_shopOwner from "./AppSidebar_shopOwner";

type NavItem = {
  name: string;
  icon: React.ReactNode;
  route?: string;
  params?: Record<string, any>;
  extraPaths?: string[];
  moduleKey?: ShopModuleKey;
  subItems?: { name: string; route: string; params?: Record<string, any>; icon?: React.ReactNode; moduleKey?: ShopModuleKey; pro?: boolean; new?: boolean }[];
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

const myPayslipsItem: NavItem = {
  icon: (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" strokeLinejoin="round">
      <path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
    </svg>
  ),
  name: "My Payslips",
  route: "erp.my-payslips",
  moduleKey: "finance",
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
    moduleKey: "hr_employees",
    subItems: [
      {
        name: "View Attendance",
        route: "erp.hr",
        params: { section: "attendance" },
        moduleKey: "hr_employees",
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
        moduleKey: "hr_employees",
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
        moduleKey: "hr_employees",
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
        moduleKey: "finance",
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
        moduleKey: "finance",
        icon: (
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
            <path d="M4 4h16v6H4z"></path>
            <path d="M4 14h16v6H4z"></path>
            <path d="M9 8h6"></path>
            <path d="M9 18h6"></path>
          </svg>
        ),
      },
      {
        name: "Salary Changes",
        route: "erp.hr",
        params: { section: "salary-changes" },
        moduleKey: "finance",
        icon: (
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
            <line x1="12" y1="1" x2="12" y2="23"></line>
            <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
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
    moduleKey: "finance",
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
    moduleKey: "finance",
    params: { section: "invoice-generation" },
    extraPaths: ["/create-invoice"],
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path>
      </svg>
    ),
    name: "Approvals",
    moduleKey: "finance",
    subItems: [
      {
        name: "Repair Pricing Approval",
        route: "finance.index",
        params: { section: "repair-pricing" },
        moduleKey: "finance",
        icon: (
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
            <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"></path>
          </svg>
        ),
      },
      {
        name: "Shoe Pricing Approval",
        route: "finance.index",
        params: { section: "shoe-pricing" },
        moduleKey: "finance",
        icon: (
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
            <path d="M2 17s.5-3.5 4-3.5 4 3.5 4 3.5m6 0s.5-3.5 4-3.5 4 3.5 4 3.5M2 17h20v4H2z"></path>
          </svg>
        ),
      },
      {
        name: "Purchase Request Review",
        route: "finance.index",
        params: { section: "purchase-request-approval" },
        moduleKey: "finance",
        icon: (
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
            <rect x="4" y="4" width="16" height="16" rx="2"></rect>
            <path d="M8 9h8"></path>
            <path d="M8 13h8"></path>
            <path d="M8 17h5"></path>
          </svg>
        ),
      },
      {
        name: "Refund Approval",
        route: "finance.index",
        params: { section: "refund-approvals" },
        moduleKey: "finance",
        extraPaths: ["/finance?refund-approvals", "/finance?section=refund-approvals"],
        icon: (
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
            <path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
          </svg>
        ),
      },
      {
        name: "Payslip Approvals",
        route: "finance.index",
        params: { section: "payslip-approvals" },
        moduleKey: "finance",
        extraPaths: ["/finance?payslip-approvals", "/finance?section=payslip-approvals"],
        icon: (
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
            <path d="M21 8V7a2 2 0 0 0-2-2h-4V3H9v2H5a2 2 0 0 0-2 2v1"></path>
            <rect x="3" y="8" width="18" height="13" rx="2"></rect>
            <path d="M16 3v4"></path>
            <path d="M8 3v4"></path>
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
    moduleKey: "finance",
    params: { section: "expense-tracking" },
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
    moduleKey: "crm",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
        <circle cx="12" cy="7" r="4"></circle>
      </svg>
    ),
    name: "Customers",
    route: "crm.customers",
    moduleKey: "crm",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <rect x="3" y="4" width="18" height="16" rx="2"></rect>
        <path d="M16 2v4"></path>
        <path d="M8 2v4"></path>
      </svg>
    ),
    name: "Customer Support",
    route: "crm.customer-support",
    moduleKey: "crm",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M12 17l-5 3 1.5-5.5L4 10.5l5.6-.5L12 5l2.4 5 5.6.5-4.5 4 1.5 5.5z"></path>
      </svg>
    ),
    name: "Customer Reviews",
    route: "crm.customer-reviews",
    moduleKey: "crm",
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
    moduleKey: "finance",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
      </svg>
    ),
    name: "Repair Rejection Review",
    route: "erp.manager.repair-rejection-review",
    moduleKey: "repair_operations",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <path d="M21 16V8a2 2 0 0 0-1-1.73L12 3 4 6.27A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73L12 21l8-4.27A2 2 0 0 0 21 16z"></path>
        <path d="M12 12v9"></path>
      </svg>
    ),
    name: "Inventory Overview",
    route: "erp.manager.inventory-overview",
    moduleKey: "inventory",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <rect x="2" y="3" width="20" height="14" rx="2"></rect>
        <path d="M8 21h8m-4-4v4"></path>
        <path d="M7 8h.01M11 8h6M7 12h4m2 0h3"></path>
      </svg>
    ),
    name: "Assist Center",
    route: "erp.manager.dss-insights",
  },
];

const managerInventoryItems: NavItem[] = [
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M3 3v18h18"></path>
        <path d="M7 15v-4"></path>
        <path d="M12 15V8"></path>
        <path d="M17 15v-6"></path>
      </svg>
    ),
    name: "Inventory Dashboard",
    route: "erp.inventory.inventory-dashboard",
    moduleKey: "inventory",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path strokeLinecap="round" strokeLinejoin="round" d="M12 16V4m0 0l-4 4m4-4l4 4" />
        <path strokeLinecap="round" strokeLinejoin="round" d="M20 16.58A5 5 0 0018 7h-1.26A8 8 0 104 16.25" />
        <path strokeLinecap="round" strokeLinejoin="round" d="M8 16l4 4 4-4" />
      </svg>
    ),
    name: "Manage Stock Items",
    route: "erp.inventory.upload-stocks",
    moduleKey: "inventory",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path strokeLinecap="round" strokeLinejoin="round" d="M3 3v18h18" />
        <path strokeLinecap="round" strokeLinejoin="round" d="M7 16l3-3 3 2 4-5" />
        <path strokeLinecap="round" strokeLinejoin="round" d="M16 10h4" />
      </svg>
    ),
    name: "Stock Movement",
    route: "erp.inventory.stock-movement",
    moduleKey: "inventory",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
    <path d="M4 6a2 2 0 0 1 2-2h8l4 4v10a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z"></path>
    <path d="M14 4v4h4"></path>
    <path d="M8 12h6"></path>
    <path d="M11 9v6"></path>
      </svg>
    ),
    name: "Stock Requests",
    route: "erp.inventory.stock-request",
    moduleKey: "inventory",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M9 12l2 2 4-4"></path>
        <path d="M21 12c0 4.97-4.03 9-9 9s-9-4.03-9-9 4.03-9 9-9"></path>
        <path d="M17 3h4v4"></path>
        <path d="M21 3l-7 7"></path>
      </svg>
    ),
    name: "Material Request Queue",
    route: "erp.inventory.request-material-approval",
    moduleKey: "inventory",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <rect x="3" y="5" width="12" height="14" rx="2"></rect>
        <path d="M7 9h4"></path>
        <path d="M7 13h4"></path>
        <circle cx="18" cy="10" r="3"></circle>
        <path d="M18 8.5v1.8l1.2.7"></path>
        <path d="M16 18h5"></path>
      </svg>
    ),
    name: "Supplier Orders",
    route: "erp.inventory.supplier-order-monitoring",
    moduleKey: "inventory",
  },
];

const procurementItems: NavItem[] = [
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
    <path d="M5 4h10l4 4v12H5z"></path>
    <path d="M15 4v4h4"></path>
    <path d="M8 13l5-5 2 2-5 5-3 1z"></path>
      </svg>
    ),
    name: "Purchase Requests",
    route: "erp.procurement.purchase-request",
    moduleKey: "procurement",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
    <rect x="3" y="4" width="18" height="16" rx="2"></rect>
    <path d="M8 8h8"></path>
    <path d="M8 12h5"></path>
    <path d="M8 16h4"></path>
    <path d="M16 12l2 2 3-3"></path>
      </svg>
    ),
    name: "Stock Request Approval",
    route: "erp.procurement.stock-request-approval",
    moduleKey: "inventory",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
    <rect x="3" y="4" width="18" height="16" rx="2"></rect>
    <path d="M7 8h10"></path>
    <path d="M7 12h10"></path>
    <path d="M7 16h6"></path>
    <path d="M16 16l1.5 1.5L20 15"></path>
      </svg>
    ),
    name: "Purchase Orders",
    route: "erp.procurement.purchase-orders",
    moduleKey: "procurement",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
    <circle cx="9" cy="10" r="3"></circle>
    <path d="M3 19a6 6 0 0 1 12 0"></path>
    <circle cx="17" cy="8" r="2"></circle>
    <path d="M14 14a4 4 0 0 1 7 3"></path>
      </svg>
    ),
    name: "Suppliers Management",
    route: "erp.procurement.suppliers-management",
    moduleKey: "procurement",
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
    name: "Retail Job Orders",
    route: "erp.staff.job-orders",
    moduleKey: "retail_operations",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.8} strokeLinecap="round" strokeLinejoin="round" xmlns="http://www.w3.org/2000/svg">
        <path d="M3 15h4.5l2.5-3.5h3.5l2.2 2.2c.8.8 1.9 1.3 3 1.3H21a1 1 0 0 1 1 1v1a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-1a2 2 0 0 1 1-1z" />
        <path d="M8 15l1.5 1.5" />
        <path d="M11 15l1.5 1.5" />
      </svg>
    ),
    name: "Product Management",
    route: "erp.staff.products",
    moduleKey: "retail_operations",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path>
        <line x1="7" y1="7" x2="7.01" y2="7"></line>
      </svg>
    ),
    name: "Shoe Pricing Requests",
    route: "erp.staff.shoe-pricing",
    moduleKey: "repair_operations",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M21 16V8a2 2 0 0 0-1-1.73L12 3 4 6.27A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73L12 21l8-4.27A2 2 0 0 0 21 16z"></path>
        <path d="M12 12v9"></path>
      </svg>
    ),
    name: "Inventory Overview",
    route: "erp.staff.inventory-overview",
    moduleKey: "inventory",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <path d="M3 7h11v10H3z"></path>
        <path d="M14 10h4l3 3v4h-7z"></path>
        <circle cx="7" cy="19" r="2"></circle>
        <circle cx="17" cy="19" r="2"></circle>
      </svg>
    ),
    name: "Logistics",
    route: "erp.logistics.shipments",
    moduleKey: "logistics",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <path d="M12 2 3 7l9 5 9-5-9-5Z"></path>
        <path d="m3 12 9 5 9-5"></path>
        <path d="m3 17 9 5 9-5"></path>
      </svg>
    ),
    name: "Batches",
    route: "erp.logistics.batches",
    moduleKey: "logistics",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <path d="M9 11l3 3L22 4"></path><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>
      </svg>
    ),
    name: "My Deliveries",
    route: "erp.logistics.deliveries",
    moduleKey: "logistics",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
        <circle cx="9" cy="7" r="4"></circle>
        <path d="M22 21v-2a4 4 0 0 0-3-3.87"></path>
        <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
      </svg>
    ),
    name: "Riders",
    route: "erp.logistics.riders",
    moduleKey: "logistics",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <circle cx="12" cy="12" r="3"></circle>
        <path d="M12 2v2m0 16v2M2 12h2m16 0h2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42m0-14.14-1.42 1.42M6.35 17.65l-1.42 1.42"></path>
      </svg>
    ),
    name: "Settings",
    route: "erp.logistics.settings",
  },
];

const repairItems: NavItem[] = [
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.8} strokeLinecap="round" strokeLinejoin="round" xmlns="http://www.w3.org/2000/svg">
        <path d="M3 3v18h18"></path>
        <path d="M7 15v-4"></path>
        <path d="M12 15V8"></path>
        <path d="M17 15v-6"></path>
      </svg>
    ),
    name: "Repair Dashboard",
    route: "erp.staff.repair-dashboard",
    moduleKey: "repair_operations",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" strokeLinejoin="round" xmlns="http://www.w3.org/2000/svg">
        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
        <circle cx="12" cy="12" r="3"></circle>
      </svg>
    ),
    name: "Job Orders Repair",
    route: "erp.staff.job-orders-repair",
    moduleKey: "repair_operations",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <path d="M9 12h6M9 16h6M9 8h6" />
        <path d="M7 4h10a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z" />
        <path d="M4 12h1M19 12h1" />
      </svg>
    ),
    name: "Warranty Queue",
    route: "erp.staff.warranty-queue",
    moduleKey: "repair_operations",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path strokeLinecap="round" strokeLinejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
      </svg>
    ),
    name: "Upload Services",
    route: "erp.staff.upload-services",
    moduleKey: "repair_operations",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path>
        <line x1="7" y1="7" x2="7.01" y2="7"></line>
      </svg>
    ),
    name: "Repair Pricing Requests",
    route: "erp.repairer.pricing-services",
    moduleKey: "repair_operations",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M21 16V8l-9-5-9 5v8l9 5 9-5z"></path>
        <path d="M3 8l9 5 9-5"></path>
      </svg>
    ),
    name: "Stocks Overview",
    route: "erp.staff.stocks-overview",
    moduleKey: "inventory",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M4 6a2 2 0 0 1 2-2h8l4 4v10a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z"></path>
        <path d="M14 4v4h4"></path>
        <path d="M8 12h8"></path>
        <path d="M12 9v6"></path>
      </svg>
    ),
    name: "Request Material",
    route: "erp.staff.request-material",
    moduleKey: "inventory",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path strokeLinecap="round" strokeLinejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
      </svg>
    ),
    name: "Chat",
    route: "erp.repairer.support",
    moduleKey: "repair_operations",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
      </svg>
    ),
    name: "Repair Reject Approval",
    route: "erp.user.repair-reject-approval",
    moduleKey: "repair_operations",
  },
];

const cashierItems: NavItem[] = [
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" xmlns="http://www.w3.org/2000/svg">
        <path d="M6 6h15l-1.4 7H8L6 4H3"></path>
        <circle cx="9" cy="19" r="1.5"></circle>
        <circle cx="18" cy="19" r="1.5"></circle>
      </svg>
    ),
    name: "Cashier",
    route: "erp.cashier.point-of-sale",
    moduleKey: "retail_operations",
  },
];

const EmployeeSidebarERP: React.FC = () => {
  const { isExpanded, isMobileOpen, isHovered, setIsHovered, openSubmenu, toggleSubmenu, setOpenSubmenu } = useSidebar();
  const { url, props } = usePage();
  const auth = (props as any)?.auth;
  const authModuleStates = auth?.shopModules;
  const sharedModuleStates = (props as any)?.moduleStates;
  const shopModules = authModuleStates && typeof authModuleStates === 'object' && Object.keys(authModuleStates).length > 0
    ? authModuleStates
    : sharedModuleStates;
  const moduleEnforcementEnabled = auth?.shopModuleEnforcementEnabled
    ?? (props as any)?.shopModuleEnforcementEnabled
    ?? Boolean(shopModules);
  const role = (props as any)?.auth?.user?.role;
  const roles = (props as any)?.auth?.user?.roles || [];
  const permissions = (props as any)?.auth?.permissions || [];
  const rawBusinessType = String(
    auth?.shop_owner?.business_type
    ?? auth?.user?.shop_owner?.business_type
    ?? ''
  ).toLowerCase().trim();
  const normalizedBusinessType = rawBusinessType.includes('both') ? 'both' : rawBusinessType;
  const isRepairCapableBusiness = normalizedBusinessType === 'repair' || normalizedBusinessType === 'both';
  const isRetailOnlyBusiness = normalizedBusinessType === 'retail';
  const isRepairOnlyBusiness = normalizedBusinessType === 'repair';
  const normalizedRole = String(role || '').toUpperCase();
  const normalizedRoles = Array.isArray(roles)
    ? roles.map((value: string) => String(value).toUpperCase())
    : [];
  const hasRolesArray = normalizedRoles.length > 0;
  const hasCashierRole = normalizedRoles.includes('CASHIER') || (!hasRolesArray && normalizedRole === 'CASHIER');
  const isCashierOnly = hasCashierRole && normalizedRoles.filter((value) => value !== 'CASHIER').length === 0;
  const hasManagerRole = normalizedRoles.includes('MANAGER') || (!hasRolesArray && normalizedRole === 'MANAGER');
  const hasInventoryManagerRole = normalizedRoles.includes('INVENTORY MANAGER');
  const hasProcurementManagerRole = normalizedRoles.includes('PROCUREMENT MANAGER');
  const hasExplicitStaffRole = normalizedRoles.includes('STAFF') || (!hasRolesArray && normalizedRole === 'STAFF');

  const [subMenuHeight, setSubMenuHeight] = useState<Record<string, number>>({});
  const subMenuRefs = useRef<Record<string, HTMLDivElement | null>>({});
  const sidebarScrollRef = useRef<HTMLDivElement | null>(null);
  const renderedItemKeys = new Set<string>();

  const getNavItemKey = (item: NavItem): string => {
    return `${item.route || ""}|${JSON.stringify(item.params || {})}|${item.name}`;
  };

  const isModuleVisible = useCallback((item: { moduleKey?: ShopModuleKey }) => {
    return canRenderShopModule(shopModules, item.moduleKey, moduleEnforcementEnabled);
  }, [shopModules, moduleEnforcementEnabled]);

  // Helper function to deduplicate items based on route and track what's been rendered
  const deduplicateItems = (items: NavItem[]): NavItem[] => {
    return items.filter(item => {
      const itemKey = getNavItemKey(item);
      if (renderedItemKeys.has(itemKey)) {
        return false; // Skip if already rendered
      }
      renderedItemKeys.add(itemKey);
      return true;
    });
  };

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
    "erp.staff.repair-dashboard": "/erp/staff/repair-dashboard",
    "erp.staff.job-orders-repair": "/erp/staff/job-orders-repair",
    "erp.staff.warranty-queue": "/erp/staff/warranty-queue",
    "erp.staff.upload-services": "/erp/staff/upload-services",
    "erp.staff.pricing-services": "/erp/staff/pricing-and-services",
    "erp.staff.repair-status": "/erp/staff/repair-status",
    "erp.staff.products": "/erp/staff/products",
    "erp.staff.shoe-pricing": "/erp/staff/shoe-pricing",
    "erp.staff.inventory-overview": "/erp/staff/inventory-overview",
    "erp.logistics.dashboard": "/erp/logistics",
    "erp.logistics.shipments": "/erp/logistics/shipments",
    "erp.logistics.batches": "/erp/logistics/batches",
    "erp.logistics.deliveries": "/erp/logistics/deliveries",
    "erp.logistics.riders": "/erp/logistics/riders",
    "erp.logistics.settings": "/erp/logistics/settings",
    "erp.staff.stocks-overview": "/erp/staff/stocks-overview",
    "erp.staff.request-material": "/erp/staff/request-material",
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
    "erp.manager.repair-rejection-review": "/erp/manager/repair-rejection-review",
    "erp.finance.audit-logs": "/erp/finance/audit-logs",
    // CRM section routes
    "crm.dashboard": "/crm",
    "crm.customers": "/crm/customers",
    "crm.customer-support": "/crm/customer-support",
    "crm.customer-reviews": "/crm/customer-reviews",
    // Manager section routes
    "erp.manager.dashboard": "/erp/manager/dashboard",
    "erp.manager.reports": "/erp/manager/reports",
    "erp.manager.shoe-pricing": "/erp/manager/shoe-pricing",
    "erp.manager.products": "/erp/manager/products",
    "erp.manager.inventory-overview": "/erp/manager/inventory-overview",
    "erp.manager.inventory-dashboard": "/erp/manager/inventory-dashboard",
    "erp.manager.upload-stocks": "/erp/manager/upload-stocks",
    "erp.manager.stock-movement": "/erp/manager/stock-movement",
    "erp.manager.product-inventory": "/erp/manager/product-inventory",
    "erp.inventory.inventory-dashboard": "/erp/inventory/inventory-dashboard",
    "erp.inventory.upload-stocks": "/erp/inventory/upload-stocks",
    "erp.inventory.stock-movement": "/erp/inventory/stock-movement",
    "erp.inventory.product-inventory": "/erp/inventory/product-inventory",
    "erp.inventory.stock-request": "/erp/inventory/stock-request",
    "erp.inventory.request-material-approval": "/erp/inventory/request-material-approval",
    "erp.inventory.supplier-order-monitoring": "/erp/inventory/supplier-order-monitoring",
    "erp.inventory.stock-request-approval": "/erp/inventory/stock-request-approval",
    "erp.inventory.purchase-request": "/erp/inventory/purchase-request",
    "erp.inventory.purchase-orders": "/erp/inventory/purchase-orders",
    "erp.inventory.suppliers-management": "/erp/inventory/suppliers-management",
    // Procurement module routes
    "erp.procurement.purchase-request": "/erp/procurement/purchase-request",
    "erp.procurement.purchase-orders": "/erp/procurement/purchase-orders",
    "erp.procurement.stock-request-approval": "/erp/procurement/stock-request-approval",
    "erp.procurement.suppliers-management": "/erp/procurement/suppliers-management",
    "erp.manager.user-management": "/erp/manager/user-management",
    "erp.manager.audit-logs": "/erp/manager/audit-logs",
    "erp.manager.suspend-approval": "/erp/manager/suspend-approval",
    "erp.manager.dss-insights": "/erp/manager/dss-insights",
    // User section routes
    "erp.user.repair-reject-approval": "/erp/user/repair-reject-approval",
    "erp.repairer.support": "/erp/staff/repairer-support",
    "erp.cashier.point-of-sale": "/erp/cashier/point-of-sale",
    "erp.repairer.point-of-sale": "/erp/repairer/point-of-sale",
    // Staff section routes
    "erp.staff.dashboard": "/erp/staff/dashboard",
    "erp.staff.job-orders": "/erp/staff/job-orders",
    "erp.staff.repair-dashboard": "/erp/staff/repair-dashboard",
    "erp.staff.job-orders-repair": "/erp/staff/job-orders-repair",
    "erp.staff.warranty-queue": "/erp/staff/warranty-queue",
    "erp.staff.upload-services": "/erp/staff/upload-services",
    "erp.staff.pricing-services": "/erp/staff/pricing-and-services",
    "erp.repairer.pricing-services": "/erp/repairer/pricing-and-services",
    "erp.staff.repair-status": "/erp/staff/repair-status",
    "erp.staff.products": "/erp/staff/products",
    "erp.staff.shoe-pricing": "/erp/staff/shoe-pricing",
    "erp.staff.inventory-overview": "/erp/staff/inventory-overview",
    "erp.logistics.dashboard": "/erp/logistics",
    "erp.logistics.shipments": "/erp/logistics/shipments",
    "erp.logistics.batches": "/erp/logistics/batches",
    "erp.logistics.deliveries": "/erp/logistics/deliveries",
    "erp.logistics.riders": "/erp/logistics/riders",
    "erp.logistics.settings": "/erp/logistics/settings",
    "erp.staff.stocks-overview": "/erp/staff/stocks-overview",
    "erp.staff.request-material": "/erp/staff/request-material",
    "erp.staff.attendance": "/erp/staff/attendance",
    "erp.staff.customers": "/erp/staff/customers",
    "erp.my-payslips": "/erp/my-payslips",
  };

  const isActive = useCallback(
    (routeName: string, params?: Record<string, any>, extraPaths?: string[]) => {
      try {
        const queryString = url.includes("?") ? url.split("?")[1] : "";
        const baseUrl = url.split("?")[0];

        if (routeName === "erp.staff.warranty-queue") {
          if (baseUrl === "/erp/staff/warranty-queue" || baseUrl === "/erp/repairer/warranty-queue") {
            return true;
          }
        }

        if (routeName.startsWith("erp.staff.")) {
          const staffPath = staffRouteMap[routeName];
          if (staffPath) {
            if (baseUrl === staffPath) return true;
            if (baseUrl.startsWith(staffPath + "/")) return true;
            if (extraPaths && extraPaths.some((path) => baseUrl.startsWith(path))) return true;
            return false;
          }
        }

        if (allRoutePaths[routeName]) {
          const mappedPath = allRoutePaths[routeName];

          if (baseUrl === mappedPath) {
            if (params?.section) {
              return queryString.includes(`section=${params.section}`);
            }
            return true;
          }

          const isDeepNestedPath = mappedPath.split("/").filter(Boolean).length >= 2;
          if (isDeepNestedPath && baseUrl.startsWith(mappedPath + "/")) return true;
        }

        try {
          const routeUrl = route(routeName, params || undefined);
          const routeUrlBase = routeUrl.split("?")[0];

          if (baseUrl === routeUrlBase) {
            if (params?.section && !queryString && routeUrl.includes("?")) {
              const routeQueryPart = routeUrl.split("?")[1] || "";
              if (routeQueryPart.includes(`section=${params.section}`)) return true;
            }
            return true;
          }

          const isDeepNestedPath = routeUrlBase.split("/").filter(Boolean).length >= 2;
          if (isDeepNestedPath && baseUrl.startsWith(routeUrlBase + "/")) return true;

          if (routeUrl.includes("?")) {
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
    
    // First try to use route() helper so route-name changes stay in sync with backend
    try {
      let url = route(routeName, params || undefined);
      // If params exist but weren't processed by route(), add them manually
      if (params && Object.keys(params).length > 0 && !url.includes('?')) {
        const queryParams = new URLSearchParams(params).toString();
        url += `?${queryParams}`;
      }
      return url;
    } catch {
      // Fall back to static maps when route() is unavailable (e.g., missing Ziggy entry)
      if (routeName === "erp.staff.warranty-queue") {
        let url = "/erp/repairer/warranty-queue";
        if (params && Object.keys(params).length > 0) {
          const queryParams = new URLSearchParams(params).toString();
          url += `?${queryParams}`;
        }
        return url;
      }

      if (allRoutePaths[routeName]) {
        let url = allRoutePaths[routeName];
        if (params && Object.keys(params).length > 0) {
          const queryParams = new URLSearchParams(params).toString();
          url += `?${queryParams}`;
        }
        return url;
      }

      if (routeName.startsWith && routeName.startsWith("erp.staff.")) {
        return staffRouteMap[routeName] || "#";
      }

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

  type AttendanceSectionKey = "staff" | "repair" | "cashier" | "manager" | "inventory" | "procurement" | "hr" | "finance" | "crm" | null;

  const getAttendanceSection = (): AttendanceSectionKey => {
    if (hasStaffAccess()) return "staff";
    if (hasRepairerAccess()) return "repair";
    if (hasCashierAccess()) return "cashier";
    if (hasManagerAccess()) return "manager";
    if (hasInventoryAccess()) return "inventory";
    if (hasProcurementAccess()) return "procurement";
    if (hasHRAccess()) return "hr";
    if (hasFinanceAccess()) return "finance";
    if (hasCRMAccess()) return "crm";
    return null;
  };

  const withAttendanceForSection = (section: Exclude<AttendanceSectionKey, null>, items: NavItem[]) => {
    if (getAttendanceSection() !== section) {
      return items;
    }

    const itemsWithoutAttendance = items.filter(
      (item) => !(item.name === attendanceItem.name && item.route === attendanceItem.route)
    );

    return [attendanceItem, ...itemsWithoutAttendance];
  };

  useEffect(() => {
    const menuGroups: Array<{ menuType: "attendance" | "staff" | "repair" | "cashier" | "manager" | "hr" | "finance" | "crm" | "main" | "others"; items: NavItem[] }> = [];

    if (hasStaffAccess()) {
      menuGroups.push({ menuType: "staff", items: withAttendanceForSection("staff", [...getFilteredStaffItems(), myPayslipsItem]) });
    }

    if (hasRepairerAccess()) {
      menuGroups.push({ menuType: "repair", items: withAttendanceForSection("repair", [...getFilteredRepairItems(), myPayslipsItem]) });
    }

    if (hasCashierAccess()) {
      menuGroups.push({ menuType: "cashier", items: withAttendanceForSection("cashier", [...cashierItems, myPayslipsItem]) });
    }

    if (hasManagerAccess()) {
      const filteredManagerItems = getFilteredManagerItems();
      menuGroups.push({
        menuType: "manager",
        items: withAttendanceForSection("manager", [...filteredManagerItems, myPayslipsItem]),
      });
    }

    if (hasHRAccess()) {
      const filteredHrItems = getFilteredHRItems();
      menuGroups.push({
        menuType: "hr",
        items: withAttendanceForSection("hr", [...filteredHrItems, myPayslipsItem]),
      });
    }

    if (hasFinanceAccess()) {
      menuGroups.push({ menuType: "finance", items: withAttendanceForSection("finance", [...getFilteredFinanceItems(), myPayslipsItem]) });
    }

    if (hasInventoryAccess()) {
      menuGroups.push({
        menuType: "manager",
        items: withAttendanceForSection("inventory", [...managerInventoryItems, myPayslipsItem]),
      });
    }

    if (hasProcurementAccess()) {
      menuGroups.push({
        menuType: "manager",
        items: withAttendanceForSection("procurement", [...procurementItems, myPayslipsItem]),
      });
    }

    if (hasCRMAccess()) {
      menuGroups.push({ menuType: "crm", items: withAttendanceForSection("crm", [...crmItems, myPayslipsItem]) });
    }

    // Deduplicate items across all menu groups based on route to prevent duplicates when users have multiple roles
    const seenRoutes = new Set<string>();
    const deduplicatedMenuGroups = menuGroups.map(group => ({
      ...group,
      items: group.items.filter(item => {
        const itemKey = getNavItemKey(item);
        if (seenRoutes.has(itemKey)) {
          return false; // Skip duplicate
        }
        seenRoutes.add(itemKey);
        return true;
      })
    }));

    let activeSubmenuKey: string | null = null;

    deduplicatedMenuGroups.some(({ menuType, items }) => {
      return items.some((nav, index) => {
        if (!nav.subItems || nav.subItems.length === 0) return false;

        const hasActiveSubItem = nav.subItems.some((subItem) =>
          isActive(subItem.route, subItem.params)
        );

        if (hasActiveSubItem) {
          activeSubmenuKey = `${menuType}-${index}`;
          return true;
        }

        return false;
      });
    });

    if (activeSubmenuKey && openSubmenu !== activeSubmenuKey) {
      setOpenSubmenu(activeSubmenuKey);
    }
  }, [url, isActive, role, roles, permissions, setOpenSubmenu]);

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

  const handleSubmenuToggle = (index: number, menuType: "attendance" | "staff" | "repair" | "cashier" | "manager" | "hr" | "finance" | "crm" | "main" | "others") => {
    const key = `${menuType}-${index}`;
    toggleSubmenu(key);
  };

  // Filter finance items based on user permissions
  const getFilteredFinanceItems = () => {
    return financeItems.map(item => ({ ...item })).filter((item) => {
      // Dashboard - check simplified permission
      if (item.route === "finance.dashboard") {
        return permissions.includes('access-finance-dashboard');
      }
      
      // Invoices - check simplified permission
      if (item.route === "finance.index" && item.params?.section === "invoice-generation") {
        return permissions.includes('access-finance-invoices');
      }
      
      // Expenses - check simplified permission
      if (item.route === "finance.index" && item.params?.section === "expense-tracking") {
        return permissions.includes('access-finance-expenses');
      }
      
      // Approvals - check simplified permissions and filter submenu
      if (item.name === "Approvals") {
        const hasAnyPricingPermission =
          permissions.includes('access-repair-price-approval') ||
          permissions.includes('access-shoe-price-approval') ||
          permissions.includes('access-refund-approval') ||
          permissions.includes('access-purchase-request-approval') ||
          normalizedRoles.includes('SHOP OWNER') ||
          normalizedRole === 'SHOP OWNER' ||
          permissions.includes('access-payslip-approval') ||
          permissions.includes('access-approval-workflow');
        
        if (hasAnyPricingPermission && item.subItems) {
          // Filter submenu items based on specific permissions
          item.subItems = item.subItems.filter((subItem) => {
            if (subItem.name === "Repair Pricing Approval") {
              return !isRetailOnlyBusiness && permissions.includes('access-repair-price-approval');
            }
            if (subItem.name === "Shoe Pricing Approval") {
              return !isRepairOnlyBusiness && permissions.includes('access-shoe-price-approval');
            }
            if (subItem.name === "Purchase Request Review") {
              return permissions.includes('access-purchase-request-approval') || permissions.includes('access-approval-workflow') || permissions.includes('access-finance-dashboard');
            }
            if (subItem.name === "Refund Approval") {
              return permissions.includes('access-refund-approval');
            }
            if (subItem.name === "Payslip Approvals") {
              return normalizedRoles.includes('SHOP OWNER') || normalizedRole === 'SHOP OWNER' || permissions.includes('access-payslip-approval') || permissions.includes('access-approval-workflow');
            }
            return false;
          });
        }
        
        return hasAnyPricingPermission && (item.subItems?.length ?? 0) > 0;
      }
      
      // Don't show items without matching permissions
      return false;
    });
  };

  // Check if user has any finance permissions
  const hasFinanceAccess = () => {
    if (normalizedRoles.includes('SHOP OWNER') || normalizedRole === 'SHOP OWNER') {
      return true;
    }

    const financePermissions = [
      'access-finance-dashboard',
      'access-finance-expenses',
      'access-finance-invoices',
      'access-purchase-request-approval',
      'access-approval-workflow',
      'access-payslip-approval',
      'access-refund-approval',
      'access-repair-price-approval',
      'access-shoe-price-approval',
    ];
    return financePermissions.some(perm => permissions.includes(perm));
  };

  // Check if user has HR role or HR-specific permissions
  const hasHRAccess = () => {
    // Check for HR role first
    if (normalizedRoles.includes('HR') || normalizedRole === 'HR') return true;
    
    // Or check for HR-specific simplified permissions
    const hrSpecificPermissions = [
      'access-hr-dashboard',
      'access-employee-directory',
      'access-attendance-records',
      'access-leave-approvals',
      'access-overtime-approvals',
      'access-payslip-generation',
      'access-view-payslip',
    ];
    return hrSpecificPermissions.some(perm => permissions.includes(perm));
  };

  // Check if user has any CRM permissions
  const hasCRMAccess = () => {
    const crmPermissions = [
      'access-crm-dashboard',
      'access-crm-customers',
      'access-customer-support',
      'access-customer-reviews',
      'access-crm-messages',
    ];
    return crmPermissions.some(perm => permissions.includes(perm));
  };

  // Check if user has manager role or manager-specific permissions
  const hasManagerAccess = () => {
    if (hasManagerRole) return true;

    // Dedicated module managers should stay inside their own module sections unless
    // they explicitly carry the MANAGER role.
    if (hasInventoryManagerRole || hasProcurementManagerRole) {
      return false;
    }

    // Keep manager gate strict: inventory-overview belongs to inventory flows and
    // should not elevate inventory-only users into the manager section.
    const managerPermissions = [
      'access-manager-dashboard',
      'access-audit-logs',
      'access-manager-reports',
      'access-repair-reject-review',
      'access-suspend-account',
    ];

    return managerPermissions.some((perm) => permissions.includes(perm));
  };

  // Check if user has Staff role or staff-specific permissions
  const hasStaffAccess = () => {
    if (isCashierOnly) return false;

    if (hasExplicitStaffRole) return true;

    const isProcurementOnlyAccount =
      hasProcurementAccess() &&
      !hasManagerRole &&
      !hasHRAccess() &&
      !hasFinanceAccess() &&
      !hasCRMAccess() &&
      !hasRepairerAccess();

    // Pure procurement accounts should not be classified as staff just because they
    // share the ERP shell. Mixed accounts that also have HR or other module access
    // can still surface staff pages when they carry staff permissions.
    if (isProcurementOnlyAccount) return false;

    const staffPermissions = [
      'access-staff-dashboard',
      'access-staff-job-orders',
      'access-product-management',
      'access-product-upload-staff',
      'access-shoe-pricing',
      'access-staff-customers',
      'access-logistics-dashboard',
      'view-logistics-shipments',
      'manage-logistics-batches',
      'operate-logistics-deliveries',
      'update-logistics-status',
      'record-logistics-proof',
    ];

    return staffPermissions.some((perm) => permissions.includes(perm));
  };

  // Check if user has Repairer role or repairer-specific permissions
  const hasRepairerAccess = () => {
    if (normalizedRoles.includes('REPAIRER') || normalizedRole === 'REPAIRER') return true;

    const repairerPermissions = [
      'access-repairer-dashboard',
      'access-repair-job-orders',
      'access-upload-service',
      'access-pricing-services',
      'access-repair-stocks',
      'access-repairer-support',
    ];

    return repairerPermissions.some((perm) => permissions.includes(perm));
  };

  const hasCashierAccess = () => {
    return permissions.includes('access-unified-pos');
  };

  // Filter manager items based on user permissions
  const getFilteredManagerItems = () => {
    return managerItems.filter((item) => {
      if (item.route === 'erp.manager.dashboard') {
        return permissions.includes('access-manager-dashboard');
      }

      if (item.route === 'erp.manager.audit-logs') {
        return permissions.includes('access-audit-logs');
      }

      if (item.route === 'erp.manager.suspend-approval') {
        return permissions.includes('access-suspend-account');
      }

      if (item.route === 'erp.manager.repair-rejection-review') {
        // Repair rejection review is only relevant for repair-capable shops.
        return isRepairCapableBusiness && permissions.includes('access-repair-reject-review');
      }

      if (item.route === 'erp.manager.inventory-overview') {
        return permissions.includes('access-inventory-overview');
      }

      if (item.route === 'erp.manager.dss-insights') {
        return permissions.includes('access-manager-reports') || permissions.includes('access-manager-dashboard');
      }

      return false;
    });
  };

  // Check if user has Inventory Manager role or explicit inventory gate permission
  // NOTE: 'access-inventory-overview' is intentionally excluded — it belongs to the Manager's
  // own overview page inside the Manager module, NOT the full Inventory module.
  const hasInventoryAccess = () => {
    if (normalizedRoles.includes('INVENTORY MANAGER')) return true;
    if (permissions.includes('view-inventory')) return true;
    // Only individual inventory module page permissions grant sidebar access
    const inventoryPagePermissions = [
      'access-inventory-dashboard',
      'access-product-inventory',
      'access-stock-movement',
      'access-upload-inventory',
      'access-request-material-approval',
      'access-inventory-request-material-approval',
    ];
    return inventoryPagePermissions.some(p => permissions.includes(p));
  };

  // Check if user has Procurement Manager role or explicit procurement gate permission
  const hasProcurementAccess = () => {
    if (normalizedRoles.includes('PROCUREMENT MANAGER')) return true;
    if (permissions.includes('view-procurement')) return true;
    // Also grant access if user has any individual procurement page permission
    const procurementPagePermissions = [
      'access-procurement-dashboard',
      'access-purchase-requests',
      'access-purchase-orders',
      'access-stock-request-approval',
      'access-suppliers-management',
    ];
    return procurementPagePermissions.some(p => permissions.includes(p));
  };

  const getFilteredInventoryItems = () => {
    return managerInventoryItems.filter((item) => {
      if (item.route === 'erp.inventory.request-material-approval') {
        return isRepairCapableBusiness;
      }

      return true;
    });
  };

  // Filter HR items based on user permissions
  const getFilteredHRItems = () => {
    return navItems.map(item => ({ ...item })).filter((item) => {
      // Dashboard - check simplified permission
      if (item.route === "erp.hr" && item.params?.section === "overview") {
        return permissions.includes('access-hr-dashboard');
      }
      
      // Employees - check simplified permission
      if (item.route === "erp.hr" && item.params?.section === "employees") {
        return permissions.includes('access-employee-directory');
      }
      
      // Attendance Monitoring - check simplified permissions and filter submenu
      if (item.name === "Attendance Monitoring") {
        const hasAnyAttendancePermission = permissions.includes('access-attendance-records') || 
               permissions.includes('access-leave-approvals') || 
               permissions.includes('access-overtime-approvals');
        
        if (hasAnyAttendancePermission && item.subItems) {
          // Filter submenu items based on specific permissions
          item.subItems = item.subItems.filter((subItem) => {
            if (subItem.name === "View Attendance") {
              return permissions.includes('access-attendance-records');
            }
            if (subItem.name === "Leave Requests") {
              return permissions.includes('access-leave-approvals');
            }
            if (subItem.name === "Overtime Requests") {
              return permissions.includes('access-overtime-approvals');
            }
            return false;
          });
        }
        
        return hasAnyAttendancePermission;
      }
      
      // Payroll - check simplified permissions and filter submenu
      if (item.name === "Payroll") {
        const canSeeSalaryChanges =
          permissions.includes('manage-salary-changes') ||
          permissions.includes('approve-salary-change') ||
          permissions.includes('override-salary-retroactive');

        const hasAnyPayrollPermission =
          permissions.includes('access-payslip-generation') ||
          permissions.includes('access-view-payslip') ||
          canSeeSalaryChanges;
        
        if (hasAnyPayrollPermission && item.subItems) {
          // Filter submenu items based on specific permissions
          item.subItems = item.subItems.filter((subItem) => {
            if (subItem.name === "View Slip") {
              return permissions.includes('access-view-payslip');
            }
            if (subItem.name === "Generate Slip") {
              return permissions.includes('access-payslip-generation');
            }
            if (subItem.name === "Salary Changes") {
              return canSeeSalaryChanges;
            }
            return false;
          });
        }
        
        return hasAnyPayrollPermission;
      }
      
      // Don't show items without matching permissions
      return false;
    });
  };

  // Filter staff items based on user permissions
  const getFilteredStaffItems = () => {
    if (isCashierOnly) return [];

    return staffItems.filter((item) => {
      // Dashboard - check simplified permission
      if (item.route === "erp.staff.dashboard") {
        return permissions.includes('access-staff-dashboard');
      }
      
      // Job Orders - check simplified permission
      if (item.route === "erp.staff.job-orders") {
        return permissions.includes('access-staff-job-orders');
      }
      
      // Products - check simplified permission
      if (item.route === "erp.staff.products") {
        return permissions.includes('access-product-upload-staff') || permissions.includes('access-product-management');
      }
      
      // Shoe Pricing - check simplified permission
      if (item.route === "erp.staff.shoe-pricing") {
        return permissions.includes('access-shoe-pricing');
      }

      // Inventory Overview - check if user has Staff role or permission
      if (item.route === "erp.staff.inventory-overview") {
        return permissions.includes('access-staff-dashboard') || permissions.includes('access-product-management') || permissions.includes('access-product-upload-staff');
      }

      if (item.route === "erp.logistics.shipments") {
        return permissions.includes('assign-logistics-deliveries');
      }

      if (item.route === "erp.logistics.batches") {
        return permissions.includes('manage-logistics-batches');
      }

      if (item.route === "erp.logistics.deliveries") {
        return permissions.includes('operate-logistics-deliveries');
      }

      if (item.route === "erp.logistics.riders") {
        return permissions.includes('manage-logistics-riders');
      }

      if (item.route === "erp.logistics.settings") {
        return permissions.includes('configure-logistics-settings');
      }
      
      // Hide other items by default (no permissions)
      return false;
    });
  };

  // Filter repair items based on user permissions
  const getFilteredRepairItems = () => {
    return repairItems.filter((item) => {
      // Repair Dashboard - check simplified permission
      if (item.route === "erp.staff.repair-dashboard") {
        return permissions.includes('access-repairer-dashboard');
      }

      // Job Orders Repair - check simplified permission
      if (item.route === "erp.staff.job-orders-repair") {
        return permissions.includes('access-repair-job-orders');
      }

      if (item.route === "erp.staff.warranty-queue") {
        return permissions.includes('access-repair-job-orders');
      }
      
      // Upload Services - check simplified permission
      if (item.route === "erp.staff.upload-services") {
        return permissions.includes('access-upload-service');
      }
      
      // Repair Pricing - check simplified permission
      if (item.route === "erp.repairer.pricing-services") {
        return permissions.includes('access-pricing-services');
      }

      // Stocks Overview - check simplified permission
      if (item.route === "erp.staff.stocks-overview") {
        return permissions.includes('access-repair-stocks');
      }

      // Request Material - check simplified permission
      if (item.route === "erp.staff.request-material") {
        return permissions.includes('access-repair-stocks');
      }
      
      // Repair Support - check simplified permission
      if (item.route === "erp.repairer.support") {
        return permissions.includes('access-repairer-support');
      }
      
      // Hide other items by default (no permissions)
      return false;
    });
  }

  const renderMenuItems = (items: NavItem[], menuType: "attendance" | "staff" | "repair" | "cashier" | "manager" | "hr" | "finance" | "crm" | "main" | "others") => {
    const visibleItems = items
      .map((item) => ({
        ...item,
        subItems: item.subItems?.filter(isModuleVisible),
      }))
      .filter((item) => isModuleVisible(item) && (!item.subItems || item.subItems.length > 0));

    return (
      <ul className="flex flex-col gap-4">
        {visibleItems.map((nav, index) => {
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
                      ? "xl:justify-center"
                      : "xl:justify-start"
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
                    <svg
                      className={`ml-auto w-5 h-5 transition-transform duration-200 ${
                        openSubmenu === `${menuType}-${index}`
                          ? "rotate-180"
                          : ""
                      }`}
                      viewBox="0 0 24 24"
                      fill="none"
                      stroke="currentColor"
                      strokeWidth="2"
                      strokeLinecap="round"
                      strokeLinejoin="round"
                      aria-hidden="true"
                    >
                      <path d="M6 9l6 6 6-6" />
                    </svg>
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
      className={`erp-sidebar fixed mt-16 flex flex-col xl:mt-0 top-0 px-5 left-0 h-screen transition-all duration-300 ease-in-out z-50 border-r
        ${
          isExpanded || isMobileOpen
            ? "w-[290px]"
            : isHovered
            ? "w-[290px]"
            : "w-[90px]"
        }
        ${isMobileOpen ? "translate-x-0" : "-translate-x-full"}
        xl:translate-x-0`}
      onMouseEnter={() => !isExpanded && setIsHovered(true)}
      onMouseLeave={() => setIsHovered(false)}
    >
      <div
        className={`py-8 flex ${
          !isExpanded && !isHovered ? "xl:justify-center" : "justify-start"
        }`}
      >
        <Link href={route("erp.time-in")} className="flex items-center gap-2 hover:scale-105 transition-transform duration-200">
          {isExpanded || isHovered || isMobileOpen ? (
            <span className="text-xl font-bold tracking-tight text-white">
              SoleSpace
            </span>
          ) : (
            <span className="text-lg font-bold tracking-tight text-white">SS</span>
          )}
        </Link>
      </div>
      <div 
        ref={sidebarScrollRef}
        className="flex flex-col overflow-y-auto duration-300 ease-linear no-scrollbar"
      >
        <>
        {/* STAFF section - Show if user has Staff role or staff permissions */}
        {hasStaffAccess() && (
          <nav className="mb-6">
            <div className="flex flex-col gap-4">
              <div>
                <h2
                  className={`mb-4 text-xs uppercase flex leading-[20px] text-gray-400 ${
                    !isExpanded && !isHovered
                      ? "xl:justify-center"
                      : "justify-start"
                  }`}
                >
                  {isExpanded || isHovered || isMobileOpen ? (
                    "STAFF"
                  ) : (
                    <HorizontaLDots className="size-6" />
                  )}
                </h2>
                {renderMenuItems(deduplicateItems(withAttendanceForSection("staff", [...getFilteredStaffItems(), myPayslipsItem])), "staff")}
              </div>
            </div>
          </nav>
        )}
        {/* REPAIR section - Show if user has Repairer role or repairer permissions */}
        {hasRepairerAccess() && (
          <nav className="mb-6">
            <div className="flex flex-col gap-4">
              <div>
                <h2
                  className={`mb-4 text-xs uppercase flex leading-[20px] text-gray-400 ${
                    !isExpanded && !isHovered
                      ? "xl:justify-center"
                      : "justify-start"
                  }`}
                >
                  {isExpanded || isHovered || isMobileOpen ? (
                    "REPAIR"
                  ) : (
                    <HorizontaLDots className="size-6" />
                  )}
                </h2>
                {renderMenuItems(deduplicateItems(withAttendanceForSection("repair", [...getFilteredRepairItems(), myPayslipsItem])), "repair")}
              </div>
            </div>
          </nav>
        )}
        {hasCashierAccess() && (
          <nav className="mb-6">
            <div className="flex flex-col gap-4">
              <div>
                <h2
                  className={`mb-4 text-xs uppercase flex leading-[20px] text-gray-400 ${
                    !isExpanded && !isHovered
                      ? "xl:justify-center"
                      : "justify-start"
                  }`}
                >
                  {isExpanded || isHovered || isMobileOpen ? (
                    "CASHIER"
                  ) : (
                    <HorizontaLDots className="size-6" />
                  )}
                </h2>
                {renderMenuItems(
                  deduplicateItems(withAttendanceForSection("cashier", [...cashierItems, myPayslipsItem])),
                  "cashier"
                )}
              </div>
            </div>
          </nav>
        )}
        {hasManagerAccess() && (
          <>
            <nav className="mb-6">
              <div className="flex flex-col gap-4">
                <div>
                  <h2
                    className={`mb-4 text-xs uppercase flex leading-[20px] text-gray-400 ${
                      !isExpanded && !isHovered
                        ? "xl:justify-center"
                        : "justify-start"
                    }`}
                  >
                    {isExpanded || isHovered || isMobileOpen ? (
                      "MANAGER"
                    ) : (
                      <HorizontaLDots className="size-6" />
                    )}
                  </h2>
                  {renderMenuItems(
                    deduplicateItems(withAttendanceForSection("manager", [...getFilteredManagerItems(), myPayslipsItem])),
                    "manager"
                  )}
                </div>
              </div>
            </nav>
          </>
        )}
        {hasInventoryAccess() && (
            <nav className="mb-6">
              <div className="flex flex-col gap-4">
                <div>
                  <h2
                    className={`mb-4 text-xs uppercase flex leading-[20px] text-gray-400 ${
                      !isExpanded && !isHovered
                        ? "xl:justify-center"
                        : "justify-start"
                    }`}
                  >
                    {isExpanded || isHovered || isMobileOpen ? (
                        "Inventory"
                    ) : (
                      <HorizontaLDots className="size-6" />
                    )}
                  </h2>
                  {renderMenuItems(deduplicateItems(withAttendanceForSection("inventory", [...getFilteredInventoryItems(), myPayslipsItem])), "manager")}
                </div>
              </div>
            </nav>
        )}
        {hasProcurementAccess() && (
            <nav className="mb-6">
              <div className="flex flex-col gap-4">
                <div>
                  <h2
                    className={`mb-4 text-xs uppercase flex leading-[20px] text-gray-400 ${
                      !isExpanded && !isHovered
                        ? "xl:justify-center"
                        : "justify-start"
                    }`}
                  >
                    {isExpanded || isHovered || isMobileOpen ? (
                      "Procurement"
                    ) : (
                      <HorizontaLDots />
                    )}
                  </h2>
                  {renderMenuItems(deduplicateItems(withAttendanceForSection("procurement", [...procurementItems, myPayslipsItem])), "manager")}
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
                      ? "xl:justify-center"
                      : "justify-start"
                  }`}
                >
                  {isExpanded || isHovered || isMobileOpen ? (
                    "HR"
                  ) : (
                    <HorizontaLDots className="size-6" />
                  )}
                </h2>
                {renderMenuItems(
                  deduplicateItems(withAttendanceForSection("hr", [...getFilteredHRItems(), myPayslipsItem])),
                  "hr"
                )}
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
                      ? "xl:justify-center"
                      : "justify-start"
                  }`}
                >
                  {isExpanded || isHovered || isMobileOpen ? (
                    "Finance"
                  ) : (
                    <HorizontaLDots className="size-6" />
                  )}
                </h2>
                {renderMenuItems(deduplicateItems(withAttendanceForSection("finance", [...getFilteredFinanceItems(), myPayslipsItem])), "finance")}
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
                      ? "xl:justify-center"
                      : "justify-start"
                  }`}
                >
                  {isExpanded || isHovered || isMobileOpen ? (
                    "CRM"
                  ) : (
                    <HorizontaLDots className="size-6" />
                  )}
                </h2>
                {renderMenuItems(deduplicateItems(withAttendanceForSection("crm", [...crmItems, myPayslipsItem])), "crm")}
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
                      ? "xl:justify-center"
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
          </>
      </div>
    </aside>
  );
};

const AppSidebar_ERP: React.FC = () => {
  const { props } = usePage();
  const auth = (props as any)?.auth;
  const erpActor = auth?.erpActor ?? (props as any)?.erpActor;
  const ownerMode = erpActor?.type === 'shop_owner' && erpActor?.ownerMode === true;
  const activeModule = (props as any)?.activeModule ?? null;

  return ownerMode
    ? <AppSidebar_shopOwner activeModule={activeModule} />
    : <EmployeeSidebarERP />;
};

export default AppSidebar_ERP;
