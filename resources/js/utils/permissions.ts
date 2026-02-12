/**
 * Permission Helper Utilities
 * 
 * Use these functions to check permissions instead of roles.
 * This allows granular access control regardless of assigned role.
 */

export interface AuthUser {
  id: number;
  name: string;
  email: string;
  role?: string;
  permissions?: string[];
}

export interface AuthData {
  user?: AuthUser;
  shop_owner?: any;
  super_admin?: any;
  permissions?: string[];
}

/**
 * Check if user has a specific permission
 */
export const hasPermission = (auth: AuthData | undefined, permission: string): boolean => {
  if (!auth) return false;
  
  // Shop owners and super admins have all permissions
  if (auth.shop_owner || auth.super_admin) return true;
  
  const permissions = auth.permissions || [];
  return permissions.includes(permission);
};

/**
 * Check if user has ANY of the specified permissions
 */
export const hasAnyPermission = (auth: AuthData | undefined, permissionList: string[]): boolean => {
  if (!auth) return false;
  
  // Shop owners and super admins have all permissions
  if (auth.shop_owner || auth.super_admin) return true;
  
  const permissions = auth.permissions || [];
  return permissionList.some(perm => permissions.includes(perm));
};

/**
 * Check if user has ALL of the specified permissions
 */
export const hasAllPermissions = (auth: AuthData | undefined, permissionList: string[]): boolean => {
  if (!auth) return false;
  
  // Shop owners and super admins have all permissions
  if (auth.shop_owner || auth.super_admin) return true;
  
  const permissions = auth.permissions || [];
  return permissionList.every(perm => permissions.includes(perm));
};

/**
 * Finance-specific permission checks
 */
export const canApproveExpenses = (auth: AuthData | undefined): boolean => {
  return hasPermission(auth, 'approve-expenses');
};

export const canApproveBudgets = (auth: AuthData | undefined): boolean => {
  return hasPermission(auth, 'approve-budgets');
};

export const canDeleteExpenses = (auth: AuthData | undefined): boolean => {
  return hasPermission(auth, 'delete-expenses');
};

export const canDeleteInvoices = (auth: AuthData | undefined): boolean => {
  return hasPermission(auth, 'delete-invoices');
};

export const canViewFinanceAuditLogs = (auth: AuthData | undefined): boolean => {
  return hasAnyPermission(auth, ['view-all-audit-logs', 'view-finance-audit-logs']);
};

/**
 * HR-specific permission checks
 */
export const canApproveEmployeeChanges = (auth: AuthData | undefined): boolean => {
  return hasPermission(auth, 'approve-employee-changes');
};

export const canProcessPayroll = (auth: AuthData | undefined): boolean => {
  return hasPermission(auth, 'process-payroll');
};

export const canApproveTimeoff = (auth: AuthData | undefined): boolean => {
  return hasPermission(auth, 'approve-timeoff');
};

/**
 * CRM-specific permission checks
 */
export const canConvertLeads = (auth: AuthData | undefined): boolean => {
  return hasPermission(auth, 'convert-leads');
};

export const canCloseOpportunities = (auth: AuthData | undefined): boolean => {
  return hasPermission(auth, 'close-opportunities');
};

/**
 * Manager-specific permission checks
 */
export const canAssignRoles = (auth: AuthData | undefined): boolean => {
  return hasPermission(auth, 'assign-roles');
};

export const canManageShopSettings = (auth: AuthData | undefined): boolean => {
  return hasPermission(auth, 'manage-shop-settings');
};

export const canViewAllAuditLogs = (auth: AuthData | undefined): boolean => {
  return hasPermission(auth, 'view-all-audit-logs');
};
