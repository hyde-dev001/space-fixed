export type OwnerShellPresentation = "canonical" | "existing";

export type OwnerShellSelectionReason =
  | "global_disabled"
  | "shop_not_allowlisted"
  | "shop_allowlisted"
  | "invalid_registration_context"
  | "cohort_evaluation_failed"
  | "shell_composition_failed";

export type OwnerShellContext = "individual" | "company" | null;

export interface OwnerShellItem {
  key: string;
  label: string;
  canonical_url: string;
  available: boolean;
  unavailable_reason: string | null;
  management_url: string | null;
  active_matching: string[];
}

export interface OwnerShellGroup {
  key: string;
  label: string;
  order: number;
  default_expanded: boolean;
  items: OwnerShellItem[];
}

export interface OwnerShellCompatibility {
  show_erp_fallback: boolean;
  erp_workspace_url: string | null;
  fallback_url: string | null;
}

export interface OwnerShellMetadata {
  presentation: OwnerShellPresentation;
  selection_reason: OwnerShellSelectionReason;
  context: OwnerShellContext;
  groups: OwnerShellGroup[];
  compatibility: OwnerShellCompatibility;
}
