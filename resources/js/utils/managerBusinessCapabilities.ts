export type ManagerBusinessCapabilities = {
    businessType: 'retail' | 'repair' | 'both';
    canRetail: boolean;
    canRepair: boolean;
};

export function getManagerBusinessCapabilities(value: unknown): ManagerBusinessCapabilities {
    const normalized = String(value ?? '').toLowerCase().trim();

    if (normalized.includes('both')) {
        return { businessType: 'both', canRetail: true, canRepair: true };
    }

    if (normalized.includes('repair') || normalized.includes('service')) {
        return { businessType: 'repair', canRetail: false, canRepair: true };
    }

    if (normalized.includes('retail') || normalized.includes('shoe') || normalized.includes('product')) {
        return { businessType: 'retail', canRetail: true, canRepair: false };
    }

    return { businessType: 'both', canRetail: true, canRepair: true };
}
