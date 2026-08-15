import type {
  OwnerShellCompatibility,
  OwnerShellContext,
  OwnerShellGroup,
  OwnerShellItem,
  OwnerShellMetadata,
  OwnerShellSelectionReason,
} from "../types/ownerShell";

const SELECTION_REASONS: ReadonlySet<OwnerShellSelectionReason> = new Set([
  "global_disabled",
  "shop_not_allowlisted",
  "shop_allowlisted",
  "invalid_registration_context",
  "cohort_evaluation_failed",
  "shell_composition_failed",
]);

const isRecord = (value: unknown): value is Record<string, unknown> => (
  typeof value === "object" && value !== null && !Array.isArray(value)
);

const isBoundedString = (value: unknown, maxLength = 240): value is string => (
  typeof value === "string" && value.length > 0 && value.length <= maxLength
);

const isPath = (value: unknown): value is string => (
  isBoundedString(value) && value.startsWith("/") && !value.startsWith("//")
);

const isOwnerShellItem = (value: unknown): value is OwnerShellItem => {
  if (!isRecord(value)) {
    return false;
  }

  const unavailableReason = value.unavailable_reason;
  const managementUrl = value.management_url;
  const isUnavailable = value.available === false;

  return isBoundedString(value.key, 80)
    && isBoundedString(value.label)
    && isPath(value.canonical_url)
    && typeof value.available === "boolean"
    && (unavailableReason === null || isBoundedString(unavailableReason))
    && (managementUrl === null || isPath(managementUrl))
    && (!isUnavailable || (isBoundedString(unavailableReason) && isPath(managementUrl)))
    && Array.isArray(value.active_matching)
    && value.active_matching.length > 0
    && value.active_matching.every(isPath);
};

const isOwnerShellGroup = (value: unknown): value is OwnerShellGroup => {
  if (!isRecord(value)) {
    return false;
  }

  return isBoundedString(value.key, 80)
    && isBoundedString(value.label)
    && Number.isInteger(value.order)
    && typeof value.default_expanded === "boolean"
    && Array.isArray(value.items)
    && value.items.length > 0
    && value.items.every(isOwnerShellItem);
};

const isOwnerShellCompatibility = (value: unknown): value is OwnerShellCompatibility => {
  if (!isRecord(value)) {
    return false;
  }

  return typeof value.show_erp_fallback === "boolean"
    && (value.erp_workspace_url === null || isPath(value.erp_workspace_url))
    && (value.fallback_url === null || isPath(value.fallback_url))
    && (!value.show_erp_fallback || isPath(value.fallback_url));
};

export const readCanonicalOwnerShell = (value: unknown): OwnerShellMetadata | null => {
  if (!isRecord(value) || value.presentation !== "canonical") {
    return null;
  }

  const context = value.context;
  const selectionReason = value.selection_reason;

  if ((context !== "individual" && context !== "company")
    || typeof selectionReason !== "string"
    || !SELECTION_REASONS.has(selectionReason as OwnerShellSelectionReason)
    || !Array.isArray(value.groups)
    || value.groups.length === 0
    || !value.groups.every(isOwnerShellGroup)
    || !isOwnerShellCompatibility(value.compatibility)) {
    return null;
  }

  return {
    presentation: "canonical",
    selection_reason: selectionReason as OwnerShellSelectionReason,
    context: context as Exclude<OwnerShellContext, null>,
    groups: value.groups as OwnerShellGroup[],
    compatibility: value.compatibility,
  };
};

export const isDirectShopOwnerContext = (value: unknown): boolean => {
  if (!isRecord(value)) {
    return false;
  }

  const auth = value.auth;
  return isRecord(auth) && isRecord(auth.shop_owner);
};

export const isOwnerModeErpContext = (value: unknown): boolean => {
  if (!isRecord(value) || !isRecord(value.auth) || !isRecord(value.auth.erpActor)) {
    return false;
  }

  const actor = value.auth.erpActor;
  return actor.type === "shop_owner" && actor.ownerMode === true;
};
