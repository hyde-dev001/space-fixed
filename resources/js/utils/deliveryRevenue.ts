type RetailRevenueInput = {
  productRevenueExVat: number;
  shippingFee: number;
  refundedAmount: number;
  orderGrandTotal: number;
  paymentStatus?: string | null;
  carrierCompany?: string | null;
};

type RepairRevenueInput = {
  serviceGrossAmount: number;
  serviceNetAmount: number;
  totalPaidAmount: number;
  refundedAmount: number;
  paymentStatus?: string | null;
  paymentPolicy?: string | null;
  intakeDeliveryMethod?: string | null;
  intakeDeliveryFee?: number | null;
  intakeLogisticsLockedAt?: string | null;
  returnDeliveryMethod?: string | null;
  returnDeliveryFee?: number | null;
  returnLogisticsLockedAt?: string | null;
};

const money = (value: number) => Math.round((Math.max(0, value) + Number.EPSILON) * 100) / 100;
const normalized = (value?: string | null) => String(value ?? '').trim().toLowerCase();

export const calculateRetailRevenue = (input: RetailRevenueInput): number => {
  const productRevenue = money(input.productRevenueExVat);
  const refunded = money(input.refundedAmount);
  const grandTotal = money(input.orderGrandTotal);
  const fullyRefunded = grandTotal > 0 && refunded >= grandTotal - 0.01;
  const paid = ['paid', 'completed'].includes(normalized(input.paymentStatus));
  const shopOwned = normalized(input.carrierCompany) === 'shop-owned logistics';
  const deliveryRevenue = paid && shopOwned && !fullyRefunded ? money(input.shippingFee) : 0;

  return money(Math.max(0, productRevenue - Math.min(productRevenue, refunded)) + deliveryRevenue);
};

export const calculateRepairRevenue = (input: RepairRevenueInput): number => {
  const serviceGross = money(input.serviceGrossAmount);
  const serviceNet = money(input.serviceNetAmount);
  const intakeFee = normalized(input.intakeDeliveryMethod) === 'shop_pickup' && input.intakeLogisticsLockedAt
    ? money(input.intakeDeliveryFee ?? 0)
    : 0;
  const returnFee = normalized(input.returnDeliveryMethod) === 'shop_delivery' && input.returnLogisticsLockedAt
    ? money(input.returnDeliveryFee ?? 0)
    : 0;
  const deliveryRevenue = intakeFee + returnFee;
  const paymentStatus = normalized(input.paymentStatus);
  const fallbackServicePaid = paymentStatus === 'completed'
    ? serviceGross
    : ['paid', 'partially_paid'].includes(paymentStatus) && normalized(input.paymentPolicy) === 'full_upfront'
      ? serviceGross
      : ['paid', 'partially_paid'].includes(paymentStatus)
        ? serviceGross * 0.5
        : 0;
  const grossPaid = input.totalPaidAmount > 0
    ? money(input.totalPaidAmount)
    : fallbackServicePaid + deliveryRevenue;
  const netCollected = Math.max(0, grossPaid - money(input.refundedAmount));
  const realizedDelivery = Math.min(deliveryRevenue, netCollected);
  const serviceCollected = Math.max(0, netCollected - realizedDelivery);
  const serviceRatio = serviceGross > 0 ? Math.min(1, serviceCollected / serviceGross) : 0;

  return money((serviceNet * serviceRatio) + realizedDelivery);
};
