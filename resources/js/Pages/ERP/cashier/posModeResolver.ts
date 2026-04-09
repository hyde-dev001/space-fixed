export type PosMode = "repair" | "retail";

const normalizeBusinessType = (businessType: string): "repair" | "retail" | "both" => {
  const normalized = String(businessType || "").toLowerCase().trim();

  if (normalized === "both" || normalized.includes("both")) {
    return "both";
  }

  const hasRepairSignal = normalized.includes("repair") || normalized.includes("service");
  const hasRetailSignal = normalized.includes("retail") || normalized.includes("shoe") || normalized.includes("product");

  if (hasRepairSignal && hasRetailSignal) {
    return "both";
  }

  if (hasRepairSignal) {
    return "repair";
  }

  return "retail";
};

export const resolveAllowedModes = (businessType: string): PosMode[] => {
  const normalized = normalizeBusinessType(businessType);

  if (normalized === "both") {
    return ["repair", "retail"];
  }

  if (normalized === "repair") {
    return ["repair"];
  }

  return ["retail"];
};
