export const STAFF_ARTICLE_REGULAR_PERMISSIONS = [
  "access-staff-dashboard",
  "access-staff-job-orders",
  "access-product-management",
  "access-product-upload-staff",
  "access-shoe-pricing",
  "access-staff-time-in",
  "access-staff-leave",
  "access-color-variant-manager",
  "access-staff-customers",
] as const;

export type StaffArticlePermission = (typeof STAFF_ARTICLE_REGULAR_PERMISSIONS)[number];

export const STAFF_ARTICLE_EXCLUDED_ROLES = [
  "CASHIER",
  "REPAIRER",
  "LOGISTICS DISPATCHER",
  "LOGISTICS RIDER",
  "MANAGER",
  "HR",
  "FINANCE",
  "FINANCE STAFF",
  "FINANCE MANAGER",
  "CRM",
  "INVENTORY",
  "INVENTORY MANAGER",
  "PROCUREMENT",
  "PROCUREMENT MANAGER",
  "SHOP OWNER",
  "SUPER ADMIN",
] as const;

export type StaffArticleBusinessType = "retail" | "both";

export type StaffArticleViewer = {
  permissions: readonly string[];
  roles?: readonly string[];
  legacyRole?: string | null;
  businessType?: string | null;
};

export const normalizeStaffArticleRole = (value: unknown): string =>
  String(value ?? "")
    .trim()
    .toLocaleUpperCase()
    .replace(/_/g, " ");

export const normalizeStaffArticleBusinessType = (
  value: unknown,
): "retail" | "repair" | "both" | "" => {
  const normalized = String(value ?? "").trim().toLocaleLowerCase();

  if (normalized.includes("both")) return "both";
  if (normalized === "retail" || normalized === "repair") return normalized;

  return "";
};

export const isRegularStaffViewer = (viewer: StaffArticleViewer): boolean => {
  const roleNames = [
    ...(viewer.roles ?? []),
    viewer.legacyRole ?? "",
  ]
    .map(normalizeStaffArticleRole)
    .filter(Boolean);

  if (roleNames.some((role) => STAFF_ARTICLE_EXCLUDED_ROLES.some((excludedRole) => excludedRole === role))) {
    return false;
  }

  const businessType = normalizeStaffArticleBusinessType(viewer.businessType);

  return (
    STAFF_ARTICLE_REGULAR_PERMISSIONS.some((permission) =>
      viewer.permissions.includes(permission),
    ) && (businessType === "retail" || businessType === "both")
  );
};
