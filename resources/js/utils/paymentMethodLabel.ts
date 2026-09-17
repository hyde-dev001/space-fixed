const PAYMENT_METHOD_LABELS: Record<string, string> = {
  cash: 'Cash',
  cod: 'Cash on Delivery (COD)',
  cash_on_delivery: 'Cash on Delivery (COD)',
  'cash on delivery': 'Cash on Delivery (COD)',
  gcash: 'GCash',
  paymaya: 'PayMaya',
  pay_maya: 'PayMaya',
  maya: 'PayMaya',
  grab_pay: 'GrabPay',
  grabpay: 'GrabPay',
  card: 'Card',
  credit_card: 'Credit Card',
  debit_card: 'Debit Card',
  paymongo: 'PayMongo online payment',
  online: 'Online payment',
};

export const formatPaymentMethod = (paymentMethod?: string | null): string => {
  const normalized = String(paymentMethod || '').trim().toLowerCase();

  if (PAYMENT_METHOD_LABELS[normalized]) {
    return PAYMENT_METHOD_LABELS[normalized];
  }

  if (!normalized) {
    return 'Not specified';
  }

  return normalized
    .replace(/[_-]+/g, ' ')
    .replace(/\b\w/g, (character) => character.toUpperCase());
};
