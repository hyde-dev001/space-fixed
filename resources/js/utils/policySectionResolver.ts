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