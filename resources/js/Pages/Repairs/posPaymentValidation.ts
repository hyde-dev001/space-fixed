export type PosPaymentMethod = 'cash' | 'gcash' | 'card';

type CanPayInput = {
  itemsCount: number;
  customerName: string;
  customerPhone: string;
  customerEmail?: string;
  paymentMethod: PosPaymentMethod;
  cashReceivedInput: string;
  hasInsufficientCash: boolean;
  proofReference: string;
  requireCustomerInfo?: boolean;
};

export const isCashPhoneValid = (phone: string): boolean => phone.length === 11;

export const isOptionalEmailValid = (email: string): boolean => {
  const normalizedEmail = email.trim();

  return normalizedEmail === '' || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(normalizedEmail);
};

export const normalizeCustomerField = (value: unknown): string => {
  const normalized = String(value ?? '').trim();

  return normalized.toLowerCase() === 'n/a' ? '' : normalized;
};

export const normalizeOptionalCustomerEmail = (value: unknown): string => {
  return normalizeCustomerField(value);
};

export const normalizeOptionalCustomerId = (value: unknown): number | null => {
  const numericValue = Number(value);

  return Number.isInteger(numericValue) && numericValue > 0 ? numericValue : null;
};

export const computeCanPay = (input: CanPayInput): boolean => {
  const hasItems = input.itemsCount > 0;
  const requiresCustomerInfo = input.requireCustomerInfo !== false;
  const hasCustomerInfo = !requiresCustomerInfo
    || (input.customerName.trim().length > 0 && isCashPhoneValid(input.customerPhone));
  const hasValidEmail = isOptionalEmailValid(input.customerEmail ?? '');

  if (input.paymentMethod === 'cash') {
    return hasItems
      && hasCustomerInfo
      && hasValidEmail
      && input.cashReceivedInput.trim().length > 0
      && !input.hasInsufficientCash;
  }

  return hasItems
    && hasCustomerInfo
    && hasValidEmail
    && input.proofReference.trim().length > 0;
};

export const getPhoneDisplayForReceipt = (method: PosPaymentMethod, phone: string): string => {
  if (method !== 'cash' && phone.trim().length === 0) return 'N/A';
  return phone.trim();
};
