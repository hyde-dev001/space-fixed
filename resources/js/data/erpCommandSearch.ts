import type {
  ArticleCatalog,
  ArticleGuide,
  ArticleLanguage,
  ArticleViewer,
} from "./articleGuides";
import {
  ARTICLE_AUDIENCE_CONFIG,
  ARTICLE_AUDIENCES,
  isArticleAudience,
  type ArticleAudience,
} from "./articleAudience";
import { STAFF_ARTICLE_REGULAR_PERMISSIONS, isRegularStaffViewer } from "./staffArticleAccess";
import { getAccessibleArticles, searchArticles } from "../utils/articleGuides";
import { readArticleViewer } from "../utils/articleViewer";

export type ErpSearchScope = ArticleAudience;

export type ErpSearchViewer = {
  permissions: readonly string[];
  roles: readonly string[];
  legacyRole: string | null;
  businessType: string | null;
  registrationType: string | null;
  ownerMode: boolean;
};

export type ErpSearchPage = {
  id: string;
  scope: ErpSearchScope;
  scopeLabel: string;
  label: string;
  href: string;
  keywords: readonly string[];
  description: string;
  recommended?: boolean;
};

export type ErpSearchResult = ErpSearchPage & {
  kind: "page" | "article";
};

type PageAccess = {
  anyRoles?: readonly string[];
  anyPermissions?: readonly string[];
  allowedBusinessTypes?: readonly string[];
};

type PageDefinition = ErpSearchPage & {
  access?: PageAccess;
};

const SCOPE_LABELS: Record<ErpSearchScope, string> = {
  staff: "Staff",
  manager: "Manager",
  finance: "Finance",
  hr: "HR",
  crm: "CRM",
  cashier: "Cashier",
  repairer: "Repairer",
  inventory: "Inventory",
  procurement: "Procurement",
  "logistics-dispatcher": "Logistics",
  "shop-owner": "Shop Owner",
};

const ARTICLE_TYPE_TERMS = new Set(["article", "articles", "guide", "guides"]);

const normalizeSearchText = (value: unknown): string => (
  String(value ?? "")
    .trim()
    .toLocaleLowerCase()
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
);

const normalizeRole = (value: unknown): string => (
  String(value ?? "")
    .trim()
    .toLocaleUpperCase()
    .replace(/_/g, " ")
);

const normalizeBusinessType = (value: unknown): string => {
  const normalized = normalizeSearchText(value);

  if (normalized.includes("both") || (normalized.includes("retail") && normalized.includes("repair"))) {
    return "both";
  }
  if (normalized.includes("retail")) return "retail";
  if (normalized.includes("repair")) return "repair";

  return "";
};

const isRecord = (value: unknown): value is Record<string, unknown> => (
  typeof value === "object" && value !== null && !Array.isArray(value)
);

const readStringArray = (value: unknown): string[] => (
  Array.isArray(value)
    ? value.filter((item): item is string => typeof item === "string")
    : []
);

const viewerRoles = (viewer: ErpSearchViewer): string[] => [
  ...viewer.roles,
  viewer.legacyRole ?? "",
].map(normalizeRole).filter(Boolean);

const hasRole = (viewer: ErpSearchViewer, roles: readonly string[] = []): boolean => {
  const currentRoles = viewerRoles(viewer);

  return roles.some((role) => currentRoles.includes(normalizeRole(role)));
};

const hasPermission = (viewer: ErpSearchViewer, permissions: readonly string[] = []): boolean => (
  permissions.some((permission) => viewer.permissions.includes(permission))
);

const definePage = (
  scope: ErpSearchScope,
  id: string,
  label: string,
  href: string,
  keywords: readonly string[],
  access?: PageAccess,
  description = "Open this page",
): PageDefinition => ({
  id,
  scope,
  scopeLabel: SCOPE_LABELS[scope],
  label,
  href,
  keywords,
  description,
  access,
});

const STAFF_PAGES: readonly PageDefinition[] = [
  definePage("staff", "staff-dashboard", "Staff Dashboard", "/erp/staff/dashboard", ["dashboard", "work", "assigned"] , { anyPermissions: ["access-staff-dashboard"] }),
  definePage("staff", "staff-attendance", "Log Attendance", "/erp/time-in", ["attendance", "time in", "clock in"], { anyPermissions: ["access-staff-time", "access-staff-dashboard"] }),
  definePage("staff", "staff-job-orders", "Retail Job Orders", "/erp/staff/job-orders", ["orders", "job orders", "retail", "customer"], { anyPermissions: ["access-staff-job-orders"] }),
  definePage("staff", "staff-products", "Product Management", "/erp/staff/products", ["product", "products", "upload"], { anyPermissions: ["access-product-management", "access-product-upload-staff"] }),
  definePage("staff", "staff-shoe-pricing", "Shoe Pricing Requests", "/erp/staff/shoe-pricing", ["shoe", "pricing", "request"], { anyPermissions: ["access-shoe-pricing"] }),
  definePage("staff", "staff-inventory", "Inventory Overview", "/erp/staff/inventory-overview", ["inventory", "stock", "items"], { anyPermissions: ["access-staff-dashboard", "access-product-management", "access-product-upload-staff"] }),
  definePage("staff", "staff-payslips", "My Payslips", "/erp/my-payslips", ["payslip", "pay", "salary"], { anyPermissions: ["access-view-payslip"] }),
  definePage("staff", "staff-articles", "Staff Articles", "/erp/articles", ["article", "articles", "guide", "knowledge"], { anyPermissions: STAFF_ARTICLE_REGULAR_PERMISSIONS }),
];

const MANAGER_PAGES: readonly PageDefinition[] = [
  definePage("manager", "manager-dashboard", "Manager Dashboard", "/erp/manager/dashboard", ["dashboard", "manager", "overview"], { anyRoles: ["MANAGER"], anyPermissions: ["access-manager-dashboard"] }),
  definePage("manager", "manager-attendance", "Log Attendance", "/erp/time-in", ["attendance", "time in"], { anyRoles: ["MANAGER"], anyPermissions: ["access-staff-time"] }),
  definePage("manager", "manager-job-orders", "Job Orders", "/erp/manager/job-orders", ["orders", "job orders", "retail"], { anyRoles: ["MANAGER"], anyPermissions: ["access-manager-job-orders"], allowedBusinessTypes: ["retail", "both"] }),
  definePage("manager", "manager-repair-jobs", "Repair Jobs", "/erp/manager/repair-jobs", ["repair", "jobs", "service"], { anyRoles: ["MANAGER"], anyPermissions: ["access-manager-repair-jobs", "access-repair-reject-review"], allowedBusinessTypes: ["repair", "both"] }),
  definePage("manager", "manager-inventory", "Inventory Overview", "/erp/manager/inventory-overview", ["inventory", "stock"], { anyRoles: ["MANAGER"], anyPermissions: ["access-inventory-overview"] }),
  definePage("manager", "manager-workload", "Staff & Workload", "/erp/manager/staff-workload", ["staff", "workload", "assignment"], { anyRoles: ["MANAGER"], anyPermissions: ["access-manager-staff-workload"] }),
  definePage("manager", "manager-leave", "Leave Approvals", "/erp/manager/leave-approvals", ["leave", "approval", "absence"], { anyRoles: ["MANAGER"], anyPermissions: ["access-manager-leave-approvals", "access-leave-approvals"] }),
  definePage("manager", "manager-suspension", "Suspension Approvals", "/erp/manager/suspension-approvals", ["suspension", "approval", "account"], { anyRoles: ["MANAGER"], anyPermissions: ["access-manager-suspension-approvals", "access-suspend-account"] }),
  definePage("manager", "manager-termination", "Termination Approvals", "/erp/manager/termination-approvals", ["termination", "approval", "account"], { anyRoles: ["MANAGER"], anyPermissions: ["access-manager-termination-approvals"] }),
  definePage("manager", "manager-rehire", "Rehire Approvals", "/erp/manager/rehire-approvals", ["rehire", "approval", "employee"], { anyRoles: ["MANAGER"], anyPermissions: ["access-manager-rehire-approvals"] }),
  definePage("manager", "manager-reports", "Reports", "/erp/manager/reports", ["reports", "analytics", "review"], { anyRoles: ["MANAGER"], anyPermissions: ["access-manager-reports"] }),
  definePage("manager", "manager-audit-logs", "Audit Logs", "/erp/manager/audit-logs", ["audit", "logs", "history"], { anyRoles: ["MANAGER"], anyPermissions: ["access-audit-logs"] }),
  definePage("manager", "manager-articles", "Manager Articles", "/erp/manager/articles", ["article", "articles", "guide", "knowledge"], { anyRoles: ["MANAGER"], anyPermissions: ["access-manager-dashboard"] }),
];

const HR_PAGES: readonly PageDefinition[] = [
  definePage("hr", "hr-dashboard", "Dashboard", "/erp/hr?section=overview", ["dashboard", "overview"], { anyRoles: ["HR"], anyPermissions: ["access-hr-dashboard"] }),
  definePage("hr", "hr-employees", "Employees", "/erp/hr?section=employees", ["employees", "staff", "directory"], { anyRoles: ["HR"], anyPermissions: ["access-employee-directory"] }),
  definePage("hr", "hr-attendance", "View Attendance", "/erp/hr?section=attendance", ["attendance", "time", "records"], { anyRoles: ["HR"], anyPermissions: ["access-attendance-records"] }),
  definePage("hr", "hr-leave", "Leave Requests", "/erp/hr?section=leaves", ["leave", "request", "absence"], { anyRoles: ["HR"], anyPermissions: ["access-leave-approvals"] }),
  definePage("hr", "hr-overtime", "Overtime Requests", "/erp/hr?section=overtime", ["overtime", "hours", "request"], { anyRoles: ["HR"], anyPermissions: ["access-overtime-approvals"] }),
  definePage("hr", "hr-payslip-view", "View Slip", "/erp/hr?section=payroll-view", ["payslip", "payroll", "salary"], { anyRoles: ["HR"], anyPermissions: ["access-view-payslip"] }),
  definePage("hr", "hr-payslip-generate", "Generate Slip", "/erp/hr?section=payroll-generate", ["payslip", "payroll", "generate"], { anyRoles: ["HR"], anyPermissions: ["access-payslip-generation"] }),
  definePage("hr", "hr-salary-changes", "Salary Changes", "/erp/hr?section=salary-changes", ["salary", "change", "approval"], { anyRoles: ["HR"], anyPermissions: ["manage-salary-changes", "approve-salary-change", "override-salary-retroactive"] }),
  definePage("hr", "hr-articles", "HR Articles", "/erp/hr/articles", ["article", "articles", "guide", "knowledge"], { anyRoles: ["HR"], anyPermissions: ["access-hr-dashboard"] }),
];

const FINANCE_PAGES: readonly PageDefinition[] = [
  definePage("finance", "finance-dashboard", "Dashboard", "/finance/dashboard", ["dashboard", "overview"], { anyRoles: ["FINANCE", "FINANCE STAFF", "FINANCE MANAGER"], anyPermissions: ["access-finance-dashboard"] }),
  definePage("finance", "finance-invoices", "Invoices", "/finance?section=invoice-generation", ["invoice", "billing", "sales"], { anyRoles: ["FINANCE", "FINANCE STAFF", "FINANCE MANAGER"], anyPermissions: ["access-finance-invoices"] }),
  definePage("finance", "finance-repair-pricing", "Repair Pricing Approval", "/finance?section=repair-pricing", ["repair", "pricing", "approval"], { anyRoles: ["FINANCE", "FINANCE STAFF", "FINANCE MANAGER"], anyPermissions: ["access-repair-price-approval"] }),
  definePage("finance", "finance-shoe-pricing", "Shoe Pricing Approval", "/finance?section=shoe-pricing", ["shoe", "pricing", "approval"], { anyRoles: ["FINANCE", "FINANCE STAFF", "FINANCE MANAGER"], anyPermissions: ["access-shoe-price-approval"] }),
  definePage("finance", "finance-purchase-requests", "Purchase Request Review", "/finance?section=purchase-request-approval", ["purchase", "request", "approval"], { anyRoles: ["FINANCE", "FINANCE STAFF", "FINANCE MANAGER"], anyPermissions: ["access-purchase-request-approval", "access-approval-workflow"] }),
  definePage("finance", "finance-refunds", "Refund Approval", "/finance?section=refund-approvals", ["refund", "return", "approval"], { anyRoles: ["FINANCE", "FINANCE STAFF", "FINANCE MANAGER"], anyPermissions: ["access-refund-approval"] }),
  definePage("finance", "finance-payslip-approvals", "Payslip Approvals", "/finance?section=payslip-approvals", ["payslip", "payroll", "approval"], { anyRoles: ["FINANCE", "FINANCE STAFF", "FINANCE MANAGER"], anyPermissions: ["access-payslip-approval", "access-approval-workflow"] }),
  definePage("finance", "finance-expenses", "Expenses", "/finance?section=expense-tracking", ["expense", "cost", "tracking"], { anyRoles: ["FINANCE", "FINANCE STAFF", "FINANCE MANAGER"], anyPermissions: ["access-finance-expenses"] }),
  definePage("finance", "finance-articles", "Finance Articles", "/finance/articles", ["article", "articles", "guide", "knowledge"], { anyRoles: ["FINANCE", "FINANCE STAFF", "FINANCE MANAGER"], anyPermissions: ["access-finance-dashboard"] }),
];

const CRM_PAGES: readonly PageDefinition[] = [
  definePage("crm", "crm-dashboard", "CRM Dashboard", "/crm", ["dashboard", "crm", "overview"], { anyRoles: ["CRM"], anyPermissions: ["access-crm-dashboard"] }),
  definePage("crm", "crm-customers", "Customers", "/crm/customers", ["customer", "customers", "directory"], { anyRoles: ["CRM"], anyPermissions: ["access-crm-customers"] }),
  definePage("crm", "crm-support", "Customer Support", "/crm/customer-support", ["support", "customer", "conversation"], { anyRoles: ["CRM"], anyPermissions: ["access-customer-support"] }),
  definePage("crm", "crm-reviews", "Customer Reviews", "/crm/customer-reviews", ["reviews", "customer", "feedback"], { anyRoles: ["CRM"], anyPermissions: ["access-customer-reviews"] }),
  definePage("crm", "crm-articles", "CRM Articles", "/crm/articles", ["article", "articles", "guide", "knowledge"], { anyRoles: ["CRM"], anyPermissions: ["access-crm-dashboard"] }),
];

const CASHIER_PAGES: readonly PageDefinition[] = [
  definePage("cashier", "cashier-dashboard", "Cashier Dashboard", "/erp/cashier/dashboard", ["dashboard", "sales", "cashier"], { anyRoles: ["CASHIER"], anyPermissions: ["access-unified-pos"] }),
  definePage("cashier", "cashier-pos", "Point of Sale", "/erp/cashier/point-of-sale", ["pos", "payment", "sales", "cashier"], { anyRoles: ["CASHIER"], anyPermissions: ["access-unified-pos"] }),
  definePage("cashier", "cashier-articles", "Cashier Articles", "/erp/cashier/articles", ["article", "articles", "guide", "knowledge"], { anyRoles: ["CASHIER"], anyPermissions: ["access-unified-pos"] }),
];

const REPAIRER_PAGES: readonly PageDefinition[] = [
  definePage("repairer", "repair-dashboard", "Repair Dashboard", "/erp/staff/repair-dashboard", ["dashboard", "repair", "queue"], { anyRoles: ["REPAIRER"], anyPermissions: ["access-repairer-dashboard"], allowedBusinessTypes: ["repair", "both"] }),
  definePage("repairer", "repair-job-orders", "Job Orders Repair", "/erp/staff/job-orders-repair", ["repair", "job orders", "service"], { anyRoles: ["REPAIRER"], anyPermissions: ["access-repair-job-orders"], allowedBusinessTypes: ["repair", "both"] }),
  definePage("repairer", "repair-warranty", "Warranty Queue", "/erp/repairer/warranty-queue", ["warranty", "claim", "repair"], { anyRoles: ["REPAIRER"], anyPermissions: ["access-repair-job-orders"], allowedBusinessTypes: ["repair", "both"] }),
  definePage("repairer", "repair-upload-services", "Upload Services", "/erp/staff/upload-services", ["service", "upload", "repair"], { anyRoles: ["REPAIRER"], anyPermissions: ["access-upload-service"], allowedBusinessTypes: ["repair", "both"] }),
  definePage("repairer", "repair-pricing", "Repair Pricing Requests", "/erp/repairer/pricing-and-services", ["pricing", "service", "repair"], { anyRoles: ["REPAIRER"], anyPermissions: ["access-pricing-services"], allowedBusinessTypes: ["repair", "both"] }),
  definePage("repairer", "repair-stocks", "Stocks Overview", "/erp/staff/stocks-overview", ["stock", "materials", "repair"], { anyRoles: ["REPAIRER"], anyPermissions: ["access-repair-stocks"], allowedBusinessTypes: ["repair", "both"] }),
  definePage("repairer", "repair-request-material", "Request Material", "/erp/staff/request-material", ["material", "request", "stock"], { anyRoles: ["REPAIRER"], anyPermissions: ["access-repair-stocks"], allowedBusinessTypes: ["repair", "both"] }),
  definePage("repairer", "repair-support", "Chat", "/erp/staff/repairer-support", ["chat", "support", "customer"], { anyRoles: ["REPAIRER"], anyPermissions: ["access-repairer-support"], allowedBusinessTypes: ["repair", "both"] }),
  definePage("repairer", "repairer-articles", "Repairer Articles", "/erp/repairer/articles", ["article", "articles", "guide", "knowledge"], { anyRoles: ["REPAIRER"], anyPermissions: ["access-repairer-dashboard"], allowedBusinessTypes: ["repair", "both"] }),
];

const INVENTORY_PAGES: readonly PageDefinition[] = [
  definePage("inventory", "inventory-dashboard", "Inventory Dashboard", "/erp/inventory/inventory-dashboard", ["dashboard", "inventory", "stock"], { anyRoles: ["INVENTORY", "INVENTORY MANAGER"], anyPermissions: ["view-inventory", "access-inventory-dashboard"] }),
  definePage("inventory", "inventory-upload-stocks", "Manage Stock Items", "/erp/inventory/upload-stocks", ["stock", "items", "upload"], { anyRoles: ["INVENTORY", "INVENTORY MANAGER"], anyPermissions: ["access-upload-inventory"] }),
  definePage("inventory", "inventory-movement", "Stock Movement", "/erp/inventory/stock-movement", ["stock", "movement", "history"], { anyRoles: ["INVENTORY", "INVENTORY MANAGER"], anyPermissions: ["access-stock-movement"] }),
  definePage("inventory", "inventory-requests", "Stock Requests", "/erp/inventory/stock-request", ["stock", "request", "quantity"], { anyRoles: ["INVENTORY", "INVENTORY MANAGER"], anyPermissions: ["access-inventory-dashboard"] }),
  definePage("inventory", "inventory-material-queue", "Material Request Queue", "/erp/inventory/request-material-approval", ["material", "request", "approval"], { anyRoles: ["INVENTORY", "INVENTORY MANAGER"], anyPermissions: ["access-request-material-approval", "access-inventory-request-material-approval"] }),
  definePage("inventory", "inventory-supplier-orders", "Supplier Orders", "/erp/inventory/supplier-order-monitoring", ["supplier", "purchase order", "delivery"], { anyRoles: ["INVENTORY", "INVENTORY MANAGER"], anyPermissions: ["access-supplier-order-monitoring"] }),
  definePage("inventory", "inventory-articles", "Inventory Articles", "/erp/inventory/articles", ["article", "articles", "guide", "knowledge"], { anyRoles: ["INVENTORY", "INVENTORY MANAGER"], anyPermissions: ["access-inventory-dashboard"] }),
];

const PROCUREMENT_PAGES: readonly PageDefinition[] = [
  definePage("procurement", "procurement-dashboard", "Dashboard", "/erp/procurement/dashboard", ["dashboard", "procurement", "purchase"], { anyRoles: ["PROCUREMENT", "PROCUREMENT MANAGER"], anyPermissions: ["view-procurement", "access-procurement-dashboard"] }),
  definePage("procurement", "procurement-purchase-requests", "Purchase Requests", "/erp/procurement/purchase-request", ["purchase", "request", "procurement"], { anyRoles: ["PROCUREMENT", "PROCUREMENT MANAGER"], anyPermissions: ["access-purchase-requests"] }),
  definePage("procurement", "procurement-stock-approval", "Stock Request Approval", "/erp/procurement/stock-request-approval", ["stock", "request", "approval"], { anyRoles: ["PROCUREMENT", "PROCUREMENT MANAGER"], anyPermissions: ["access-stock-request-approval"] }),
  definePage("procurement", "procurement-purchase-orders", "Purchase Orders", "/erp/procurement/purchase-orders", ["purchase", "order", "supplier"], { anyRoles: ["PROCUREMENT", "PROCUREMENT MANAGER"], anyPermissions: ["access-purchase-orders"] }),
  definePage("procurement", "procurement-suppliers", "Suppliers Management", "/erp/procurement/suppliers-management", ["supplier", "vendors", "management"], { anyRoles: ["PROCUREMENT", "PROCUREMENT MANAGER"], anyPermissions: ["access-suppliers-management"] }),
  definePage("procurement", "procurement-articles", "Procurement Articles", "/erp/procurement/articles", ["article", "articles", "guide", "knowledge"], { anyRoles: ["PROCUREMENT", "PROCUREMENT MANAGER"], anyPermissions: ["access-procurement-dashboard"] }),
];

const LOGISTICS_PAGES: readonly PageDefinition[] = [
  definePage("logistics-dispatcher", "logistics-dashboard", "Logistics Dashboard", "/erp/logistics", ["dashboard", "logistics", "dispatch"], { anyRoles: ["LOGISTICS DISPATCHER"], anyPermissions: ["access-logistics-dashboard"] }),
  definePage("logistics-dispatcher", "logistics-shipments", "Shipments", "/erp/logistics/shipments", ["shipment", "order", "dispatch"], { anyRoles: ["LOGISTICS DISPATCHER"], anyPermissions: ["assign-logistics-deliveries"] }),
  definePage("logistics-dispatcher", "logistics-batches", "Batches", "/erp/logistics/batches", ["batch", "delivery", "dispatch"], { anyRoles: ["LOGISTICS DISPATCHER"], anyPermissions: ["manage-logistics-batches"] }),
  definePage("logistics-dispatcher", "logistics-deliveries", "My Deliveries", "/erp/logistics/deliveries", ["delivery", "shipment", "tracking"], { anyRoles: ["LOGISTICS DISPATCHER", "LOGISTICS RIDER"], anyPermissions: ["operate-logistics-deliveries"] }),
  definePage("logistics-dispatcher", "logistics-riders", "Riders", "/erp/logistics/riders", ["rider", "delivery", "assignment"], { anyRoles: ["LOGISTICS DISPATCHER"], anyPermissions: ["manage-logistics-riders"] }),
  definePage("logistics-dispatcher", "logistics-settings", "Settings", "/erp/logistics/settings", ["settings", "logistics", "configuration"], { anyRoles: ["LOGISTICS DISPATCHER"], anyPermissions: ["configure-logistics-settings"] }),
  definePage("logistics-dispatcher", "logistics-articles", "Logistics Articles", "/erp/logistics/articles", ["article", "articles", "guide", "knowledge"], { anyRoles: ["LOGISTICS DISPATCHER"], anyPermissions: ["access-logistics-dashboard"] }),
];

const PAGE_DEFINITIONS: readonly PageDefinition[] = [
  ...STAFF_PAGES,
  ...MANAGER_PAGES,
  ...HR_PAGES,
  ...FINANCE_PAGES,
  ...CRM_PAGES,
  ...CASHIER_PAGES,
  ...REPAIRER_PAGES,
  ...INVENTORY_PAGES,
  ...PROCUREMENT_PAGES,
  ...LOGISTICS_PAGES,
];

const ROLE_SCOPE: Record<string, ErpSearchScope> = {
  STAFF: "staff",
  MANAGER: "manager",
  FINANCE: "finance",
  "FINANCE STAFF": "finance",
  "FINANCE MANAGER": "finance",
  HR: "hr",
  CRM: "crm",
  CASHIER: "cashier",
  REPAIRER: "repairer",
  INVENTORY: "inventory",
  "INVENTORY MANAGER": "inventory",
  PROCUREMENT: "procurement",
  "PROCUREMENT MANAGER": "procurement",
  "LOGISTICS DISPATCHER": "logistics-dispatcher",
  "LOGISTICS RIDER": "logistics-dispatcher",
  "SHOP OWNER": "shop-owner",
};

const PATH_SCOPE: ReadonlyArray<readonly [string, ErpSearchScope]> = [
  ["/shop-owner/erp", "shop-owner"],
  ["/erp/manager", "manager"],
  ["/erp/hr", "hr"],
  ["/finance", "finance"],
  ["/crm", "crm"],
  ["/erp/cashier", "cashier"],
  ["/erp/repairer", "repairer"],
  ["/erp/inventory", "inventory"],
  ["/erp/procurement", "procurement"],
  ["/erp/logistics", "logistics-dispatcher"],
  ["/erp/articles", "staff"],
  ["/erp/staff", "staff"],
];

export const resolveErpSearchScope = (url: string, props: unknown): ErpSearchScope | null => {
  const root = isRecord(props) ? props : {};
  if (isArticleAudience(root.articleAudience)) return root.articleAudience;

  const auth = isRecord(root.auth) ? root.auth : {};
  const actor = isRecord(auth.erpActor) ? auth.erpActor : {};
  if (actor.type === "shop_owner" && actor.ownerMode === true) return "shop-owner";

  const user = isRecord(auth.user) ? auth.user : {};
  const roleCandidates = [
    typeof user.role === "string" ? user.role : "",
    ...readStringArray(user.roles),
  ];

  for (const candidate of roleCandidates) {
    const scope = ROLE_SCOPE[normalizeRole(candidate)];
    if (scope) return scope;
  }

  const path = String(url ?? "").split("?")[0];
  return PATH_SCOPE.find(([prefix]) => path === prefix || path.startsWith(`${prefix}/`))?.[1] ?? null;
};

export const readErpSearchViewer = (props: unknown, scope: ErpSearchScope): ErpSearchViewer => {
  const viewer = readArticleViewer(props, scope);

  return {
    permissions: viewer.permissions,
    roles: viewer.roles ?? [],
    legacyRole: viewer.legacyRole ?? null,
    businessType: viewer.businessType ?? null,
    registrationType: viewer.registrationType ?? null,
    ownerMode: viewer.ownerMode === true,
  };
};

const canViewPage = (page: PageDefinition, viewer: ErpSearchViewer): boolean => {
  if (page.id === "staff-articles" && !isRegularStaffViewer(viewer)) return false;

  const access = page.access;
  if (!access) return true;

  if (access.allowedBusinessTypes?.length
    && !access.allowedBusinessTypes.includes(normalizeBusinessType(viewer.businessType))) {
    return false;
  }

  const roleMatch = hasRole(viewer, access.anyRoles);
  const permissionMatch = hasPermission(viewer, access.anyPermissions);
  const hasIdentityGate = Boolean(access.anyRoles?.length || access.anyPermissions?.length);

  return !hasIdentityGate || roleMatch || permissionMatch;
};

export const getAccessibleErpSearchPages = (
  scope: ErpSearchScope,
  viewer: ErpSearchViewer,
): ErpSearchPage[] => PAGE_DEFINITIONS
  .filter((page) => page.scope === scope && canViewPage(page, viewer))
  .map(({ access: _access, ...page }) => page);

const scoreMatch = (
  label: string,
  keywords: readonly string[],
  description: string,
  query: string,
  recommended = false,
): number => {
  const normalizedQuery = normalizeSearchText(query);
  const normalizedLabel = normalizeSearchText(label);
  const normalizedKeywords = normalizeSearchText(keywords.join(" "));
  const normalizedDescription = normalizeSearchText(description);
  const terms = normalizedQuery.split(/\s+/).filter(Boolean);
  const searchable = `${normalizedLabel} ${normalizedKeywords} ${normalizedDescription}`;

  if (!terms.every((term) => searchable.includes(term))) return -1;

  let score = 0;
  if (normalizedLabel === normalizedQuery) score += 1000;
  else if (normalizedLabel.startsWith(normalizedQuery)) score += 800;
  else if (normalizedLabel.includes(normalizedQuery)) score += 650;
  else if (normalizedKeywords.includes(normalizedQuery)) score += 450;
  else score += 250;

  if (recommended) score += 100;
  return score;
};

const articleScore = (article: ArticleGuide, query: string, language: ArticleLanguage): number => {
  const copy = article.translations[language];

  return scoreMatch(
    copy.title,
    ["article", "articles", "guide", "guides", ...copy.keywords],
    `${copy.question} ${copy.summary} ${copy.audience}`,
    query,
    article.recommended === true,
  );
};

export const searchErpCommands = ({
  scope,
  pages,
  catalog,
  query,
  language,
  basePath,
  viewer,
}: {
  scope: ErpSearchScope;
  pages: readonly ErpSearchPage[];
  catalog: ArticleCatalog;
  query: string;
  language: ArticleLanguage;
  basePath: string;
  viewer: ErpSearchViewer;
}): ErpSearchResult[] => {
  const normalizedQuery = normalizeSearchText(query);
  if (!normalizedQuery || catalog.audience !== scope) return [];

  const terms = normalizedQuery.split(/\s+/).filter(Boolean);
  const articleTypeOnly = terms.length > 0 && terms.every((term) => ARTICLE_TYPE_TERMS.has(term));
  const accessibleArticles = getAccessibleArticles(catalog, viewer);

  const pageResults = pages.flatMap((page) => {
    const score = scoreMatch(page.label, page.keywords, page.description, normalizedQuery, page.recommended);

    return score < 0 ? [] : [{ ...page, kind: "page" as const, score }];
  });
  const articleQuery = terms
    .filter((term) => !ARTICLE_TYPE_TERMS.has(term))
    .join(" ");
  const articleResults = searchArticles(accessibleArticles, articleQuery, language)
    .flatMap((article) => {
      const translation = article.translations[language];
      const score = articleScore(article, normalizedQuery, language);

      return score < 0 ? [] : [{
        id: `article-${article.slug}`,
        scope,
        scopeLabel: SCOPE_LABELS[scope],
        kind: "article" as const,
        label: translation.title,
        href: `${basePath}/${article.slug}`,
        keywords: translation.keywords,
        description: translation.question,
        recommended: article.recommended === true,
        score,
      }];
    });

  const sortedPages = pageResults.sort((left, right) => right.score - left.score);
  const sortedArticles = articleResults.sort((left, right) => right.score - left.score);
  const results = articleTypeOnly
    ? [...sortedArticles, ...sortedPages]
    : [...sortedPages, ...sortedArticles].sort((left, right) => right.score - left.score);

  return results.map(({ score: _score, ...result }) => result);
};

const ownerPage = (label: string, href: string, groupLabel: string): ErpSearchPage => ({
  id: `owner-${href}`,
  scope: "shop-owner",
  scopeLabel: SCOPE_LABELS["shop-owner"],
  label,
  href,
  keywords: [groupLabel, "owner", "shop"],
  description: `Open ${groupLabel} page`,
});

export const getOwnerShellSearchPages = (metadata: unknown): ErpSearchPage[] => {
  if (!isRecord(metadata) || !Array.isArray(metadata.groups)) return [];

  const pages: ErpSearchPage[] = [];
  const addItems = (items: unknown[], groupLabel: string) => {
    items.forEach((value) => {
      if (!isRecord(value) || value.available !== true) return;
      if (typeof value.label === "string" && typeof value.canonical_url === "string") {
        pages.push(ownerPage(value.label, value.canonical_url, groupLabel));
      }
      const childGroupLabel = typeof value.label === "string" ? value.label : groupLabel;
      if (Array.isArray(value.children)) addItems(value.children, childGroupLabel);
    });
  };

  metadata.groups.forEach((group) => {
    if (!isRecord(group) || !Array.isArray(group.items)) return;
    addItems(group.items, typeof group.label === "string" ? group.label : "Shop Owner");
  });

  pages.push(ownerPage("Shop Owner Articles", "/shop-owner/erp/articles", "Articles"));
  return pages;
};

export const getSearchScopeLabel = (scope: ErpSearchScope): string => SCOPE_LABELS[scope];

export const getSearchBasePath = (scope: ErpSearchScope): string => ARTICLE_AUDIENCE_CONFIG[scope].basePath;

export const isSearchScope = (value: unknown): value is ErpSearchScope => (
  typeof value === "string" && ARTICLE_AUDIENCES.includes(value as ErpSearchScope)
);
