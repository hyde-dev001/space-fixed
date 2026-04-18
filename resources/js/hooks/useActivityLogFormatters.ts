interface ActivityLogChange {
  old: any;
  new: any;
  label?: string;
}

interface ActivityLogLike {
  event: string;
  subject_type: string | null;
  subject_label?: string;
  properties: Record<string, any>;
  changes: Record<string, ActivityLogChange>;
  causer?: {
    name?: string;
    role?: string;
  };
}

const FRIENDLY_SUBJECT_NAMES: Record<string, string> = {
  Product: "Product",
  Expense: "Expense",
  Invoice: "Invoice",
  User: "Employee",
  Employee: "Employee",
  Order: "Order",
  Customer: "Customer",
  RepairService: "Repair Service",
  RepairRequest: "Repair Request",
  LeaveRequest: "Leave Request",
  AttendanceRecord: "Attendance Record",
  Attendance: "Attendance",
  Payroll: "Payroll",
  PriceChangeRequest: "Price Change Request",
};

const MONEY_FIELD_HINTS = new Set([
  "price",
  "amount",
  "cost",
  "subtotal",
  "total",
  "grand_total",
  "salary",
  "rate",
  "net_pay",
  "gross_pay",
  "deduction",
  "allowance",
  "balance",
  "fee",
]);

const isCodeLikeSubjectLabel = (label: string): boolean => {
  const trimmed = label.trim();
  return /^[A-Z]{2,8}-\d{4,}$/i.test(trimmed) || /^[A-Z]{2,8}\d{6,}$/i.test(trimmed);
};

const humanizeKey = (raw: unknown): string => {
  const normalized = String(raw ?? "").trim();
  if (!normalized) {
    return "";
  }

  return normalized
    .replace(/_/g, " ")
    .replace(/\b\w/g, (letter) => letter.toUpperCase())
    .trim();
};

const isMoneyField = (fieldName: string): boolean => {
  const normalized = fieldName.toLowerCase();
  if (MONEY_FIELD_HINTS.has(normalized)) {
    return true;
  }

  return Array.from(MONEY_FIELD_HINTS).some((hint) => normalized.includes(hint));
};

export function useActivityLogFormatters() {
  const escapeHtml = (value: any): string => {
    return String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/\"/g, "&quot;")
      .replace(/'/g, "&#39;");
  };

  const formatSubjectType = (type: string | null): string => {
    if (!type) return "N/A";
    const parts = type.split("\\");
    const typeName = parts[parts.length - 1];
    return FRIENDLY_SUBJECT_NAMES[typeName] || typeName;
  };

  const formatValue = (value: any, fieldName = ""): string => {
    if (value === null || value === undefined || value === "") return "N/A";

    if (typeof value === "boolean") {
      return value ? "Yes" : "No";
    }

    if (typeof value === "number") {
      if (isMoneyField(fieldName)) {
        return `Php ${value.toLocaleString("en-US", {
          minimumFractionDigits: 2,
          maximumFractionDigits: 2,
        })}`;
      }
      return value.toLocaleString("en-US");
    }

    if (typeof value === "object") {
      return JSON.stringify(value);
    }

    const text = String(value);
    if (text.includes("_")) {
      return humanizeKey(text).toLowerCase().replace(/\b\w/g, (letter) => letter.toUpperCase());
    }

    return text;
  };

  const humanizeValue = (value: any, fieldName = ""): string => {
    return formatValue(value, fieldName);
  };

  const parseUserAgent = (ua: string): string => {
    if (!ua || ua === "N/A") return "Unknown Device";

    let browser = "Unknown Browser";
    let os = "Unknown OS";

    if (ua.includes("Chrome")) browser = "Chrome";
    else if (ua.includes("Firefox")) browser = "Firefox";
    else if (ua.includes("Safari") && !ua.includes("Chrome")) browser = "Safari";
    else if (ua.includes("Edge")) browser = "Edge";

    if (ua.includes("Windows")) os = "Windows";
    else if (ua.includes("Mac")) os = "macOS";
    else if (ua.includes("Linux")) os = "Linux";
    else if (ua.includes("Android")) os = "Android";
    else if (ua.includes("iOS")) os = "iOS";

    return `${browser} on ${os}`;
  };

  const getModalSubjectReference = (log: ActivityLogLike): string => {
    const subjectType = formatSubjectType(log.subject_type);

    const fallbackLabel =
      log.subject_label ||
      log.properties?.attributes?.name ||
      log.properties?.attributes?.reference ||
      log.properties?.attributes?.title ||
      "";

    const label = String(fallbackLabel).trim();
    if (!label || isCodeLikeSubjectLabel(label)) {
      return subjectType;
    }

    return `${subjectType} ${label}`;
  };

  const formatDetailedDescription = (log: ActivityLogLike): string => {
    const actor = log.causer?.name || "Unknown User";
    const actorRole = log.causer?.role ? ` (${log.causer.role})` : "";
    const subjectRef = getModalSubjectReference(log);
    const changes = log.changes || {};
    const changedFields = Object.keys(changes);

    switch (log.event) {
      case "created":
        return `${actor}${actorRole} created ${subjectRef}`;
      case "updated":
        if (changedFields.length === 1) {
          const key = changedFields[0];
          const change = changes[key];
          const fieldName = humanizeKey(change?.label || key);
          const oldVal = formatValue(change?.old, key);
          const newVal = formatValue(change?.new, key);
          return `${actor}${actorRole} updated ${fieldName} in ${subjectRef} from ${oldVal} to ${newVal}`;
        }

        if (changedFields.length > 1) {
          return `${actor}${actorRole} updated ${changedFields.length} fields in ${subjectRef}`;
        }

        return `${actor}${actorRole} updated ${subjectRef}`;
      case "deleted":
        return `${actor}${actorRole} deleted ${subjectRef}`;
      case "archived":
        return `${actor}${actorRole} archived ${subjectRef}`;
      case "restored":
        return `${actor}${actorRole} restored ${subjectRef}`;
      case "approved":
        return `${actor}${actorRole} approved ${subjectRef}`;
      case "rejected":
        return `${actor}${actorRole} rejected ${subjectRef}`;
      default:
        return `${actor}${actorRole} performed ${humanizeKey(log.event) || "an action"} on ${subjectRef}`;
    }
  };

  const getModalTitle = (log: ActivityLogLike): string => {
    const subjectRef = getModalSubjectReference(log);
    const changes = log.changes || {};
    const changedFields = Object.keys(changes);

    if (log.event === "created") {
      return `${subjectRef} created`;
    }

    if (log.event === "deleted") {
      return `${subjectRef} deleted`;
    }

    if (log.event === "archived") {
      return `${subjectRef} archived`;
    }

    if (log.event === "restored") {
      return `${subjectRef} restored`;
    }

    if (log.event === "approved") {
      return `${subjectRef} approved`;
    }

    if (log.event === "rejected") {
      return `${subjectRef} rejected`;
    }

    if (log.event === "updated") {
      if (changedFields.length === 1) {
        const key = changedFields[0];
        const change = changes[key];
        const fieldName = humanizeKey(change?.label || key);
        const oldVal = formatValue(change?.old, key);
        const newVal = formatValue(change?.new, key);
        return `${fieldName} changed in ${subjectRef} from ${oldVal} to ${newVal}`;
      }

      if (changedFields.length > 1) {
        return `${changedFields.length} fields changed in ${subjectRef}`;
      }

      return `Updated ${subjectRef}`;
    }

    return `${subjectRef} ${humanizeKey(log.event) || "Updated"}`;
  };

  return {
    escapeHtml,
    formatSubjectType,
    formatValue,
    humanizeValue,
    parseUserAgent,
    getModalSubjectReference,
    formatDetailedDescription,
    getModalTitle,
  };
}
