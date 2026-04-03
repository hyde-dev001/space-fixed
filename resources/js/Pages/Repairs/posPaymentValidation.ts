export type PosPaymentMethod = 'cash' | 'gcash' | 'card';

type CanPayInput = {
  itemsCount: number;
  customerName: string;
  customerPhone: string;
  paymentMethod: PosPaymentMethod;
  cashReceivedInput: string;
  hasInsufficientCash: boolean;
  proofReference: string;
};

export const isCashPhoneValid = (phone: string): boolean => phone.length === 11;

export const computeCanPay = (input: CanPayInput): boolean => {
  const hasItems = input.itemsCount > 0;
  const hasName = input.customerName.trim().length > 0;

  if (input.paymentMethod === 'cash') {
    return hasItems
      && hasName
      && isCashPhoneValid(input.customerPhone)
      && input.cashReceivedInput.trim().length > 0
      && !input.hasInsufficientCash;
  }

  return hasItems
    && hasName
    && input.proofReference.trim().length > 0;
};

export const getPhoneDisplayForReceipt = (method: PosPaymentMethod, phone: string): string => {
  if (method !== 'cash' && phone.trim().length === 0) return 'N/A';
  return phone.trim();
};
