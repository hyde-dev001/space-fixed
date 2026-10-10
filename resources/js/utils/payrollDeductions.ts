export const deductionPercentageOfGross = (
  deductionAmount: unknown,
  grossPay: unknown,
): number => {
  const amount = Math.abs(Number(deductionAmount ?? 0));
  const gross = Number(grossPay ?? 0);

  if (!Number.isFinite(amount) || !Number.isFinite(gross) || gross <= 0) {
    return 0;
  }

  return Math.round((amount / gross) * 10000) / 100;
};

export const formatDeductionPercentage = (
  deductionAmount: unknown,
  grossPay: unknown,
): string => `${deductionPercentageOfGross(deductionAmount, grossPay).toFixed(2)}%`;
