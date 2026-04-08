export type PosMode = "repair" | "retail";

export const resolveAllowedModes = (businessType: string): PosMode[] => {
  const normalized = String(businessType || "").toLowerCase();

  if (normalized === "both") {
    return ["repair", "retail"];
  }

  if (normalized === "repair") {
    return ["repair"];
  }

  return ["retail"];
};
