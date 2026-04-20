export type RefundSnapshot = {
  overall_status?: string | null;
  status?: string | null;
  repairer_status?: string | null;
  finance_status?: string | null;
  owner_status?: string | null;
  shop_owner_status?: string | null;
};

export const refundStageLabel = (refund: RefundSnapshot): string => {
  const overallStatus = String(refund.overall_status ?? refund.status ?? '').toLowerCase();
  const repairerStatus = String(refund.repairer_status ?? '').toLowerCase();
  const financeStatus = String(refund.finance_status ?? '').toLowerCase();
  const ownerStatus = String(refund.owner_status ?? refund.shop_owner_status ?? '').toLowerCase();

  if (overallStatus === 'executed' || overallStatus === 'succeeded') return 'Refund Executed';
  if (overallStatus === 'failed') return 'Refund Failed';
  if (overallStatus === 'rejected') return 'Rejected';
  if (repairerStatus === 'pending') return 'Under Repairer Review';
  if (financeStatus === 'pending' || financeStatus === 'approved_initial') return 'Under Finance Review';
  if (ownerStatus === 'pending') return 'Under Owner Review';
  if (overallStatus === 'approved_final' || overallStatus === 'approved') return 'Approved for Refund Execution';

  return 'In Review';
};
