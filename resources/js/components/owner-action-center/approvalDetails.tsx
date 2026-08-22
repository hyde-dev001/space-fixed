import type { ReactNode } from "react";
import type { OwnerAttentionItem } from "../../types/ownerActionCenter";

export type ApprovalDetail = Record<string, unknown>;

export interface ApprovalDetailRendererProps {
  detail: ApprovalDetail;
  item: OwnerAttentionItem;
}

export const isRecord = (value: unknown): value is ApprovalDetail => (
  typeof value === "object" && value !== null && !Array.isArray(value)
);

const pathValue = (value: unknown, path: string): unknown => {
  const result = path.split(".").reduce<unknown>((current, segment) => (
    isRecord(current) ? current[segment] : undefined
  ), value);

  return result;
};

export const pick = (value: ApprovalDetail, ...paths: string[]): unknown => {
  for (const path of paths) {
    const candidate = pathValue(value, path);
    if (candidate !== null && candidate !== undefined && candidate !== "") {
      return candidate;
    }
  }

  return null;
};

export const hasAny = (value: ApprovalDetail, ...paths: string[]): boolean => pick(value, ...paths) !== null;

export const stringValue = (value: unknown, fallback = "—"): string => {
  if (typeof value === "string" && value.trim() !== "") return value;
  if (typeof value === "number" || typeof value === "boolean") return String(value);
  return fallback;
};

export const personName = (value: unknown, fallback = "—"): string => {
  if (!isRecord(value)) return stringValue(value, fallback);

  const named = pick(value, "name", "full_name", "display_name");
  if (named !== null) return stringValue(named, fallback);

  const first = stringValue(pick(value, "first_name", "firstName"), "").trim();
  const last = stringValue(pick(value, "last_name", "lastName"), "").trim();
  const combined = `${first} ${last}`.trim();

  return combined || fallback;
};

export const numberValue = (value: unknown): number | null => {
  if (typeof value === "number" && Number.isFinite(value)) return value;
  if (typeof value === "string" && value.trim() !== "") {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : null;
  }
  return null;
};

export const formatCurrency = (value: unknown): string => {
  const amount = numberValue(value);
  if (amount === null) return stringValue(value);

  return new Intl.NumberFormat("en-PH", {
    style: "currency",
    currency: "PHP",
    minimumFractionDigits: 2,
  }).format(amount);
};

export const formatDate = (value: unknown): string => {
  if (typeof value !== "string" || value.trim() === "") return stringValue(value);

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;

  return new Intl.DateTimeFormat("en-PH", {
    dateStyle: "medium",
    timeStyle: "short",
  }).format(date);
};

export const formatStatus = (value: unknown): string => {
  const status = stringValue(value, "Unknown").replace(/[_-]+/g, " ");
  return status.replace(/\b\w/g, (character) => character.toUpperCase());
};

export const displayValue = (value: unknown): string => {
  if (value === null || value === undefined || value === "") return "—";
  if (Array.isArray(value)) return value.map((entry) => displayValue(entry)).join(", ");
  if (isRecord(value)) return personName(value, stringValue(pick(value, "label", "title", "reference")));
  return stringValue(value);
};

export function DetailSection({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section className="rounded-xl border border-gray-200 p-4 dark:border-gray-800" aria-labelledby={`approval-${title.toLowerCase().replace(/[^a-z0-9]+/g, "-")}`}>
      <h3 id={`approval-${title.toLowerCase().replace(/[^a-z0-9]+/g, "-")}`} className="text-sm font-semibold text-gray-800 dark:text-white/90">
        {title}
      </h3>
      <div className="mt-3">{children}</div>
    </section>
  );
}

export function DetailGrid({ children }: { children: ReactNode }) {
  return <dl className="grid gap-3 sm:grid-cols-2">{children}</dl>;
}

export function DetailField({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div className="min-w-0">
      <dt className="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{label}</dt>
      <dd className="mt-1 break-words text-sm text-gray-800 dark:text-gray-200">{value}</dd>
    </div>
  );
}

export function StatusBadge({ value }: { value: unknown }) {
  return (
    <span className="inline-flex rounded-full bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">
      {formatStatus(value)}
    </span>
  );
}

export function DetailNote({ label, value }: { label: string; value: unknown }) {
  return (
    <div>
      <dt className="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{label}</dt>
      <dd className="mt-1 whitespace-pre-wrap text-sm text-gray-800 dark:text-gray-200">{displayValue(value)}</dd>
    </div>
  );
}
