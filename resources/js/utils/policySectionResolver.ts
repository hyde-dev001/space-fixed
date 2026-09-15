export type PolicyFlow = 'retail' | 'repair';

type PolicySections = Record<string, string>;

const isRetailKey = (key: string): boolean => (
  key === 'retail_terms'
    || key === 'refund_payment_terms_retail'
    || key.startsWith('custom_terms_retail_')
);

const isRepairKey = (key: string): boolean => (
  key === 'repair_service_terms'
    || key === 'refund_payment_terms_repair'
    || key.startsWith('custom_terms_repair_')
);

const isLegacyCustomKey = (key: string): boolean => (
  key.startsWith('custom_terms_')
    && !key.startsWith('custom_terms_retail_')
    && !key.startsWith('custom_terms_repair_')
);

const getMetadataSectionKey = (key: string): string | null => {
  const metadataPrefixes = [
    '__section_title__',
    '__section_key__',
    '__section_custom_clauses__',
    '__section_deleted__',
  ];

  const prefix = metadataPrefixes.find((candidate) => key.startsWith(candidate));
  if (!prefix) return null;

  const sectionKey = key.slice(prefix.length);
  return sectionKey.length > 0 ? sectionKey : null;
};

const isIncludedInFlow = (key: string, flow: PolicyFlow, businessTypeScope: string): boolean => {
  const normalizedScope = String(businessTypeScope || '').toLowerCase().trim();

  if (isRetailKey(key)) return flow === 'retail';
  if (isRepairKey(key)) return flow === 'repair';

  if (isLegacyCustomKey(key)) {
    if (normalizedScope.includes('repair') && !normalizedScope.includes('both')) {
      return flow === 'repair';
    }

    return flow === 'retail';
  }

  return true;
};

export const requiredPolicySectionKeys = (businessType: string): string[] => {
  const normalized = String(businessType || '').toLowerCase().trim();
  const isBoth = normalized === 'both' || normalized.includes('both');
  const hasRepair = isBoth || normalized.includes('repair') || normalized.includes('service');
  const hasRetail = isBoth || normalized.includes('retail') || normalized.includes('shoe') || normalized.includes('product');

  const keys: string[] = ['refund_payment_terms'];
  if (hasRepair) keys.push('repair_service_terms');
  if (hasRetail) keys.push('retail_terms');

  return keys;
};

export const resolvePolicySectionsForFlow = (
  sections: PolicySections,
  flow: PolicyFlow,
  businessTypeScope = '',
): PolicySections => {
  const resolved: PolicySections = {};

  Object.entries(sections || {}).forEach(([key, value]) => {
    const contentKey = key.startsWith('__') ? getMetadataSectionKey(key) : key;

    if (key.startsWith('__') && !contentKey) return;
    if (contentKey && !isIncludedInFlow(contentKey, flow, businessTypeScope)) return;
    if (!contentKey && !isIncludedInFlow(key, flow, businessTypeScope)) return;

    resolved[key] = String(value ?? '');
  });

  return resolved;
};
