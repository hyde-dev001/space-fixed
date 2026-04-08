export type PosBusinessType = "retail" | "repair" | "both";
export type PosMode = "retail" | "repair";

export const normalizePosBusinessType = (raw: unknown): PosBusinessType => {
	const value = String(raw ?? "").trim().toLowerCase();

	if (value.includes("both")) return "both";
	if (value === "retail") return "retail";
	return "repair";
};

export const getAllowedPosModes = (businessType: PosBusinessType): PosMode[] => {
	if (businessType === "retail") return ["retail"];
	if (businessType === "repair") return ["repair"];
	return ["repair", "retail"];
};

export const resolveInitialPosMode = (
	businessType: PosBusinessType,
	requestedMode: string | null,
): PosMode => {
	const allowed = getAllowedPosModes(businessType);
	const normalized = String(requestedMode ?? "").toLowerCase();

	if (normalized === "retail" && allowed.includes("retail")) return "retail";
	if (normalized === "repair" && allowed.includes("repair")) return "repair";
	return allowed[0];
};
