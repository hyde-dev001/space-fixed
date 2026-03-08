import { useCallback, useEffect, useRef, useState } from "react";
import { Link, usePage } from "@inertiajs/react";
import { route } from "ziggy-js";

// Assume these icons are imported from an icon library
import {
  CheckLineIcon,
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

const myPayslipsItem: NavItem = {
  icon: (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" strokeLinejoin="round">
      <path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
    </svg>
  ),
  name: "My Payslips",
  route: "erp.my-payslips",
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
        name: "Repair Pricing Approval",
        route: "finance.index",
        params: { section: "repair-pricing" },
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
        icon: (
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
            <path d="M2 17s.5-3.5 4-3.5 4 3.5 4 3.5m6 0s.5-3.5 4-3.5 4 3.5 4 3.5M2 17h20v4H2z"></path>
          </svg>
        ),
      },
      {
        name: "Purchase Request Approval",
        route: "finance.index",
        params: { section: "purchase-request-approval" },
        icon: (
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
            <rect x="4" y="4" width="16" height="16" rx="2"></rect>
            <path d="M8 9h8"></path>
            <path d="M8 13h8"></path>
            <path d="M8 17h5"></path>
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
        <path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
      </svg>
    ),
    name: "Refund Approval",
    route: "finance.index",
    params: { section: "refund-approvals" },
    extraPaths: ["/finance?refund-approvals", "/finance?section=refund-approvals"],
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
    name: "Customers",
    route: "crm.customers",
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
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M12 17l-5 3 1.5-5.5L4 10.5l5.6-.5L12 5l2.4 5 5.6.5-4.5 4 1.5 5.5z"></path>
      </svg>
    ),
    name: "Customer Reviews",
    route: "crm.customer-reviews",
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
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
      </svg>
    ),
    name: "Repair Rejection Review",
    route: "erp.manager.repair-rejection-review",
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
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <rect x="2" y="3" width="20" height="14" rx="2"></rect>
        <path d="M8 21h8m-4-4v4"></path>
        <path d="M7 8h.01M11 8h6M7 12h4m2 0h3"></path>
      </svg>
    ),
    name: "DSS Insights",
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
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path strokeLinecap="round" strokeLinejoin="round" d="M12 16V4m0 0l-4 4m4-4l4 4" />
        <path strokeLinecap="round" strokeLinejoin="round" d="M20 16.58A5 5 0 0018 7h-1.26A8 8 0 104 16.25" />
        <path strokeLinecap="round" strokeLinejoin="round" d="M8 16l4 4 4-4" />
      </svg>
    ),
    name: "Upload Stocks",
    route: "erp.inventory.upload-stocks",
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
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <rect x="3" y="4" width="18" height="16" rx="2"></rect>
        <path d="M8 8h8"></path>
        <path d="M8 12h8"></path>
        <path d="M8 16h5"></path>
      </svg>
    ),
    name: "Product Inventory",
    route: "erp.inventory.product-inventory",
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
    name: "Stock Request",
    route: "erp.inventory.stock-request",
  },
];

const procurementItems: NavItem[] = [
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
    name: "Supplier Order Monitoring",
    route: "erp.procurement.supplier-order-monitoring",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
    <path d="M5 4h10l4 4v12H5z"></path>
    <path d="M15 4v4h4"></path>
    <path d="M8 13l5-5 2 2-5 5-3 1z"></path>
      </svg>
    ),
    name: "Purchase Request",
    route: "erp.procurement.purchase-request",
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
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
    <rect x="4" y="3" width="16" height="18" rx="2"></rect>
    <path d="M8 9h8"></path>
    <path d="M8 13h5"></path>
    <path d="M14 15l2 2 3-3"></path>
      </svg>
    ),
    name: "Stock Request Approval",
    route: "erp.procurement.stock-request-approval",
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
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.8} strokeLinecap="round" strokeLinejoin="round" xmlns="http://www.w3.org/2000/svg">
        <path d="M3 15h4.5l2.5-3.5h3.5l2.2 2.2c.8.8 1.9 1.3 3 1.3H21a1 1 0 0 1 1 1v1a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-1a2 2 0 0 1 1-1z" />
        <path d="M8 15l1.5 1.5" />
        <path d="M11 15l1.5 1.5" />
      </svg>
    ),
    name: "Product Uploader",
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
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M21 16V8a2 2 0 0 0-1-1.73L12 3 4 6.27A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73L12 21l8-4.27A2 2 0 0 0 21 16z"></path>
        <path d="M12 12v9"></path>
      </svg>
    ),
    name: "Inventory Overview",
    route: "erp.staff.inventory-overview",
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
    route: "erp.repairer.pricing-services",
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
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path strokeLinecap="round" strokeLinejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
      </svg>
    ),
    name: "Chat",
    route: "erp.repairer.support",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
      </svg>
    ),
    name: "Repair Reject Approval",
    route: "erp.user.repair-reject-approval",
  },
];

const AppSidebar_ERP: React.FC = () => {
  const { isExpanded, isMobileOpen, isHovered, setIsHovered, openSubmenu, toggleSubmenu } = useSidebar();
  const { url, props } = usePage();
  const role = (props as any)?.auth?.user?.role;
  const roles = (props as any)?.auth?.user?.roles || [];
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
    "erp.staff.repair-dashboard": "/erp/staff/repair-dashboard",
    "erp.staff.job-orders-repair": "/erp/staff/job-orders-repair",
    "erp.staff.upload-services": "/erp/staff/upload-services",
    "erp.staff.pricing-services": "/erp/staff/pricing-and-services",
    "erp.staff.repair-status": "/erp/staff/repair-status",
    "erp.staff.products": "/erp/staff/products",
    "erp.staff.shoe-pricing": "/erp/staff/shoe-pricing",
    "erp.staff.inventory-overview": "/erp/staff/inventory-overview",
    "erp.staff.stocks-overview": "/erp/staff/stocks-overview",
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
    "erp.procurement.supplier-order-monitoring": "/erp/procurement/supplier-order-monitoring",
    "erp.manager.user-management": "/erp/manager/user-management",
    "erp.manager.audit-logs": "/erp/manager/audit-logs",
    "erp.manager.suspend-approval": "/erp/manager/suspend-approval",
    "erp.manager.dss-insights": "/erp/manager/dss-insights",
    // User section routes
    "erp.user.repair-reject-approval": "/erp/user/repair-reject-approval",
    "erp.repairer.support": "/erp/staff/repairer-support",
    // Staff section routes
    "erp.staff.dashboard": "/erp/staff/dashboard",
    "erp.staff.job-orders": "/erp/staff/job-orders",
    "erp.staff.repair-dashboard": "/erp/staff/repair-dashboard",
    "erp.staff.job-orders-repair": "/erp/staff/job-orders-repair",
    "erp.staff.upload-services": "/erp/staff/upload-services",
    "erp.staff.pricing-services": "/erp/staff/pricing-and-services",
    "erp.repairer.pricing-services": "/erp/repairer/pricing-and-services",
    "erp.staff.repair-status": "/erp/staff/repair-status",
    "erp.staff.products": "/erp/staff/products",
    "erp.staff.shoe-pricing": "/erp/staff/shoe-pricing",
    "erp.staff.inventory-overview": "/erp/staff/inventory-overview",
    "erp.staff.stocks-overview": "/erp/staff/stocks-overview",
    "erp.staff.attendance": "/erp/staff/attendance",
    "erp.staff.customers": "/erp/staff/customers",
    "erp.my-payslips": "/erp/my-payslips",
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

  useEffect(() => {
    const menuGroups: Array<{ menuType: "attendance" | "staff" | "repair" | "manager" | "hr" | "finance" | "crm" | "main" | "others"; items: NavItem[] }> = [];

    if (hasStaffAccess()) {
      menuGroups.push({ menuType: "staff", items: [attendanceItem, ...getFilteredStaffItems(), myPayslipsItem] });
    }

    if (hasRepairerAccess()) {
      menuGroups.push({ menuType: "repair", items: [attendanceItem, ...getFilteredRepairItems(), myPayslipsItem] });
    }

    if (role === "MANAGER") {
      menuGroups.push({
        menuType: "manager",
        items: hasFinanceAccess() ? [...managerItems, myPayslipsItem] : [attendanceItem, ...managerItems, myPayslipsItem],
      });
    }

    if (hasHRAccess()) {
      const filteredHrItems = getFilteredHRItems();
      menuGroups.push({
        menuType: "hr",
        items: hasFinanceAccess() ? [...filteredHrItems, myPayslipsItem] : [attendanceItem, ...filteredHrItems, myPayslipsItem],
      });
    }

    if (hasFinanceAccess()) {
      menuGroups.push({ menuType: "finance", items: [attendanceItem, ...getFilteredFinanceItems(), myPayslipsItem] });
    }

    let activeSubmenuKey: string | null = null;

    menuGroups.some(({ menuType, items }) => {
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
      toggleSubmenu(activeSubmenuKey);
    }
  }, [url, isActive, openSubmenu, toggleSubmenu, role, roles, permissions]);

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
      
      // Pricing Approvals - check simplified permissions and filter submenu
      if (item.name === "Pricing Approvals") {
        const hasAnyPricingPermission = permissions.includes('access-repair-price-approval') || permissions.includes('access-shoe-price-approval');
        
        if (hasAnyPricingPermission && item.subItems) {
          // Filter submenu items based on specific permissions
          item.subItems = item.subItems.filter((subItem) => {
            if (subItem.name === "Repair Pricing Approval") {
              return permissions.includes('access-repair-price-approval');
            }
            if (subItem.name === "Shoe Pricing Approval") {
              return permissions.includes('access-shoe-price-approval');
            }
            if (subItem.name === "Purchase Request Approval") {
              return permissions.includes('access-shoe-price-approval') || permissions.includes('access-repair-price-approval');
            }
            return false;
          });
        }
        
        return hasAnyPricingPermission;
      }
      
      // Refund Approval - check simplified permission
      if (item.route === "finance.index" && item.params?.section === "refund-approvals") {
        return permissions.includes('access-refund-approval');
      }
      
      // Payslip Approvals - check simplified permission
      if (item.route === "finance.index" && item.params?.section === "payslip-approvals") {
        return permissions.includes('access-payslip-approval');
      }
      
      // Don't show items without matching permissions
      return false;
    });
  };

  // Check if user has any finance permissions
  const hasFinanceAccess = () => {
    const financePermissions = [
      'access-finance-dashboard',
      'access-finance-expenses',
      'access-finance-invoices',
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
    if (roles.includes('HR')) return true;
    
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

  // Check if user has Staff role (don't check permissions - Finance has pricing permissions but shouldn't see Staff section)
  const hasStaffAccess = () => {
    return roles.includes('Staff') || role === "STAFF" || role === "Staff";
  };

  // Check if user has Repairer role (don't check permissions - Finance has repair pricing permissions but shouldn't see Repairer section)
  const hasRepairerAccess = () => {
    return roles.includes('Repairer') || role === "REPAIRER" || role === "Repairer";
  };

  // Check if user has Inventory Manager role or explicit inventory gate permission
  // NOTE: 'access-inventory-overview' is intentionally excluded — it belongs to the Manager's
  // own overview page inside the Manager module, NOT the full Inventory module.
  const hasInventoryAccess = () => {
    if (roles.includes('Inventory Manager')) return true;
    if (permissions.includes('view-inventory')) return true;
    // Only individual inventory module page permissions grant sidebar access
    const inventoryPagePermissions = [
      'access-inventory-dashboard',
      'access-product-inventory',
      'access-stock-movement',
      'access-upload-inventory',
    ];
    return inventoryPagePermissions.some(p => permissions.includes(p));
  };

  // Check if user has Procurement Manager role or explicit procurement gate permission
  const hasProcurementAccess = () => {
    if (roles.includes('Procurement Manager')) return true;
    if (permissions.includes('view-procurement')) return true;
    // Also grant access if user has any individual procurement page permission
    const procurementPagePermissions = [
      'access-procurement-dashboard',
      'access-purchase-requests',
      'access-purchase-orders',
      'access-stock-request-approval',
      'access-suppliers-management',
      'access-supplier-order-monitoring',
    ];
    return procurementPagePermissions.some(p => permissions.includes(p));
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
        const hasAnyPayrollPermission = permissions.includes('access-payslip-generation') || permissions.includes('access-view-payslip');
        
        if (hasAnyPayrollPermission && item.subItems) {
          // Filter submenu items based on specific permissions
          item.subItems = item.subItems.filter((subItem) => {
            if (subItem.name === "View Slip") {
              return permissions.includes('access-view-payslip');
            }
            if (subItem.name === "Generate Slip") {
              return permissions.includes('access-payslip-generation');
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
        return roles.includes('Staff') || permissions.includes('access-staff-dashboard');
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
      
      // Repair Support - check simplified permission
      if (item.route === "erp.repairer.support") {
        return permissions.includes('access-repairer-support');
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
        {/* STAFF section - Show if user has Staff role or staff permissions */}
        {hasStaffAccess() && (
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
                {renderMenuItems([attendanceItem, ...getFilteredStaffItems(), myPayslipsItem], "staff")}
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
                {renderMenuItems([attendanceItem, ...getFilteredRepairItems(), myPayslipsItem], "repair")}
              </div>
            </div>
          </nav>
        )}
        {role === "MANAGER" && (
          <>
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
                  {renderMenuItems(
                    hasFinanceAccess() ? [...managerItems, myPayslipsItem] : [attendanceItem, ...managerItems, myPayslipsItem],
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
                        ? "lg:justify-center"
                        : "justify-start"
                    }`}
                  >
                    {isExpanded || isHovered || isMobileOpen ? (
                        "Inventory"
                    ) : (
                      <HorizontaLDots className="size-6" />
                    )}
                  </h2>
                  {renderMenuItems(hasFinanceAccess() ? managerInventoryItems : [attendanceItem, ...managerInventoryItems], "manager")}
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
                        ? "lg:justify-center"
                        : "justify-start"
                    }`}
                  >
                    {isExpanded || isHovered || isMobileOpen ? (
                      "Procurement"
                    ) : (
                      <HorizontaLDots />
                    )}
                  </h2>
                  {renderMenuItems(hasFinanceAccess() ? procurementItems : [attendanceItem, ...procurementItems], "manager")}
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
                {renderMenuItems(
                  hasFinanceAccess() ? [...getFilteredHRItems(), myPayslipsItem] : [attendanceItem, ...getFilteredHRItems(), myPayslipsItem],
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
                {renderMenuItems([attendanceItem, ...getFilteredFinanceItems(), myPayslipsItem], "finance")}
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
                {renderMenuItems([attendanceItem, ...crmItems], "crm")}
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
