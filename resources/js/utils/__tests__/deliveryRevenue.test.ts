import { describe, expect, it } from 'vitest';
import {
  calculateRepairRevenue,
  calculateRetailRevenue,
} from '../deliveryRevenue';

describe('delivery revenue', () => {
  it('adds a paid shop-owned retail delivery fee', () => {
    expect(calculateRetailRevenue({
      productRevenueExVat: 100,
      shippingFee: 20,
      refundedAmount: 0,
      orderGrandTotal: 132,
      paymentStatus: 'Paid',
      carrierCompany: 'Shop-owned logistics',
    })).toBe(120);
  });

  it('excludes third-party and unpaid retail delivery fees', () => {
    const order = {
      productRevenueExVat: 100,
      shippingFee: 20,
      refundedAmount: 0,
      orderGrandTotal: 132,
    };

    expect(calculateRetailRevenue({
      ...order,
      paymentStatus: 'Paid',
      carrierCompany: 'Third-party courier',
    })).toBe(100);
    expect(calculateRetailRevenue({
      ...order,
      paymentStatus: 'Pending',
      carrierCompany: 'Shop-owned logistics',
    })).toBe(100);
  });

  it('handles full and partial retail refunds without reviving delivery revenue', () => {
    const order = {
      productRevenueExVat: 100,
      shippingFee: 20,
      orderGrandTotal: 132,
      paymentStatus: 'Paid',
      carrierCompany: 'Shop-owned logistics',
    };

    expect(calculateRetailRevenue({ ...order, refundedAmount: 132 })).toBe(0);
    expect(calculateRetailRevenue({ ...order, refundedAmount: 40 })).toBe(80);
  });

  it('adds only locked shop-owned repair delivery fees', () => {
    expect(calculateRepairRevenue({
      serviceGrossAmount: 1120,
      serviceNetAmount: 1000,
      totalPaidAmount: 1240,
      refundedAmount: 0,
      paymentStatus: 'completed',
      paymentPolicy: 'full_upfront',
      intakeDeliveryMethod: 'shop_pickup',
      intakeDeliveryFee: 50,
      intakeLogisticsLockedAt: '2026-07-26T00:00:00Z',
      returnDeliveryMethod: 'shop_delivery',
      returnDeliveryFee: 70,
      returnLogisticsLockedAt: '2026-07-26T00:00:00Z',
    })).toBe(1120);
  });

  it('caps repair service revenue when an included fee is no longer eligible', () => {
    expect(calculateRepairRevenue({
      serviceGrossAmount: 1120,
      serviceNetAmount: 1000,
      totalPaidAmount: 1170,
      refundedAmount: 0,
      paymentStatus: 'completed',
      paymentPolicy: 'full_upfront',
      intakeDeliveryMethod: 'shop_pickup',
      intakeDeliveryFee: 50,
      intakeLogisticsLockedAt: null,
      returnDeliveryMethod: 'customer_dropoff',
      returnDeliveryFee: 0,
      returnLogisticsLockedAt: null,
    })).toBe(1000);
  });

  it('removes all repair revenue after a full refund', () => {
    expect(calculateRepairRevenue({
      serviceGrossAmount: 1120,
      serviceNetAmount: 1000,
      totalPaidAmount: 1240,
      refundedAmount: 1240,
      paymentStatus: 'completed',
      paymentPolicy: 'full_upfront',
      intakeDeliveryMethod: 'shop_pickup',
      intakeDeliveryFee: 50,
      intakeLogisticsLockedAt: '2026-07-26T00:00:00Z',
      returnDeliveryMethod: 'shop_delivery',
      returnDeliveryFee: 70,
      returnLogisticsLockedAt: '2026-07-26T00:00:00Z',
    })).toBe(0);
  });
});
