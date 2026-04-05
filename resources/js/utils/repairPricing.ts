export type RepairTaxMode = 'vat_inclusive' | 'legacy_add_on' | 'legacy_additive';

type BuildRepairBreakdownInput = {
  finalTotal: number;
  vatRate?: number;
  taxMode?: RepairTaxMode;
};

type RepairBreakdown = {
  taxMode: RepairTaxMode;
  vatRate: number;
  netSubtotal: number;
  vatAmount: number;
  grandTotal: number;
};

const round2 = (value: number): number => {
  const safe = Number.isFinite(value) ? value : 0;
  return Math.round(safe * 100) / 100;
};

export const buildRepairBreakdown = ({
  finalTotal,
  vatRate = 12,
  taxMode = 'legacy_additive',
}: BuildRepairBreakdownInput): RepairBreakdown => {
  const safeFinal = round2(Math.max(finalTotal, 0));
  const safeRate = Math.max(vatRate, 0);

  if (taxMode === 'vat_inclusive' && safeRate > 0) {
    const vatAmount = round2(safeFinal * (safeRate / (100 + safeRate)));
    const netSubtotal = round2(safeFinal - vatAmount);

    return {
      taxMode,
      vatRate: safeRate,
      netSubtotal,
      vatAmount,
      grandTotal: safeFinal,
    };
  }

  const vatAmount = round2(safeFinal * (safeRate / 100));

  return {
    taxMode,
    vatRate: safeRate,
    netSubtotal: safeFinal,
    vatAmount,
    grandTotal: round2(safeFinal + vatAmount),
  };
};