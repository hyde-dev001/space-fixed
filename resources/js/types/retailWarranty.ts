export type WarrantyDurationUnit = 'days' | 'weeks' | 'months' | 'years';

export interface RetailWarrantySettingsPayload {
  enabled: boolean;
  title: string;
  duration_value: number;
  duration_unit: WarrantyDurationUnit;
  description: string | null;
  terms: string | null;
  exclusions: string | null;
  instructions: string | null;
  eligible_orders_from?: string | null;
}

export interface RetailWarrantyItemCoverage {
  id: number;
  order_item_id: number;
  product_name: string;
  size: string | null;
  color: string | null;
  covered_quantity: number;
  refunded_quantity: number;
  remaining_quantity: number;
  reserved_quantity: number;
  available_quantity: number;
  status: string;
  can_assess: boolean;
  start_date: string;
  expiration_date: string;
  policy: Omit<RetailWarrantySettingsPayload, 'enabled' | 'eligible_orders_from'>;
  void_reason: string | null;
}

export interface RetailWarrantyProjection {
  id: number;
  reference: string;
  order_number: string;
  customer_name: string;
  shop_name: string;
  issued_at: string;
  fulfilled_at: string;
  timezone: string;
  status: string;
  download_url: string | null;
  items: RetailWarrantyItemCoverage[];
}
