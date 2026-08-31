import React, { useState, useEffect, useMemo, useRef, useCallback } from 'react';
import { Head, Link } from '@inertiajs/react';
import Navigation from '../Shared/Navigation';
import Swal from '@/Pages/UserSide/Shared/UserModal';
import axios from 'axios';
import CustomerAddressManager, { type CustomerAddress } from '@/components/address/CustomerAddressManager';
import type { TrackingShipment } from '@/types/logistics';
import { refundStageLabel } from './refundWorkflow';
import { buildRepairBreakdown, type RepairTaxMode } from '../../../utils/repairPricing';
import type { PreferredReturnChannel } from './refundPayloadBuilder';
import { CustomerFooterReveal } from '../../../components/common/CustomerFooter';
import { useScrollReveal } from '../Shared/useScrollReveal';

const MAX_REFUND_IMAGE_SIZE_BYTES = 20 * 1024 * 1024;
const MAX_REFUND_VIDEO_SIZE_BYTES = 256 * 1024 * 1024;
const REPAIR_VAT_RATE_PERCENT = 12;
const REFUND_ALLOWED_IMAGE_MIME_TYPES = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
const REFUND_ALLOWED_VIDEO_MIME_TYPES = ['video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/x-matroska', 'video/webm'];
const REFUND_ALLOWED_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];
const REFUND_ALLOWED_VIDEO_EXTENSIONS = ['mp4', 'mov', 'avi', 'mkv', 'webm'];
const REFUND_MEDIA_ACCEPT = '.jpg,.jpeg,.png,.webp,.mp4,.mov,.avi,.mkv,.webm';
const MY_REPAIRS_POLL_INTERVAL_MS = 3000;

const getFileExtension = (fileName: string): string => {
  const pieces = fileName.toLowerCase().split('.');
  return pieces.length > 1 ? pieces[pieces.length - 1] : '';
};

const isAllowedRefundImageFile = (file: File): boolean => {
  const mimeType = String(file.type || '').toLowerCase();
  const extension = getFileExtension(file.name);

  if (REFUND_ALLOWED_IMAGE_MIME_TYPES.includes(mimeType)) {
    return true;
  }

  if (mimeType.startsWith('image/')) {
    return REFUND_ALLOWED_IMAGE_EXTENSIONS.includes(extension);
  }

  return REFUND_ALLOWED_IMAGE_EXTENSIONS.includes(extension);
};

const isAllowedRefundVideoFile = (file: File): boolean => {
  const mimeType = String(file.type || '').toLowerCase();
  const extension = getFileExtension(file.name);

  if (REFUND_ALLOWED_VIDEO_MIME_TYPES.includes(mimeType)) {
    return true;
  }

  if (mimeType.startsWith('video/')) {
    return REFUND_ALLOWED_VIDEO_EXTENSIONS.includes(extension);
  }

  return REFUND_ALLOWED_VIDEO_EXTENSIONS.includes(extension);
};


type RepairStatus = 'new_request' | 'assigned_to_repairer' | 'repairer_accepted' | 'waiting_customer_confirmation' | 'owner_approval_pending' | 'owner_approved' | 'owner_rejected' | 'in_progress' | 'awaiting_parts' | 'completed' | 'ready_for_pickup' | 'shipped' | 'picked_up' | 'pending' | 'received' | 'cancelled' | 'rejected' | 'repairer_rejected';
type RepairTab = 'new_request' | 'repairer_accepted' | 'waiting_customer_confirmation' | 'owner_approval_pending' | 'in_progress' | 'completed' | 'ready_for_pickup' | 'picked_up' | 'pending' | 'received' | 'cancelled' | 'rejected' | 'return_refund';
const REPAIR_TABS: RepairTab[] = [
  'new_request',
  'pending',
  'received',
  'in_progress',
  'ready_for_pickup',
  'picked_up',
  'return_refund',
  'cancelled',
  'rejected',
];

type RepairServiceSummary = {
  id: number;
  name: string;
  category?: string;
  price: number | string;
  duration?: string;
};

type RepairAddressSnapshot = {
  address_id?: number | null;
  name?: string;
  phone?: string;
  address_line?: string;
  barangay?: string;
  city?: string;
  province?: string;
  region?: string;
  postal_code?: string;
  latitude?: number | null;
  longitude?: number | null;
  delivery_instructions?: string | null;
  method?: string;
  version?: string | null;
  external_tracking?: {
    carrier?: string | null;
    tracking_number?: string | null;
    tracking_url?: string | null;
    updated_at?: string | null;
  } | null;
};

type DeliveryQuote = {
  available: boolean;
  reason?: string | null;
  distance_km?: number | null;
  coverage_radius_km?: number | null;
  fee?: number | null;
  estimate?: number | null;
  address_version?: string | null;
  method?: string | null;
};

type RepairOrder = {
  id: number;
  order_number: string;
  repair_type: string;
  services?: RepairServiceSummary[];
  description: string;
  status: RepairStatus;
  total_amount: number;
  created_at: string;
  estimated_completion?: string;
  estimated_delivery_date?: string | null;
  duration?: string;
  completed_at?: string;
  received_at?: string | null;
  shop_id?: number | null;
  shop_name: string;
  shop_address: string;
  image?: string;
  conversation_id?: number | null;
  delivery_method?: string;
  intake_delivery_method?: 'walk_in' | 'customer_delivery' | 'shop_pickup' | string;
  intake_address?: RepairAddressSnapshot | null;
  intake_delivery_fee?: number | null;
  intake_logistics_quote?: DeliveryQuote | null;
  intake_logistics_locked_at?: string | null;
  return_delivery_method?: 'walk_in' | 'customer_pickup' | 'shop_delivery' | string;
  return_address?: RepairAddressSnapshot | null;
  return_delivery_fee?: number | null;
  return_logistics_quote?: DeliveryQuote | null;
  return_logistics_locked_at?: string | null;
  return_address_confirmed_at?: string | null;
  return_address_confirmed_version?: string | null;
  pickup_recovery?: {
    state: 'awaiting_arrangement' | 'awaiting_payment' | 'resolved' | 'ready_for_dispatch';
    status?: string;
    action?: IntakeDeliveryMethod;
    scheduled_delivery_date?: string | null;
    delivery_window?: 'morning' | 'afternoon' | null;
  } | null;
  pickup_retry_payment_due?: boolean;
  return_recovery?: {
    code: string;
    label: string;
    state: 'returning_to_shop' | 'awaiting_arrangement' | 'awaiting_payment' | 'shop_pickup' | 'ready_for_dispatch';
    message?: string | null;
    actions_available?: boolean;
    shop?: { name?: string | null; address?: string | null } | null;
    scheduled_delivery_date?: string | null;
    delivery_window?: 'morning' | 'afternoon' | null;
  } | null;
  redelivery_payment_due?: boolean;
  same_as_intake_address?: boolean;
  pickup_address?: string | {
    address_line?: string;
    barangay?: string;
    city?: string;
    region?: string;
    postal_code?: string;
  } | null;
  payment_status?: string;
  payment_completed_at?: string | null;
  paymongo_link_id?: string | null;
  payment_enabled?: boolean;
  payment_enabled_at?: string | null;
  pickup_enabled?: boolean;
  pickup_enabled_at?: string | null;
  tracking_number?: string | null;
  carrier_company?: string | null;
  carrier_name?: string | null;
  carrier_phone?: string | null;
  tracking_link?: string | null;
  logistics_shipments?: Array<{ id: number; purpose: string }>;
  shipped_at?: string | null;
  assigned_repairer_id?: number | null;
  repairer_name?: string | null;
  payment_policy?: 'deposit_50' | 'full_upfront';
  collection_summary?: {
    collectible?: boolean;
    due_type?: string | null;
    phase?: string | null;
    collectible_amount?: number | null;
    outstanding_balance?: number | null;
    service_amount?: number | null;
    delivery_amount?: number | null;
    total_paid_amount?: number | null;
    grand_total?: number | null;
    fully_paid?: boolean;
  } | null;
  collectible?: boolean;
  collectible_amount?: number | null;
  outstanding_balance?: number | null;
  due_type?: string | null;
  phase?: string | null;
  fully_paid?: boolean;
  is_warranty_job?: boolean;
  parent_repair_request_id?: number | null;
  billing_mode?: string | null;
  warranty_display_alias?: string | null;
  shop_owner_id?: number | null;
  repair_package_id?: number | null;
  package_price?: number | null;
  add_ons_total?: number | null;
  materials_total?: number | null;
  final_total?: number | null;
  tax_mode?: string | null;
  vat_amount?: number | null;
  vat_rate?: number | null;
  grand_total?: number | null;
  total_paid_amount?: number | null;
  display_total_paid_amount?: number | null;
  total_refunded_amount?: number | null;
  has_review?: boolean;
  latest_pos_transaction_id?: number | null;
  refund_payment_type?: 'pure_online' | 'mixed' | 'manual_only' | string;
  refund_requires_payout_destination?: boolean;
  refund_original_method_only?: boolean;
  included_services_snapshot?: Array<{
    id: number;
    name: string;
    category?: string;
    price?: number;
    duration?: string;
  }> | null;
  add_on_services_snapshot?: Array<{
    id: number;
    name: string;
    category?: string;
    price?: number;
    duration?: string;
  }> | null;
  pricing_breakdown?: {
    mode?: string;
    tax_mode?: string;
    package_id?: number;
    package_name?: string;
    included_services_total?: number;
    package_price?: number;
    add_ons_total?: number;
    base_total?: number;
    materials_total?: number;
    final_total?: number;
  } | null;
};

type RepairRefundStatus = {
  id: number;
  status: string;
  repairer_status?: string | null;
  finance_status?: string | null;
  owner_status?: string | null;
  shop_owner_status?: string | null;
  requested_amount?: number;
  approved_amount?: number | null;
  requested_at?: string | null;
  failure_reason?: string | null;
  execution_channel?: string | null;
  execution_reference_masked?: string | null;
  execution_proof_urls?: string[];
  executed_at?: string | null;
};

type RepairWarrantyClaimStatus = {
  id: number;
  claim_no: string;
  status: string;
  reason_code?: string;
  source_channel?: string;
  preferred_return_method?: string;
  preferred_receive_method?: string;
  created_at?: string | null;
  reviewed_at?: string | null;
  rejection_reason?: string | null;
  approved_repair_request_id?: number | null;
};

type ConversationShop = {
  conversation_id?: number;
  unreadCount?: number;
};

type WarrantyIntakeMethod = 'walk_in' | 'customer_delivery' | 'shop_pickup';
type WarrantyReceiveMethod = 'walk_in' | 'customer_pickup' | 'shop_delivery';

const getMonthKey = (date: Date): string => {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  return `${year}-${month}`;
};

const REPAIR_TAB_NOTIFICATION_STORAGE_KEY = 'my_repairs_seen_tab_item_ids_v1';

const formatCurrency = (value?: number | null) => `₱${Number(value || 0).toLocaleString()}`;

const resolveOrderTaxMode = (order: RepairOrder): RepairTaxMode => {
  const value = String(order.tax_mode ?? order.pricing_breakdown?.tax_mode ?? '').toLowerCase();
  if (value === 'vat_inclusive') return 'vat_inclusive';
  if (value === 'legacy_add_on') return 'legacy_add_on';
  return 'legacy_additive';
};

const getOrderVatRate = (order: RepairOrder) => {
  const parsed = Number(order.vat_rate);
  return Number.isFinite(parsed) && parsed >= 0 ? parsed : REPAIR_VAT_RATE_PERCENT;
};

const getOrderBreakdown = (order: RepairOrder) => {
  const baseTotal = Number(order.pricing_breakdown?.base_total);
  const finalTotal = Number.isFinite(baseTotal)
    ? baseTotal
    : Number(order.final_total ?? order.total_amount ?? 0);
  return buildRepairBreakdown({
    finalTotal: Number.isFinite(finalTotal) ? finalTotal : 0,
    vatRate: getOrderVatRate(order),
    taxMode: resolveOrderTaxMode(order),
  });
};

const getOrderSubtotal = (order: RepairOrder) => getOrderBreakdown(order).netSubtotal;

const getOrderVatAmount = (order: RepairOrder) => {
  const parsed = Number(order.vat_amount);
  if (Number.isFinite(parsed) && parsed >= 0) {
    return parsed;
  }

  return getOrderBreakdown(order).vatAmount;
};

const getOrderGrandTotal = (order: RepairOrder) => {
  const parsedGrandTotal = Number(order.grand_total);
  if (Number.isFinite(parsedGrandTotal) && parsedGrandTotal > 0) {
    return parsedGrandTotal;
  }

  return getOrderBreakdown(order).grandTotal;
};

const getOrderDisplayedPaidAmount = (order: RepairOrder) => {
  const paid = Number(
    order.collection_summary?.total_paid_amount
      ?? order.display_total_paid_amount
      ?? order.total_paid_amount
      ?? 0,
  );

  return Number.isFinite(paid) && paid > 0 ? paid : 0;
};

const getOrderOutstandingBalance = (order: RepairOrder): number => {
  const outstanding = Number(
    order.collection_summary?.outstanding_balance
      ?? order.outstanding_balance
      ?? 0,
  );

  return Number.isFinite(outstanding) && outstanding > 0 ? outstanding : 0;
};

const isInitialPaymentDue = (order: RepairOrder): boolean => {
  if (order.collection_summary !== undefined) {
    return order.collection_summary?.phase === 'initial'
      && Boolean(order.collection_summary?.collectible)
      && Number(order.collection_summary?.collectible_amount ?? 0) > 0;
  }

  return !['paid', 'completed'].includes(String(order.payment_status ?? '').toLowerCase());
};

const isWarrantyNoChargeOrder = (order: RepairOrder): boolean => {
  return Boolean(order.is_warranty_job) || String(order.billing_mode || '').toLowerCase() === 'warranty_no_charge';
};

const normalizeProofUrlForBrowser = (value: string): string => {
  const trimmed = value.trim();
  if (trimmed === '') return '';

  if (/^(https?:|data:|blob:)/i.test(trimmed)) {
    return trimmed;
  }

  if (trimmed.startsWith('//')) {
    const protocol = typeof window !== 'undefined' ? window.location.protocol : 'https:';
    return `${protocol}${trimmed}`;
  }

  const normalizedPath = trimmed.startsWith('/') ? trimmed : `/${trimmed.replace(/^\/+/, '')}`;

  if (typeof window === 'undefined') {
    return normalizedPath;
  }

  try {
    return new URL(normalizedPath, window.location.origin).toString();
  } catch {
    return normalizedPath;
  }
};

const isProofImageUrl = (value: string): boolean => /\.(png|jpe?g|webp|gif|bmp|svg)(\?.*)?$/i.test(value);

const getIntakeMethod = (order: RepairOrder): 'walk_in' | 'customer_delivery' | 'shop_pickup' => {
  if (
    order.intake_delivery_method === 'walk_in'
    || order.intake_delivery_method === 'customer_delivery'
    || order.intake_delivery_method === 'shop_pickup'
  ) {
    return order.intake_delivery_method;
  }

  return order.delivery_method === 'walk_in' ? 'walk_in' : 'customer_delivery';
};

const getReturnMethod = (order: RepairOrder): 'walk_in' | 'customer_pickup' | 'shop_delivery' => {
  if (
    order.return_delivery_method === 'walk_in' ||
    order.return_delivery_method === 'customer_pickup' ||
    order.return_delivery_method === 'shop_delivery'
  ) {
    return order.return_delivery_method;
  }

  // The legacy delivery_method describes intake only. A missing return method
  // must never silently opt a repair into shop rider delivery.
  return order.delivery_method === 'walk_in' ? 'walk_in' : 'customer_pickup';
};

const isOnlineIntakeFlow = (order: RepairOrder): boolean => {
  return getIntakeMethod(order) !== 'walk_in';
};

const getIntakeMethodLabel = (order: RepairOrder): string => {
  const method = getIntakeMethod(order);
  if (method === 'walk_in') return 'Walk-in Delivery to Shop';
  if (method === 'shop_pickup') return 'Shop rider pickup';
  return 'Customer-arranged delivery';
};

const getReturnMethodLabel = (order: RepairOrder): string => {
  const returnMethod = getReturnMethod(order);

  if (returnMethod === 'walk_in') {
    return 'Customer Pick-up at Shop';
  }

  return returnMethod === 'customer_pickup'
    ? 'Customer-arranged courier'
    : 'Shop rider delivery';
};

type IntakeDeliveryMethod = 'walk_in' | 'customer_delivery' | 'shop_pickup';
type ReturnDeliveryMethod = 'walk_in' | 'customer_pickup' | 'shop_delivery';

type CoverageAddress = {
  id?: number | null;
  address_id?: number | null;
  version?: string | null;
  latitude?: number | null;
  longitude?: number | null;
  address_line?: string | null;
  barangay?: string | null;
  city?: string | null;
  province?: string | null;
  region?: string | null;
  postal_code?: string | null;
};

const getCoverageAddressKey = (
  address?: CoverageAddress | null,
  fallbackId?: number | null,
): string => [
  Number(fallbackId ?? address?.id ?? address?.address_id ?? 0),
  address?.version ?? '',
  address?.latitude ?? '',
  address?.longitude ?? '',
  address?.address_line ?? '',
  address?.barangay ?? '',
  address?.city ?? '',
  address?.province ?? address?.region ?? '',
  address?.postal_code ?? '',
].join('|');

const formatRepairAddress = (address?: RepairAddressSnapshot | null): string => {
  return [
    address?.address_line,
    address?.barangay,
    address?.city,
    address?.province ?? address?.region,
    address?.postal_code,
  ].filter(Boolean).join(', ');
};

const getWarrantyAddress = (
  order: RepairOrder | undefined,
  leg: 'intake' | 'return',
): RepairAddressSnapshot | null => {
  if (!order) return null;
  const candidates = leg === 'intake'
    ? [order.intake_address, order.pickup_address, order.return_address]
    : [order.return_address, order.intake_address, order.pickup_address];

  return candidates.find((address) => Number(address?.address_id || 0) > 0) ?? null;
};

const getRequestErrorMessage = (error: unknown, fallback: string): string => {
  const data = (error as {
    response?: {
      data?: {
        message?: unknown;
        errors?: Record<string, unknown>;
      };
    };
  })?.response?.data;

  if (typeof data?.message === 'string' && data.message.trim()) {
    return data.message;
  }

  const firstValidationError = Object.values(data?.errors ?? {}).flat().find(
    (entry) => typeof entry === 'string' && entry.trim(),
  );

  return typeof firstValidationError === 'string' ? firstValidationError : fallback;
};

const SponsoredIntakeReplanCard: React.FC<{
  order: RepairOrder;
  onRefresh: () => Promise<unknown>;
  onPay: () => void;
}> = ({ order, onRefresh, onPay }) => {
  const currentAddressId = order.intake_address?.address_id
    ? Number(order.intake_address.address_id)
    : null;
  const [method, setMethod] = useState<IntakeDeliveryMethod>(() => getIntakeMethod(order));
  const [selectedAddress, setSelectedAddress] = useState<CustomerAddress | null>(null);
  const [coverage, setCoverage] = useState<DeliveryQuote | null>(null);
  const [coverageLoading, setCoverageLoading] = useState(false);
  const [coverageError, setCoverageError] = useState<string | null>(null);
  const [deliveryDate, setDeliveryDate] = useState('');
  const [deliveryWindow, setDeliveryWindow] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const effectiveAddressId = selectedAddress?.id ?? currentAddressId;
  const shopId = order.shop_owner_id ?? order.shop_id;
  const addressRequired = method !== 'walk_in';
  const shopPickupUnavailable = method === 'shop_pickup'
    && (coverageLoading || !coverage?.available || !deliveryDate || !deliveryWindow);

  useEffect(() => {
    if (!shopId || !effectiveAddressId) {
      return;
    }

    let active = true;
    setCoverageLoading(true);
    setCoverageError(null);

    fetch(`/api/repair/shops/${shopId}/delivery-quote?address_id=${effectiveAddressId}`, {
      headers: { Accept: 'application/json' },
      credentials: 'include',
    })
      .then(async (response) => {
        const payload = await response.json();
        if (!response.ok) {
          throw new Error(payload?.message || 'Unable to check shop rider coverage.');
        }
        return (payload?.data ?? payload) as DeliveryQuote;
      })
      .then((quote) => {
        if (!active) return;
        setCoverage(quote);
      })
      .catch((reason: Error) => {
        if (!active) return;
        setCoverage(null);
        setCoverageError(reason.message || 'Unable to check shop rider coverage.');
      })
      .finally(() => {
        if (active) setCoverageLoading(false);
      });

    return () => {
      active = false;
    };
  }, [effectiveAddressId, shopId]);

  const handleAddressSelect = useCallback((address: CustomerAddress) => {
    setSelectedAddress(address);
    setCoverage(null);
    setCoverageError(null);
    setError(null);
    setSuccess(null);
  }, []);

  const handleRebook = async () => {
    if (addressRequired && !effectiveAddressId) {
      setError('Choose one of your saved addresses.');
      return;
    }

    if (method === 'shop_pickup' && !coverage?.available) {
      setError(coverage?.reason || 'Choose an address within shop rider coverage.');
      return;
    }

    setSaving(true);
    setError(null);
    setSuccess(null);

    try {
      const response = await axios.post(`/api/customer/repairs/${order.id}/pickup-recovery`, {
        method,
        ...(addressRequired ? { address_id: effectiveAddressId } : {}),
        ...(method === 'shop_pickup' ? {
          delivery_date: deliveryDate,
          delivery_window: deliveryWindow,
        } : {}),
      });
      setSuccess(response.data?.message || 'Intake plan updated.');
      await onRefresh();
    } catch (reason) {
      setError(getRequestErrorMessage(reason, 'Unable to update the intake plan.'));
    } finally {
      setSaving(false);
    }
  };

  if (order.pickup_recovery?.state === 'awaiting_payment') {
    const scheduledDate = order.pickup_recovery.scheduled_delivery_date
      ? new Date(`${order.pickup_recovery.scheduled_delivery_date}T00:00:00`).toLocaleDateString('en-US', {
          month: 'short', day: 'numeric', year: 'numeric',
        })
      : 'Schedule pending';
    const scheduledWindow = order.pickup_recovery.delivery_window
      ? order.pickup_recovery.delivery_window[0].toUpperCase() + order.pickup_recovery.delivery_window.slice(1)
      : 'Window pending';

    return (
      <section aria-label="Warranty pickup recovery" className="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-4 sm:p-6">
        <h4 className="text-lg font-bold text-black">Pickup scheduled—payment required</h4>
        <p className="mt-2 text-sm font-semibold text-gray-800">{scheduledDate} · {scheduledWindow}</p>
        <p className="mt-1 text-sm text-gray-700">{formatRepairAddress(order.intake_address)}</p>
        <div className="mt-4 flex items-center justify-between gap-4 rounded-xl border border-amber-200 bg-white p-3 text-sm">
          <span className="font-semibold text-gray-700">Pickup shipping fee</span>
          <span className="font-bold text-black">{formatCurrency(order.intake_delivery_fee)}</span>
        </div>
        <button
          type="button"
          onClick={onPay}
          disabled={!order.pickup_retry_payment_due || !order.payment_enabled}
          className="mt-4 min-h-12 w-full rounded-xl bg-[#16233b] px-4 py-3 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:bg-gray-300 sm:w-auto"
        >
          Pay pickup shipping fee
        </button>
      </section>
    );
  }

  if (order.pickup_recovery?.state === 'resolved' || order.pickup_recovery?.state === 'ready_for_dispatch') {
    return (
      <section aria-label="Warranty pickup recovery" className="mt-6 rounded-2xl border border-green-200 bg-green-50 p-4 text-sm text-green-800">
        Pickup arrangement confirmed. Watch this page for the next rider update.
      </section>
    );
  }

  return (
    <section aria-label="Warranty pickup recovery" className="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-4 sm:p-6">
      <h4 className="text-lg font-bold text-black">Pickup failed—action required</h4>
      <p className="mt-1 text-sm text-gray-600">
        Choose how to get the shoes back to the shop.
      </p>

      <fieldset className="mt-4 space-y-3">
        <legend className="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500">
          Intake method
        </legend>
        <label className="flex items-start gap-3 text-sm text-gray-800">
          <input
            type="radio"
            name={`sponsored-intake-method-${order.id}`}
            checked={method === 'walk_in'}
            disabled={saving}
            onChange={() => {
              setMethod('walk_in');
              setError(null);
              setSuccess(null);
            }}
          />
          <span>
            <span className="block font-semibold">Walk-in delivery to shop</span>
            <span className="text-xs text-gray-500">Bring the shoes directly to the shop.</span>
          </span>
        </label>
        <label className="flex items-start gap-3 text-sm text-gray-800">
          <input
            type="radio"
            name={`sponsored-intake-method-${order.id}`}
            checked={method === 'customer_delivery'}
            disabled={saving}
            onChange={() => {
              setMethod('customer_delivery');
              setError(null);
              setSuccess(null);
            }}
          />
          <span>
            <span className="block font-semibold">Customer-arranged delivery</span>
            <span className="text-xs text-gray-500">Use your own third-party courier.</span>
          </span>
        </label>
        <label className="flex items-start gap-3 text-sm text-gray-800">
          <input
            type="radio"
            name={`sponsored-intake-method-${order.id}`}
            checked={method === 'shop_pickup'}
            disabled={saving || coverageLoading || !coverage?.available}
            onChange={() => {
              setMethod('shop_pickup');
              setError(null);
              setSuccess(null);
            }}
          />
          <span>
            <span className="block font-semibold">Shop rider pickup</span>
            <span className="text-xs text-gray-500">Schedule and pay for a new pickup from a supported address.</span>
          </span>
        </label>
      </fieldset>

      {addressRequired && (
        <div className="mt-4">
          <CustomerAddressManager
            onSelect={handleAddressSelect}
            onSelectionCleared={() => setSelectedAddress(null)}
            initialAddressId={currentAddressId}
            disabled={saving}
            title="Saved pickup address"
            description="Choose or pin the address for this intake plan."
          />
        </div>
      )}

      {method === 'shop_pickup' && (
        <div className="mt-4 grid gap-3 sm:grid-cols-2">
          <label className="text-sm font-semibold text-gray-800">
            Pickup date
            <input
              type="date"
              value={deliveryDate}
              onChange={(event) => setDeliveryDate(event.target.value)}
              className="mt-1 min-h-12 w-full rounded-xl border border-gray-300 bg-white px-3 text-base text-gray-900"
            />
          </label>
          <label className="text-sm font-semibold text-gray-800">
            Pickup window
            <select
              value={deliveryWindow}
              onChange={(event) => setDeliveryWindow(event.target.value)}
              className="mt-1 min-h-12 w-full rounded-xl border border-gray-300 bg-white px-3 text-base text-gray-900"
            >
              <option value="">Choose a time</option>
              <option value="morning">Morning</option>
              <option value="afternoon">Afternoon</option>
            </select>
          </label>
        </div>
      )}

      <div className="mt-4 rounded-xl border border-gray-200 bg-white p-3 text-sm">
        {coverageLoading ? (
          <p className="font-semibold text-gray-600">Checking coverage...</p>
        ) : coverage?.available ? (
          <p className="font-semibold text-green-700">Within coverage · Pickup fee {formatCurrency(coverage.fee)}</p>
        ) : (
          <p className="font-semibold text-amber-700">
            {coverage?.reason || 'Shop rider coverage is not available for this address.'}
          </p>
        )}
        {coverageError && <p className="mt-1 text-red-700">{coverageError}</p>}
      </div>

      {error && (
        <p role="alert" className="mt-4 rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
          {error}
        </p>
      )}
      {success && (
        <p role="status" className="mt-4 rounded-xl border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-700">
          {success}
        </p>
      )}

      <button
        type="button"
        onClick={handleRebook}
        disabled={saving || (addressRequired && !effectiveAddressId) || shopPickupUnavailable}
        className="mt-4 inline-flex w-full items-center justify-center rounded-full bg-[#16233b] px-5 py-3 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:bg-gray-300 sm:w-auto"
      >
        {saving ? 'Saving...' : 'Save intake plan'}
      </button>
    </section>
  );
};

const CustomerReturnRecoveryActions: React.FC<{
  order: RepairOrder;
  onRefresh: () => Promise<unknown>;
}> = ({ order, onRefresh }) => {
  const [deliveryDate, setDeliveryDate] = useState('');
  const [deliveryWindow, setDeliveryWindow] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const tomorrowDate = new Date();
  tomorrowDate.setDate(tomorrowDate.getDate() + 1);
  const tomorrow = [
    tomorrowDate.getFullYear(),
    String(tomorrowDate.getMonth() + 1).padStart(2, '0'),
    String(tomorrowDate.getDate()).padStart(2, '0'),
  ].join('-');

  const chooseRecovery = async (action: 'schedule_redelivery' | 'shop_pickup') => {
    if (action === 'schedule_redelivery' && (!deliveryDate || !deliveryWindow)) {
      setError('Choose a delivery date and time window.');
      return;
    }

    setSaving(true);
    setError(null);
    setSuccess(null);
    try {
      const response = await axios.post(
        `/api/customer/repairs/${order.id}/return-recovery`,
        action === 'schedule_redelivery'
          ? {
              action,
              scheduled_delivery_date: deliveryDate,
              delivery_window: deliveryWindow,
            }
          : { action },
      );
      setSuccess(response.data?.message || 'Return arrangement saved.');
      await onRefresh();
    } catch (reason) {
      setError(getRequestErrorMessage(reason, 'Unable to save your return arrangement.'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="mt-4 space-y-4">
      {(order.return_recovery?.shop?.name || order.return_recovery?.shop?.address) && (
        <div className="rounded-xl border border-amber-200 bg-white p-3 text-sm text-gray-700">
          <p className="font-semibold text-black">{order.return_recovery.shop.name}</p>
          <p className="mt-1">{order.return_recovery.shop.address}</p>
        </div>
      )}
      <div className="grid gap-3 sm:grid-cols-2">
        <label className="text-sm font-semibold text-gray-800">
          Re-delivery date
          <input
            type="date"
            min={tomorrow}
            value={deliveryDate}
            onChange={(event) => setDeliveryDate(event.target.value)}
            className="mt-1 min-h-12 w-full rounded-xl border border-gray-300 bg-white px-3 text-base text-gray-900"
          />
        </label>
        <label className="text-sm font-semibold text-gray-800">
          Delivery window
          <select
            value={deliveryWindow}
            onChange={(event) => setDeliveryWindow(event.target.value)}
            className="mt-1 min-h-12 w-full rounded-xl border border-gray-300 bg-white px-3 text-base text-gray-900"
          >
            <option value="">Choose a time</option>
            <option value="morning">Morning</option>
            <option value="afternoon">Afternoon</option>
          </select>
        </label>
      </div>

      {error && (
        <p role="alert" className="rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
          {error}
        </p>
      )}
      {success && (
        <p role="status" className="rounded-xl border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-700">
          {success}
        </p>
      )}

      <div className="grid gap-3 sm:grid-cols-2">
        <button
          type="button"
          onClick={() => chooseRecovery('schedule_redelivery')}
          disabled={saving || !deliveryDate || !deliveryWindow}
          className="min-h-12 rounded-xl bg-[#16233b] px-4 py-3 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:bg-gray-300"
        >
          {saving ? 'Saving...' : 'Schedule re-delivery'}
        </button>
        <button
          type="button"
          onClick={() => chooseRecovery('shop_pickup')}
          disabled={saving}
          className="min-h-12 rounded-xl border border-[#16233b] bg-white px-4 py-3 text-sm font-semibold text-[#16233b] disabled:cursor-not-allowed disabled:border-gray-300 disabled:text-gray-400"
        >
          Set for shop pickup
        </button>
      </div>
    </div>
  );
};

const ReturnDeliveryPlanCard: React.FC<{
  order: RepairOrder;
  onRefresh: () => Promise<unknown>;
}> = ({ order, onRefresh }) => {
  const initialMethod = getReturnMethod(order);
  const initialSameAsIntake = order.same_as_intake_address ?? true;
  const initialAddress = initialSameAsIntake ? order.intake_address : order.return_address;
  const initialAddressId = initialSameAsIntake
    ? order.intake_address?.address_id
      ? Number(order.intake_address.address_id)
      : null
    : order.return_address?.address_id
      ? Number(order.return_address.address_id)
      : null;
  const initialQuote = order.return_logistics_quote;
  const initialCoverageKey = initialMethod === 'shop_delivery'
    && initialQuote
    && initialAddressId
    && (!initialQuote.method || initialQuote.method === 'shop_delivery')
    && (!initialQuote.address_version
      || !initialAddress?.version
      || initialQuote.address_version === initialAddress.version)
    ? ['shop_delivery', getCoverageAddressKey(initialAddress, initialAddressId)].join(':')
    : null;
  const [method, setMethod] = useState<ReturnDeliveryMethod>(initialMethod);
  const [sameAsIntake, setSameAsIntake] = useState(initialSameAsIntake);
  const [selectedAddress, setSelectedAddress] = useState<CustomerAddress | null>(null);
  const [coverage, setCoverage] = useState<DeliveryQuote | null>(
    initialCoverageKey ? initialQuote : null,
  );
  const [coverageKey, setCoverageKey] = useState<string | null>(initialCoverageKey);
  const [coverageLoading, setCoverageLoading] = useState(false);
  const [coverageError, setCoverageError] = useState<string | null>(null);
  const [acceptedFee, setAcceptedFee] = useState(
    initialMethod === 'shop_delivery' ? Number(order.return_delivery_fee ?? 0) : 0,
  );
  const [saving, setSaving] = useState(false);
  const [dirty, setDirty] = useState(false);
  const [planSavedLocally, setPlanSavedLocally] = useState(false);
  const [confirmedLocally, setConfirmedLocally] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const coverageRequestKeyRef = useRef<string | null>(null);

  const locked = Boolean(order.return_logistics_locked_at);
  const returnAddressId = order.return_address?.address_id
    ? Number(order.return_address.address_id)
    : null;
  const intakeAddressId = order.intake_address?.address_id
    ? Number(order.intake_address.address_id)
    : null;
  const effectiveAddressId = sameAsIntake
    ? intakeAddressId
    : selectedAddress?.id ?? returnAddressId;
  const effectiveAddress = sameAsIntake
    ? order.intake_address
    : selectedAddress ?? order.return_address;
  const shopId = order.shop_owner_id ?? order.shop_id;
  const currentCoverageKey = method === 'shop_delivery' && shopId && effectiveAddressId
    ? ['shop_delivery', getCoverageAddressKey(effectiveAddress, effectiveAddressId)].join(':')
    : null;
  const serverConfirmationIsCurrent = Boolean(
    order.return_address_confirmed_at
    && order.return_address_confirmed_version
    && order.return_address_confirmed_version === order.return_address?.version,
  );
  const isConfirmed = confirmedLocally || (!dirty && !planSavedLocally && serverConfirmationIsCurrent);
  const serverOutstandingBalance = getOrderOutstandingBalance(order);
  const serverDeliveryAmount = Number(order.collection_summary?.delivery_amount ?? 0);
  const serviceOutstandingBalance = Math.max(0, serverOutstandingBalance - serverDeliveryAmount);
  const displayedFee = method === 'shop_delivery'
    ? (dirty ? Number(coverage?.fee ?? 0) : acceptedFee)
    : 0;
  const finalAmount = order.redelivery_payment_due
    ? displayedFee
    : dirty
      ? serviceOutstandingBalance + displayedFee
      : serverOutstandingBalance;

  useEffect(() => {
    coverageRequestKeyRef.current = currentCoverageKey;

    if (!currentCoverageKey || !effectiveAddressId) {
      setCoverage(null);
      setCoverageKey(null);
      setCoverageError(null);
      setCoverageLoading(false);
      setAcceptedFee(0);
      return;
    }

    if (coverageKey === currentCoverageKey && coverage) {
      setCoverageLoading(false);
      return;
    }

    let active = true;
    const requestKey = currentCoverageKey;
    setCoverageLoading(true);
    setCoverage(null);
    setCoverageKey(null);
    setCoverageError(null);
    setAcceptedFee(0);

    fetch(`/api/repair/shops/${shopId}/delivery-quote?address_id=${effectiveAddressId}`, {
      headers: { Accept: 'application/json' },
      credentials: 'include',
    })
      .then(async (response) => {
        const payload = await response.json();
        if (!response.ok) {
          throw new Error(payload?.message || 'Unable to check shop rider coverage.');
        }
        return (payload?.data ?? payload) as DeliveryQuote;
      })
      .then((quote) => {
        if (!active || coverageRequestKeyRef.current !== requestKey) return;
        setCoverage(quote);
        setCoverageKey(requestKey);
      })
      .catch((reason: Error) => {
        if (!active || coverageRequestKeyRef.current !== requestKey) return;
        setCoverage(null);
        setCoverageKey(null);
        setCoverageError(reason.message || 'Unable to check shop rider coverage.');
      })
      .finally(() => {
        if (active && coverageRequestKeyRef.current === requestKey) setCoverageLoading(false);
      });

    return () => {
      active = false;
    };
  }, [currentCoverageKey, effectiveAddressId, shopId]);

  const markEdited = () => {
    setDirty(true);
    setPlanSavedLocally(false);
    setConfirmedLocally(false);
    setError(null);
    setSuccess(null);
  };

  const invalidateCoverage = useCallback(() => {
    coverageRequestKeyRef.current = null;
    setCoverage(null);
    setCoverageKey(null);
    setCoverageLoading(false);
    setCoverageError(null);
    setAcceptedFee(0);
  }, []);

  const handleAddressSelect = useCallback((address: CustomerAddress) => {
    setSelectedAddress(address);
    invalidateCoverage();
    setDirty(true);
    setPlanSavedLocally(false);
    setConfirmedLocally(false);
    setError(null);
    setSuccess(null);
  }, [invalidateCoverage]);

  const handleSameAsIntakeChange = (checked: boolean) => {
    setSameAsIntake(checked);
    setSelectedAddress(null);
    invalidateCoverage();
    markEdited();
  };

  const handleAddressCleared = () => {
    setSelectedAddress(null);
    invalidateCoverage();
    markEdited();
  };

  const handleConfirm = async () => {
    setError(null);
    setSuccess(null);

    if (method !== 'walk_in' && !effectiveAddressId) {
      setError('Select a saved return address before confirming delivery.');
      return;
    }

    if (method === 'shop_delivery' && !coverage?.available) {
      setError(coverage?.reason || 'Shop rider delivery is not available for this address.');
      return;
    }

    setSaving(true);

    try {
      if (!locked && dirty) {
        const response = await axios.patch(`/api/customer/repairs/${order.id}/delivery-method`, {
          return_delivery_method: method,
          ...(method !== 'walk_in' && effectiveAddressId
            ? { return_address_id: effectiveAddressId }
            : {}),
          same_as_intake_address: sameAsIntake,
        });
        const updated = response.data ?? {};
        const nextMethod: ReturnDeliveryMethod = [
          'walk_in',
          'customer_pickup',
          'shop_delivery',
        ].includes(updated.return_delivery_method)
          ? updated.return_delivery_method
          : method;
        const nextAddress = updated.return_address ?? effectiveAddress;
        const nextAddressId = Number(
          nextAddress?.address_id ?? effectiveAddressId ?? 0,
        ) || null;
        const nextCoverageKey = nextMethod === 'shop_delivery' && nextAddressId
          ? ['shop_delivery', getCoverageAddressKey(nextAddress, nextAddressId)].join(':')
          : null;

        setMethod(nextMethod);
        if (nextMethod === 'shop_delivery') {
          const nextQuote = updated.return_logistics_quote ?? null;
          setCoverage(nextQuote);
          setCoverageKey(nextQuote && nextCoverageKey ? nextCoverageKey : null);
          setAcceptedFee(Number(updated.return_delivery_fee ?? 0));
        } else {
          invalidateCoverage();
        }
        setDirty(false);
        setPlanSavedLocally(true);
        setSuccess('Delivery plan updated. Review the new fee, then confirm.');
        await onRefresh();
        return;
      }

      const confirmation = await axios.post(
        `/api/customer/repairs/${order.id}/confirm-return-address`,
      );
      setDirty(false);
      setPlanSavedLocally(false);
      setConfirmedLocally(true);
      setSuccess(confirmation.data?.message || 'Return address and delivery confirmed.');
      await onRefresh();
    } catch (reason) {
      setError(getRequestErrorMessage(reason, 'Unable to confirm the return delivery plan.'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <section className="mt-6 rounded-2xl border border-gray-200 bg-gray-50 p-4 sm:p-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h4 className="text-lg font-bold text-black">Return delivery plan</h4>
          <p className="mt-1 text-sm text-gray-600">
            {order.redelivery_payment_due
              ? 'Confirm your return address, then pay the new delivery fee.'
              : 'Review the exact return address and method before final payment and dispatch.'}
          </p>
        </div>
        {locked && (
          <span className="rounded-full bg-gray-200 px-3 py-1 text-xs font-semibold text-gray-700">
            Locked after payment
          </span>
        )}
      </div>

      <fieldset className="mt-5 space-y-3">
        <legend className="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500">
          Return method
        </legend>
        <label className="flex items-start gap-3 text-sm text-gray-800">
          <input
            type="radio"
            name={`return-method-${order.id}`}
            value="walk_in"
            checked={method === 'walk_in'}
            disabled={locked || saving}
            onChange={() => {
              setMethod('walk_in');
              invalidateCoverage();
              markEdited();
            }}
          />
          <span>
            <span className="block font-semibold">Customer pickup at shop</span>
            <span className="text-xs text-gray-500">Collect the repaired shoes directly from the shop.</span>
          </span>
        </label>
        <label className="flex items-start gap-3 text-sm text-gray-800">
          <input
            type="radio"
            name={`return-method-${order.id}`}
            value="customer_pickup"
            checked={method === 'customer_pickup'}
            disabled={locked || saving}
            onChange={() => {
              setMethod('customer_pickup');
              invalidateCoverage();
              markEdited();
            }}
          />
          <span>
            <span className="block font-semibold">Customer-arranged courier</span>
            <span className="text-xs text-gray-500">Arrange and pay for your own third-party courier.</span>
          </span>
        </label>
        {(coverage?.available || method === 'shop_delivery' || Boolean(effectiveAddressId)) && (
          <label className="flex items-start gap-3 text-sm text-gray-800">
            <input
              type="radio"
              name={`return-method-${order.id}`}
              value="shop_delivery"
              checked={method === 'shop_delivery'}
              disabled={locked || saving || !effectiveAddressId}
              onChange={() => {
                setMethod('shop_delivery');
                invalidateCoverage();
                markEdited();
              }}
            />
            <span>
              <span className="block font-semibold">Shop rider delivery</span>
              <span className="text-xs text-gray-500">The shop handles this return with its own rider after coverage is confirmed.</span>
            </span>
          </label>
        )}
      </fieldset>

      <label className="mt-5 flex items-center gap-3 text-sm font-semibold text-gray-800">
        <input
          type="checkbox"
          checked={sameAsIntake}
          disabled={locked || saving}
          onChange={(event) => handleSameAsIntakeChange(event.target.checked)}
        />
        Same as intake address
      </label>

      {sameAsIntake ? (
        <div className="mt-3 rounded-xl border border-gray-200 bg-white p-3 text-sm text-gray-700">
          {formatRepairAddress(order.intake_address) || 'No intake address is available.'}
        </div>
      ) : (
        <div className="mt-4">
          <CustomerAddressManager
            onSelect={handleAddressSelect}
            onSelectionCleared={handleAddressCleared}
            initialAddressId={returnAddressId}
            disabled={locked || saving}
            title="Saved return address"
            description="Choose or pin the address to use for this repair return."
          />
        </div>
      )}

      <div className="mt-5 grid gap-3 rounded-xl border border-gray-200 bg-white p-4 text-sm sm:grid-cols-2">
        <div>
          {method !== 'shop_delivery' ? (
            <p className="font-semibold text-gray-600">
              {method === 'customer_pickup'
                ? 'Third-party courier selected. Shop rider coverage and fee do not apply.'
                : 'Customer pickup selected. Shop rider coverage and fee do not apply.'}
            </p>
          ) : coverageLoading ? (
            <p className="font-semibold text-gray-600">Checking coverage…</p>
          ) : coverage?.available ? (
            <>
              <p className="font-semibold text-green-700">Within coverage</p>
              {Number.isFinite(Number(coverage.distance_km)) && (
                <p className="mt-1 text-gray-600">Distance: {Number(coverage.distance_km)} km</p>
              )}
            </>
          ) : (
            <p className="font-semibold text-amber-700">
              {coverage?.reason || 'Shop rider coverage is not available for this address.'}
            </p>
          )}
          {method === 'shop_delivery' && coverageError && (
            <p className="mt-1 text-sm text-red-700">{coverageError}</p>
          )}
        </div>
        <div className="sm:text-right">
          <p className="font-semibold text-gray-800">
            {method === 'shop_delivery'
              ? (dirty ? 'Estimated return fee' : 'Accepted return fee')
              : 'Shop rider fee not applicable'}: {formatCurrency(displayedFee)}
          </p>
          <p className="mt-1 font-bold text-black">Final amount: {formatCurrency(finalAmount)}</p>
        </div>
      </div>

      {error && (
        <p role="alert" className="mt-4 rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
          {error}
        </p>
      )}
      {success && (
        <p role="status" className="mt-4 rounded-xl border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-700">
          {success}
        </p>
      )}

      <button
        type="button"
        onClick={handleConfirm}
        disabled={saving || isConfirmed}
        className="mt-5 inline-flex w-full items-center justify-center rounded-full bg-[#16233b] px-5 py-3 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:bg-gray-300 sm:w-auto"
      >
        {saving
          ? dirty
            ? 'Saving…'
            : 'Confirming…'
          : isConfirmed
            ? 'Address & delivery confirmed'
            : dirty
              ? 'Save & review delivery plan'
              : 'Confirm address & delivery'}
      </button>
    </section>
  );
};

const CustomerExternalTrackingCard: React.FC<{
  order: RepairOrder;
  leg: 'intake' | 'return';
  onRefresh: () => Promise<unknown>;
}> = ({ order, leg, onRefresh }) => {
  const isIntake = leg === 'intake';
  const enabled = isIntake
    ? order.intake_delivery_method === 'customer_delivery'
    : getReturnMethod(order) === 'customer_pickup';
  const snapshot = isIntake ? order.intake_address : order.return_address;
  const tracking = snapshot?.external_tracking;
  const locked = Boolean(isIntake
    ? order.intake_logistics_locked_at
      && (!isWarrantyNoChargeOrder(order) || order.received_at)
    : order.pickup_enabled);
  const unpaidThirdPartyReturn = !isIntake
    && getReturnMethod(order) === 'customer_pickup'
    && getOrderOutstandingBalance(order) > 0;
  const trackingGuidance = isIntake
    ? 'Add the details from the courier you arranged. The shop can view them but cannot edit them.'
    : unpaidThirdPartyReturn
      ? 'Complete the remaining payment first. Then enter the courier details below.'
      : 'Enter the courier carrier and tracking number before the shop records the return handoff.';
  const title = `${isIntake ? 'Intake' : 'Return'} courier tracking`;
  const fieldPrefix = isIntake ? 'Intake' : 'Return';
  const [carrier, setCarrier] = useState(tracking?.carrier ?? '');
  const [trackingNumber, setTrackingNumber] = useState(tracking?.tracking_number ?? '');
  const [trackingUrl, setTrackingUrl] = useState(tracking?.tracking_url ?? '');
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    setCarrier(tracking?.carrier ?? '');
    setTrackingNumber(tracking?.tracking_number ?? '');
    setTrackingUrl(tracking?.tracking_url ?? '');
  }, [tracking?.carrier, tracking?.tracking_number, tracking?.tracking_url]);

  if (!enabled) return null;

  const save = async () => {
    if (unpaidThirdPartyReturn) {
      setError('Complete the remaining payment before arranging the return courier.');
      return;
    }

    setSaving(true);
    setMessage(null);
    setError(null);
    try {
      const response = await axios.post(`/api/customer/repairs/${order.id}/external-tracking`, {
        leg,
        carrier: carrier.trim(),
        tracking_number: trackingNumber.trim(),
        tracking_url: trackingUrl.trim() || null,
      });
      setMessage(response.data?.message || 'Tracking details saved.');
      await onRefresh();
    } catch (reason) {
      setError(getRequestErrorMessage(reason, 'Unable to save tracking details.'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <section
      aria-label={title}
      className="mt-6 rounded-2xl border border-gray-200 bg-white p-4 sm:p-5"
    >
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h4 className="font-bold text-black">{title}</h4>
          <p className="mt-1 text-xs text-gray-500">
            {trackingGuidance}
          </p>
        </div>
        {locked && (
          <span className="rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-600">
            Locked after handoff
          </span>
        )}
      </div>

      {unpaidThirdPartyReturn ? (
        <p role="alert" className="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-800">
          Complete the remaining payment first. After payment, enter the courier carrier and tracking number here.
        </p>
      ) : locked ? (
        tracking?.tracking_number ? (
          <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2">
            <div>
              <dt className="text-xs font-semibold uppercase tracking-wider text-gray-500">Carrier</dt>
              <dd className="mt-1 font-semibold text-gray-900">{tracking.carrier || 'Not provided'}</dd>
            </div>
            <div>
              <dt className="text-xs font-semibold uppercase tracking-wider text-gray-500">Tracking number</dt>
              <dd className="mt-1 font-semibold text-gray-900">{tracking.tracking_number}</dd>
            </div>
            {tracking.tracking_url && (
              <div className="sm:col-span-2">
                <a
                  href={tracking.tracking_url}
                  target="_blank"
                  rel="noreferrer"
                  className="font-semibold text-[#16233b] underline"
                >
                  Open courier tracking
                </a>
              </div>
            )}
          </dl>
        ) : (
          <p className="mt-4 text-sm text-gray-600">No third-party tracking details were provided before handoff.</p>
        )
      ) : (
        <div className="mt-4 grid gap-3 sm:grid-cols-2">
          <label className="text-sm font-semibold text-gray-700">
            Carrier
            <input
              aria-label={`${fieldPrefix} carrier`}
              value={carrier}
              onChange={(event) => setCarrier(event.target.value)}
              className="mt-1 w-full rounded-xl border border-gray-300 px-3 py-2 font-normal text-gray-900"
            />
          </label>
          <label className="text-sm font-semibold text-gray-700">
            Tracking number
            <input
              aria-label={`${fieldPrefix} tracking number`}
              value={trackingNumber}
              onChange={(event) => setTrackingNumber(event.target.value)}
              className="mt-1 w-full rounded-xl border border-gray-300 px-3 py-2 font-normal text-gray-900"
            />
          </label>
          <label className="text-sm font-semibold text-gray-700 sm:col-span-2">
            Tracking link <span className="font-normal text-gray-500">(optional)</span>
            <input
              type="url"
              aria-label={`${fieldPrefix} tracking link`}
              value={trackingUrl}
              onChange={(event) => setTrackingUrl(event.target.value)}
              className="mt-1 w-full rounded-xl border border-gray-300 px-3 py-2 font-normal text-gray-900"
            />
          </label>
          <button
            type="button"
            onClick={save}
            disabled={saving || !carrier.trim() || !trackingNumber.trim()}
            className="rounded-full bg-[#16233b] px-5 py-2.5 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:bg-gray-300 sm:w-fit"
          >
            {saving ? 'Savingâ€¦' : `Save ${leg} tracking`}
          </button>
        </div>
      )}

      {message && <p role="status" className="mt-3 text-sm font-semibold text-green-700">{message}</p>}
      {error && <p role="alert" className="mt-3 text-sm font-semibold text-red-700">{error}</p>}
    </section>
  );
};

const RepairLogisticsTracking: React.FC<{
  shipments?: Array<{ id: number; purpose: string }>;
}> = ({ shipments = [] }) => {
  const intakeReference = shipments.find((shipment) => shipment.purpose === 'repair_pickup');
  const returnReference = shipments.find((shipment) => shipment.purpose === 'repair_return');
  const references = useMemo(
    () => [intakeReference, returnReference].filter(
      (shipment): shipment is { id: number; purpose: string } => Boolean(shipment),
    ),
    [intakeReference?.id, returnReference?.id],
  );
  const referenceKey = references.map((shipment) => `${shipment.purpose}:${shipment.id}`).join('|');
  const [trackingByPurpose, setTrackingByPurpose] = useState<Record<
    string,
    { shipment?: TrackingShipment; error?: string }
  > | null>(null);

  useEffect(() => {
    if (references.length === 0) {
      setTrackingByPurpose(null);
      return;
    }

    let active = true;
    setTrackingByPurpose(null);

    Promise.all(references.map(async (reference) => {
      try {
        const response = await axios.get(`/tracking/shipments/${reference.id}`, {
          headers: {
            Accept: 'application/json',
          },
        });
        const shipment = response.data?.props?.shipment ?? response.data?.shipment;
        return [reference.purpose, { shipment }] as const;
      } catch (reason) {
        return [
          reference.purpose,
          { error: getRequestErrorMessage(reason, 'Unable to load tracking updates.') },
        ] as const;
      }
    })).then((entries) => {
      if (active) {
        setTrackingByPurpose(Object.fromEntries(entries));
      }
    });

    return () => {
      active = false;
    };
  }, [referenceKey]);

  if (references.length === 0) {
    return null;
  }

  if (!trackingByPurpose) {
    return <p className="mt-5 text-sm text-gray-500">Loading logistics tracking…</p>;
  }

  const renderTimeline = (
    reference: { id: number; purpose: string } | undefined,
    heading: string,
    label: string,
  ) => {
    if (!reference) return null;

    const tracking = trackingByPurpose[reference.purpose];
    const events = tracking?.shipment?.events ?? [];

    return (
      <section
        aria-label={label}
        className="rounded-2xl border border-gray-200 bg-white p-4 sm:p-5"
      >
        <div className="flex items-center justify-between gap-3">
          <h4 className="font-bold text-black">{heading}</h4>
          <Link
            href={`/tracking/shipments/${reference.id}`}
            className="text-xs font-semibold text-gray-700 underline"
          >
            Open tracking
          </Link>
        </div>
        {tracking?.error ? (
          <p className="mt-3 text-sm text-red-700">{tracking.error}</p>
        ) : events.length > 0 ? (
          <ol className="mt-4 space-y-3 border-l border-gray-300 pl-4">
            {events.map((event) => (
              <li key={event.id}>
                <p className="text-sm font-medium text-gray-800">
                  {event.message || event.event_type.replace(/_/g, ' ')}
                </p>
                {event.created_at && (
                  <p className="mt-0.5 text-xs text-gray-500">
                    {new Date(event.created_at).toLocaleString()}
                  </p>
                )}
              </li>
            ))}
          </ol>
        ) : (
          <p className="mt-3 text-sm text-gray-500">No tracking events yet.</p>
        )}
      </section>
    );
  };

  return (
    <div className="mt-6 grid gap-4 lg:grid-cols-2">
      {renderTimeline(intakeReference, 'Intake pickup tracking', 'Intake pickup tracking')}
      {renderTimeline(returnReference, 'Return delivery tracking', 'Return delivery tracking')}
    </div>
  );
};

// Static mock data for testing
const getStaticRepairOrders = (): RepairOrder[] => {
  return [
    {
      id: 1,
      order_number: 'REP-2026020201',
      repair_type: 'Sole Replacement',
      description: 'Replace worn out sole on Nike Air Max',
      status: 'new_request',
      total_amount: 1500,
      created_at: new Date('2026-02-02T10:30:00').toISOString(),
      estimated_completion: 'Feb 7, 2026',
      shop_id: 1,
      shop_name: 'SoleSpace Repair Center',
      shop_address: '123 Main St, Makati City',
      image: '/images/product/product-01.jpg',
    },
    {
      id: 2,
      order_number: 'REP-2026020101',
      repair_type: 'Heel Replacement',
      description: 'Replace broken heel on dress shoes',
      status: 'pending',
      total_amount: 2000,
      created_at: new Date('2026-02-01T16:45:00').toISOString(),
      estimated_completion: 'Feb 6, 2026',
      shop_id: 1,
      shop_name: 'SoleSpace Repair Center',
      shop_address: '123 Main St, Makati City',
      image: '/images/product/product-04.jpg',
    },
    {
      id: 3,
      order_number: 'REP-2026013101',
      repair_type: 'Shoe Cleaning',
      description: 'Deep cleaning for white sneakers',
      status: 'received',
      total_amount: 800,
      created_at: new Date('2026-01-31T14:20:00').toISOString(),
      estimated_completion: 'Feb 4, 2026',
      shop_id: 1,
      shop_name: 'SoleSpace Repair Center',
      shop_address: '123 Main St, Makati City',
      image: '/images/product/product-02.jpg',
    },
    {
      id: 4,
      order_number: 'REP-2026013001',
      repair_type: 'Stitching Repair',
      description: 'Fix torn stitching on leather boots',
      status: 'in_progress',
      total_amount: 1200,
      created_at: new Date('2026-01-30T09:15:00').toISOString(),
      estimated_completion: 'Feb 3, 2026',
      shop_id: 1,
      shop_name: 'SoleSpace Repair Center',
      shop_address: '123 Main St, Makati City',
      image: '/images/product/product-03.jpg',
    },
    {
      id: 5,
      order_number: 'REP-2026012901',
      repair_type: 'Sole Reglue',
      description: 'Re-glue sole on running shoes',
      status: 'ready_for_pickup',
      total_amount: 950,
      created_at: new Date('2026-01-29T11:05:00').toISOString(),
      completed_at: 'Feb 1, 2026',
      shop_id: 1,
      shop_name: 'SoleSpace Repair Center',
      shop_address: '123 Main St, Makati City',
      image: '/images/product/product-05.jpg',
    },
    {
      id: 6,
      order_number: 'REP-2026012801',
      repair_type: 'Lace Replacement',
      description: 'Replace worn laces on sneakers',
      status: 'cancelled',
      total_amount: 250,
      created_at: new Date('2026-01-28T13:40:00').toISOString(),
      shop_id: 1,
      shop_name: 'SoleSpace Repair Center',
      shop_address: '123 Main St, Makati City',
      image: '/images/product/product-06.jpg',
    },
    {
      id: 7,
      order_number: 'REP-2026012701',
      repair_type: 'Toe Cap Repair',
      description: 'Fix scuffed toe cap on leather shoes',
      status: 'rejected',
      total_amount: 700,
      created_at: new Date('2026-01-27T10:10:00').toISOString(),
      shop_id: 1,
      shop_name: 'SoleSpace Repair Center',
      shop_address: '123 Main St, Makati City',
      image: '/images/product/product-07.jpg',
    },
  ];
};

const MyRepairs: React.FC = () => {
  const isPollingRepairsRef = useRef(false);
  const revealRootRef = useRef<HTMLDivElement | null>(null);
  useScrollReveal(revealRootRef);
  const [orders, setOrders] = useState<RepairOrder[]>([]);
  const [loading, setLoading] = useState(true);
  const [selectedTab, setSelectedTab] = useState<RepairTab>('new_request');
  const [seenRepairIdsByTab, setSeenRepairIdsByTab] = useState<Partial<Record<RepairTab, number[]>>>({});
  const [highlightRepairId, setHighlightRepairId] = useState<number | null>(null);
  const [showCancelModal, setShowCancelModal] = useState(false);
  const [cancelTargetOrderId, setCancelTargetOrderId] = useState<number | null>(null);
  const [selectedReason, setSelectedReason] = useState<string>('');
  const [cancelNote, setCancelNote] = useState<string>('');
  const [modifyOrder, setModifyOrder] = useState<RepairOrder | null>(null);
  const [availableModifyServices, setAvailableModifyServices] = useState<RepairServiceSummary[]>([]);
  const [selectedModifyServiceIds, setSelectedModifyServiceIds] = useState<number[]>([]);
  const [packageRemovalConfirmed, setPackageRemovalConfirmed] = useState(false);
  const [isLoadingModifyServices, setIsLoadingModifyServices] = useState(false);
  const [isSavingModifyServices, setIsSavingModifyServices] = useState(false);

  // Shop capacity cache: shopOwnerId → { active_count, limit, is_full }
  const [shopCapacityCache, setShopCapacityCache] = useState<Record<number, { active_count: number; limit: number; is_full: boolean }>>({});

  // Refund modal states
  const [showRefundModal, setShowRefundModal] = useState(false);
  const [refundOrderId, setRefundOrderId] = useState<number | null>(null);
  const [refundStep, setRefundStep] = useState<number>(1);
  const [refundReason, setRefundReason] = useState<string>('');
  const [refundOtherReason, setRefundOtherReason] = useState<string>('');
  const [refundMedia, setRefundMedia] = useState<File[]>([]);
  const [refundMethod, setRefundMethod] = useState<string>('gcash');
  const [refundAccountName, setRefundAccountName] = useState<string>('');
  const [refundAccountRef, setRefundAccountRef] = useState<string>('');
  const [refundPayoutConsent, setRefundPayoutConsent] = useState<boolean>(false);
  const [refundNote, setRefundNote] = useState<string>('');
  const [isSubmittingRefund, setIsSubmittingRefund] = useState(false);
  const [showWarrantyModal, setShowWarrantyModal] = useState(false);
  const [warrantyOrderId, setWarrantyOrderId] = useState<number | null>(null);
  const [warrantyReasonCode, setWarrantyReasonCode] = useState<string>('issue_returned');
  const [warrantyReasonDetails, setWarrantyReasonDetails] = useState<string>('');
  const [warrantyIntakeMethod, setWarrantyIntakeMethod] = useState<WarrantyIntakeMethod>('walk_in');
  const [warrantyReceiveMethod, setWarrantyReceiveMethod] = useState<WarrantyReceiveMethod>('walk_in');
  const [warrantyImages, setWarrantyImages] = useState<File[]>([]);
  const [isSubmittingWarrantyClaim, setIsSubmittingWarrantyClaim] = useState(false);
  const [latestRefundByRepairId, setLatestRefundByRepairId] = useState<Record<number, RepairRefundStatus>>({});
  const [latestWarrantyClaimByRepairId, setLatestWarrantyClaimByRepairId] = useState<Record<number, RepairWarrantyClaimStatus>>({});

  // Review modal states (Phase 10D)
  const [showReviewModal, setShowReviewModal] = useState(false);
  const [reviewOrderId, setReviewOrderId] = useState<number | null>(null);
  const [reviewRating, setReviewRating] = useState<number>(0);
  const [reviewText, setReviewText] = useState<string>('');
  const [reviewImages, setReviewImages] = useState<File[]>([]);
  const [hoveredRating, setHoveredRating] = useState<number>(0);
  const [processingPayment, setProcessingPayment] = useState(false);
  const [conversationUnreadCounts, setConversationUnreadCounts] = useState<Record<number, number>>({});

  // Schedule modal state
  const [showScheduleModal, setShowScheduleModal] = useState(false);
  const [scheduleOrderId, setScheduleOrderId] = useState<number | null>(null);
  const [scheduleShopId, setScheduleShopId] = useState<number | null>(null);
  const [scheduleSelectedDate, setScheduleSelectedDate] = useState<string>('');
  const [scheduleVisibleMonthKey, setScheduleVisibleMonthKey] = useState<string>('');
  const [shopClosedDayNumbers, setShopClosedDayNumbers] = useState<Set<number>>(new Set());
  const [isSubmittingSchedule, setIsSubmittingSchedule] = useState(false);

  // Derived calendar data for the schedule modal
  const scheduleCalendarData = useMemo(() => {
    if (!scheduleVisibleMonthKey) return null;
    const [yearText, monthText] = scheduleVisibleMonthKey.split('-');
    const year = Number(yearText);
    const month = Number(monthText);
    const monthDate = new Date(year, month - 1, 1);
    const now = new Date();
    return {
      firstWeekday: monthDate.getDay(),
      totalDays: new Date(year, month, 0).getDate(),
      monthLabel: monthDate.toLocaleString('en-US', { month: 'long', year: 'numeric' }),
      todayKey: `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`,
    };
  }, [scheduleVisibleMonthKey]);

  // Fetch shop closed days when the schedule modal opens or the shop changes
  useEffect(() => {
    if (!showScheduleModal || !scheduleShopId) return;
    let cancelled = false;
    const fetchShopHours = async () => {
      try {
        const response = await fetch(
          `/api/repair/shop-hours?shop_id=${scheduleShopId}`,
          { headers: { Accept: 'application/json' } }
        );
        if (!response.ok || cancelled) return;
        const data = await response.json();
        if (data.success && Array.isArray(data.closed_day_numbers)) {
          setShopClosedDayNumbers(new Set<number>(data.closed_day_numbers));
        }
      } catch { /* silently ignore */ }
    };
    fetchShopHours();
    return () => { cancelled = true; };
  }, [showScheduleModal, scheduleShopId]);

  const handleConfirmSchedule = async () => {
    if (!scheduleOrderId || !scheduleSelectedDate) return;
    setIsSubmittingSchedule(true);
    try {
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
      const response = await fetch(`/api/customer/repairs/${scheduleOrderId}/schedule`, {
        method: 'PATCH',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken || '',
          'Accept': 'application/json',
        },
        body: JSON.stringify({ preferred_date: scheduleSelectedDate }),
      });
      const data = await response.json();
      if (data.success) {
        setShowScheduleModal(false);
        await fetchRepairs();
        Swal.fire({
          icon: 'success',
          title: 'Schedule Confirmed!',
          text: `Your drop-off date has been set to ${data.preferred_date}.`,
          confirmButtonColor: '#000000',
        });
      } else {
        Swal.fire({ icon: 'error', title: 'Failed', text: data.message || 'Could not set schedule.', confirmButtonColor: '#000000' });
      }
    } catch {
      Swal.fire({ icon: 'error', title: 'Error', text: 'Something went wrong. Please try again.', confirmButtonColor: '#000000' });
    } finally {
      setIsSubmittingSchedule(false);
    }
  };

  const fetchConversationUnreadCounts = async () => {
    try {
      const response = await axios.get('/api/customer/conversations/shops');
      const unreadMap: Record<number, number> = {};

      (response.data as ConversationShop[]).forEach((shop) => {
        if (shop.conversation_id) {
          unreadMap[shop.conversation_id] = shop.unreadCount ?? 0;
        }
      });

      setConversationUnreadCounts(unreadMap);
    } catch (error) {
      console.error('Failed to fetch conversation unread counts:', error);
    }
  };

  const getUnreadCountForConversation = (conversationId?: number | null) => {
    if (!conversationId) return 0;
    return conversationUnreadCounts[conversationId] ?? 0;
  };

  const getRefundStatusLabel = (refund: RepairRefundStatus): string => {
    const stageLabel = refundStageLabel({
      overall_status: refund.status,
      status: refund.status,
      repairer_status: refund.repairer_status,
      finance_status: refund.finance_status,
      owner_status: refund.owner_status,
      shop_owner_status: refund.shop_owner_status,
    });

    if (stageLabel !== 'In Review') {
      return stageLabel;
    }

    switch ((refund.status || '').toLowerCase()) {
      case 'requested': return 'Refund Requested';
      case 'approved': return 'Refund Approved';
      case 'processing': return 'Refund Processing';
      case 'succeeded': return 'Refund Completed';
      case 'rejected': return 'Refund Rejected';
      case 'failed': return 'Refund Failed';
      default: return `Refund ${refund.status}`;
    }
  };

  const isRefundInProgress = (status?: string | null): boolean => {
    const normalized = String(status || '').toLowerCase();
    return normalized === 'requested' || normalized === 'approved' || normalized === 'processing';
  };

  const isRefundFlowLocked = (status?: string | null): boolean => {
    const normalized = String(status || '').toLowerCase();
    return normalized === 'requested' || normalized === 'approved' || normalized === 'processing' || normalized === 'succeeded';
  };

  const getWarrantyClaimStatusLabel = (status?: string | null): string => {
    const normalized = String(status || '').toLowerCase();

    if (normalized === 'pending_repairer') return 'Warranty Claim Under Review';
    if (normalized === 'approved') return 'Warranty Claim Approved';
    if (normalized === 'rejected') return 'Warranty Claim Rejected';
    if (normalized === 'expired') return 'Warranty Claim Expired';

    return normalized ? `Warranty ${normalized.replace(/_/g, ' ')}` : 'Warranty Claim Filed';
  };

  const canFileWarrantyClaim = (order: RepairOrder): boolean => {
    if (Boolean(order.is_warranty_job) || String(order.billing_mode || '').toLowerCase() === 'warranty_no_charge') {
      return false;
    }

    if (order.status !== 'picked_up' && order.status !== 'received') {
      return false;
    }

    if (hasBlockingRefundForReview(order)) {
      return false;
    }

    const latestClaim = latestWarrantyClaimByRepairId[order.id];
    if (!latestClaim) {
      return true;
    }

    return String(latestClaim.status || '').toLowerCase() === 'rejected';
  };

  const getWarrantyClaimBlockReason = (order: RepairOrder): string => {
    if (Boolean(order.is_warranty_job) || String(order.billing_mode || '').toLowerCase() === 'warranty_no_charge') {
      return 'Warranty rework jobs cannot be filed for another warranty claim.';
    }

    if (order.status !== 'picked_up' && order.status !== 'received') {
      return 'Warranty claim is available only after pickup/receipt completion.';
    }

    if (hasBlockingRefundForReview(order)) {
      return 'Warranty claim is unavailable while a refund request is active or after a refund has been completed for this repair.';
    }

    const latestClaim = latestWarrantyClaimByRepairId[order.id];
    if (!latestClaim) {
      return '';
    }

    if (String(latestClaim.status || '').toLowerCase() === 'rejected') {
      return '';
    }

    return `Warranty claim cannot be re-filed unless the previous claim was rejected (${getWarrantyClaimStatusLabel(latestClaim.status)}).`;
  };

  const getRefundAnchorRepairId = (order: RepairOrder): number => {
    const parentRepairId = Number(order.parent_repair_request_id ?? 0);

    if (isWarrantyNoChargeOrder(order) && parentRepairId > 0) {
      return parentRepairId;
    }

    return Number(order.id);
  };

  const getLatestRefundForOrder = (order: RepairOrder): RepairRefundStatus | undefined => {
    return latestRefundByRepairId[getRefundAnchorRepairId(order)];
  };

  const hasActiveWarrantyClaim = (order: RepairOrder): boolean => {
    const status = latestWarrantyClaimByRepairId[getRefundAnchorRepairId(order)]?.status;
    return ['pending_repairer', 'approved'].includes(String(status || '').toLowerCase());
  };

  const hasReviewForOrder = (order: RepairOrder): boolean => {
    if (Boolean(order.has_review)) {
      return true;
    }

    const anchorOrder = orders.find((entry) => entry.id === getRefundAnchorRepairId(order));
    return Boolean(anchorOrder?.has_review);
  };

  const hasBlockingRefundForReview = (order: RepairOrder): boolean => {
    const activeRefund = getLatestRefundForOrder(order);
    const refundStatus = String(activeRefund?.status || '').toLowerCase();
    const refundAnchorOrder = orders.find((entry) => entry.id === getRefundAnchorRepairId(order));
    const paymentStatus = String(refundAnchorOrder?.payment_status || order.payment_status || '').toLowerCase();
    const refundedAmount = Number(refundAnchorOrder?.total_refunded_amount ?? order.total_refunded_amount ?? 0);

    return ['requested', 'approved', 'processing', 'succeeded'].includes(refundStatus)
      || paymentStatus === 'refunded'
      || refundedAmount > 0;
  };

  const getOrderDisplayedBasePrice = (order: RepairOrder): number => {
    const currentPackagePrice = Number(order.package_price ?? 0);

    if (!isWarrantyNoChargeOrder(order)) {
      return Number.isFinite(currentPackagePrice) ? currentPackagePrice : 0;
    }

    const parentRepairId = Number(order.parent_repair_request_id ?? 0);
    const parentOrder = parentRepairId > 0
      ? orders.find((entry) => entry.id === parentRepairId)
      : undefined;

    const parentPackagePrice = Number(parentOrder?.package_price ?? 0);
    if (Number.isFinite(parentPackagePrice) && parentPackagePrice > 0) {
      return parentPackagePrice;
    }

    const previouslyPaid = Number(
      parentOrder?.display_total_paid_amount
      ?? parentOrder?.total_paid_amount
      ?? order.display_total_paid_amount
      ?? order.total_paid_amount
      ?? 0
    );

    if (Number.isFinite(previouslyPaid) && previouslyPaid > 0) {
      return previouslyPaid;
    }

    return Number.isFinite(currentPackagePrice) ? currentPackagePrice : 0;
  };

  const isReturnRefundRepair = (order: RepairOrder): boolean => {
    const activeRefund = getLatestRefundForOrder(order);
    const refundStatus = String(activeRefund?.status || '').toLowerCase();
    const refundAnchorOrder = orders.find((entry) => entry.id === getRefundAnchorRepairId(order));
    const paymentStatus = String(refundAnchorOrder?.payment_status || order.payment_status || '').toLowerCase();
    const refundedAmount = Number(refundAnchorOrder?.total_refunded_amount ?? order.total_refunded_amount ?? 0);

    return (
      ['requested', 'approved', 'processing', 'succeeded', 'failed', 'rejected'].includes(refundStatus)
      || paymentStatus === 'refunded'
      || refundedAmount > 0
    );
  };

  const mapRepairStatusToTab = (order: RepairOrder): RepairTab => {
    if (isReturnRefundRepair(order)) {
      return 'return_refund';
    }

    const status = order.status;

    switch (status) {
      case 'new_request':
      case 'assigned_to_repairer':
        return 'new_request';
      case 'pending':
      case 'repairer_accepted':
      case 'waiting_customer_confirmation':
      case 'owner_approval_pending':
      case 'owner_approved':
        return 'pending';
      case 'received':
        return 'received';
      case 'in_progress':
      case 'awaiting_parts':
        return 'in_progress';
      case 'completed':
        return 'completed';
      case 'ready_for_pickup':
      case 'shipped':
        return 'ready_for_pickup';
      case 'picked_up':
        return 'picked_up';
      case 'cancelled':
        return 'cancelled';
      case 'rejected':
      case 'repairer_rejected':
      case 'owner_rejected':
        return 'rejected';
      default:
        return 'new_request';
    }
  };

  const mapTabParamToRepairTab = (rawValue: string | null): RepairTab | null => {
    if (!rawValue) return null;

    const value = rawValue.toLowerCase();

    if (value === 'accepted') return 'pending';
    if (value === 'progress') return 'in_progress';
    if (value === 'pickup' || value === 'ready' || value === 'shipped') return 'ready_for_pickup';
    if (value === 'return_refund' || value === 'return/refund' || value === 'return' || value === 'refund') return 'return_refund';

    if (
      value === 'new_request' ||
      value === 'pending' ||
      value === 'received' ||
      value === 'in_progress' ||
      value === 'completed' ||
      value === 'ready_for_pickup' ||
      value === 'picked_up' ||
      value === 'cancelled' ||
      value === 'rejected' ||
      value === 'return_refund'
    ) {
      return value as RepairTab;
    }

    return null;
  };

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const requestedTab = params.get('tab') || params.get('status');
    const mappedTab = mapTabParamToRepairTab(requestedTab);

    if (mappedTab) {
      setSelectedTab(mappedTab);
    }
  }, []);

  useEffect(() => {
    if (typeof window === 'undefined') {
      return;
    }

    try {
      const raw = localStorage.getItem(REPAIR_TAB_NOTIFICATION_STORAGE_KEY);
      if (!raw) {
        return;
      }

      const parsed = JSON.parse(raw) as Partial<Record<RepairTab, unknown>>;
      if (!parsed || typeof parsed !== 'object') {
        return;
      }

      const restored: Partial<Record<RepairTab, number[]>> = {};
      REPAIR_TABS.forEach((tab) => {
        const candidate = parsed[tab];
        if (!Array.isArray(candidate)) {
          return;
        }

        restored[tab] = Array.from(
          new Set(
            candidate
              .map((entry) => Number(entry))
              .filter((entry) => Number.isFinite(entry) && entry > 0)
          )
        ).sort((left, right) => left - right);
      });

      setSeenRepairIdsByTab(restored);
    } catch (error) {
      console.warn('Failed to restore repair tab notification state:', error);
    }
  }, []);

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const highlightParam = params.get('highlightRepair') || params.get('highlight');

    if (!highlightParam) {
      return;
    }

    const parsedId = Number(highlightParam);
    if (Number.isNaN(parsedId)) {
      return;
    }

    setHighlightRepairId(parsedId);
  }, []);

  useEffect(() => {
    if (!highlightRepairId || orders.length === 0) {
      return;
    }

    const targetRepair = orders.find((order) => order.id === highlightRepairId);
    if (!targetRepair) {
      return;
    }

    setSelectedTab(mapRepairStatusToTab(targetRepair));

    const scrollTimer = window.setTimeout(() => {
      const targetElement = document.querySelector(`[data-repair-id="${highlightRepairId}"]`);
      if (targetElement) {
        targetElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    }, 200);

    return () => window.clearTimeout(scrollTimer);
  }, [highlightRepairId, orders]);

  useEffect(() => {
    const checkPaymentReturn = async () => {
      const urlParams         = new URLSearchParams(window.location.search);
      const isPaymongoSuccess = urlParams.get('paymongo_success') === '1';
      const isPaymongoFailed  = urlParams.get('paymongo_failed')  === '1';
      const pendingRepairIdFromSession = Number(sessionStorage.getItem('pendingRepairId') || '0');
      const pendingRepairIdFromQuery = Number(urlParams.get('pending_repair_id') || '0');
      const returnTs = Number(urlParams.get('return_ts') || '0');
      const returnSig = String(urlParams.get('return_sig') || '');
      const usedSessionPendingId = Number.isFinite(pendingRepairIdFromSession) && pendingRepairIdFromSession > 0;
      const usedQueryFallback = !usedSessionPendingId && Number.isFinite(pendingRepairIdFromQuery) && pendingRepairIdFromQuery > 0;
      const parsedPendingRepairId = Number.isFinite(pendingRepairIdFromSession) && pendingRepairIdFromSession > 0
        ? pendingRepairIdFromSession
        : (Number.isFinite(pendingRepairIdFromQuery) && pendingRepairIdFromQuery > 0 ? pendingRepairIdFromQuery : null);

      // Always clean up URL params and session storage
      if (isPaymongoSuccess || isPaymongoFailed) {
        const cleanedParams = new URLSearchParams(urlParams);
        cleanedParams.delete('paymongo_success');
        cleanedParams.delete('paymongo_failed');
        cleanedParams.delete('pending_repair_id');
        cleanedParams.delete('return_ts');
        cleanedParams.delete('return_sig');
        const cleanedQuery = cleanedParams.toString();
        window.history.replaceState({}, '', `/my-repairs${cleanedQuery ? `?${cleanedQuery}` : ''}`);
      }

      if (isPaymongoFailed) {
        sessionStorage.removeItem('pendingRepairId');
        if (usedQueryFallback) {
          await Swal.fire({
            icon: 'warning',
            title: 'Payment Not Completed',
            text: 'You can continue browsing now. Sign in later if you want to retry this repair payment.',
            confirmButtonColor: '#000000',
          });
          window.location.href = '/';
          return;
        }

        fetchRepairs();
        const retryResult = await Swal.fire({
          icon: 'error',
          title: 'Payment Not Completed',
          text: 'You did not finish the payment. Create a new payment session to try again.',
          showCancelButton: true,
          confirmButtonText: 'Create New Payment',
          cancelButtonText: 'Close',
          confirmButtonColor: '#000000',
        });

        if (retryResult.isConfirmed && parsedPendingRepairId && Number.isFinite(parsedPendingRepairId)) {
          await handlePayNow(parsedPendingRepairId);
        }

        fetchRepairs();
        return;
      }

      if (parsedPendingRepairId && isPaymongoSuccess) {
        sessionStorage.removeItem('pendingRepairId');
        Swal.fire({
          icon: 'info',
          title: 'Verifying Payment...',
          text: 'Please wait while we confirm your payment with PayMongo.',
          allowOutsideClick: false,
          showConfirmButton: false,
          didOpen: () => { Swal.showLoading(); },
        });

        try {
          const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

          // PayMongo redirects back before its own status update propagates.
          // Retry up to 6 times (12 seconds total) waiting for payment_status = 'paid'.
          const MAX_ATTEMPTS = 6;
          const RETRY_DELAY  = 2000;
          let result: any = null;

          for (let attempt = 1; attempt <= MAX_ATTEMPTS; attempt++) {
            const response = await fetch(`/api/customer/repairs/${parsedPendingRepairId}/verify-payment-return`, {
              method: 'POST',
              credentials: 'include',
              headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken || '',
              },
              body: JSON.stringify({
                return_ts: returnTs,
                return_sig: returnSig,
              }),
            });
            result = await response.json();

            if (result.success && result.payment_verified) break;

            if (response.status === 410) break;

            // Stop retrying on hard errors (network, server crash, wrong ID)
            if (response.status >= 500 || response.status === 404) break;

            if (attempt < MAX_ATTEMPTS) {
              await new Promise(resolve => setTimeout(resolve, RETRY_DELAY));
            }
          }

          if (!usedQueryFallback) {
            void fetchRepairs();
          }

          if (result?.success && result?.payment_verified) {
            await Swal.fire({
              icon: 'success',
              title: 'Payment Confirmed!',
              text: usedQueryFallback
                ? 'Your payment has been confirmed. You can continue browsing now and sign in later to view full repair details.'
                : 'Your payment has been received. Your repair will begin shortly.',
              confirmButtonColor: '#000000',
              timer: 3000,
              timerProgressBar: true,
            });

            if (usedQueryFallback) {
              window.location.href = '/';
            }
          } else if (result?.expired) {
            if (usedQueryFallback) {
              await Swal.fire({
                icon: 'warning',
                title: 'Payment Session Expired',
                text: 'Your payment session expired. Sign in later to create a new payment session.',
                confirmButtonColor: '#000000',
              });
              window.location.href = '/';
              return;
            }

            const retryResult = await Swal.fire({
              icon: 'warning',
              title: 'Payment Session Expired',
              text: 'Your payment session expired. Create a new payment session to continue.',
              showCancelButton: true,
              confirmButtonText: 'Create New Payment',
              cancelButtonText: 'Close',
              confirmButtonColor: '#000000',
            });

            if (retryResult.isConfirmed && parsedPendingRepairId && Number.isFinite(parsedPendingRepairId)) {
              await handlePayNow(parsedPendingRepairId);
            }
          } else {
            await Swal.fire({
              icon: 'warning',
              title: 'Payment Not Verified',
              text: result?.message || 'We could not confirm your payment yet. Please try again or contact support.',
              confirmButtonColor: '#000000',
            });

            if (usedQueryFallback) {
              window.location.href = '/';
            }
          }
        } catch (error) {
          console.error('Payment verification error:', error);
          if (!usedQueryFallback) {
            void fetchRepairs();
          }
          await Swal.fire({
            icon: 'error',
            title: 'Verification Error',
            text: 'There was an issue verifying your payment. Please contact support.',
            confirmButtonColor: '#000000',
          });

          if (usedQueryFallback) {
            window.location.href = '/';
          }
        }
      } else {
        sessionStorage.removeItem('pendingRepairId');
        fetchRepairs();
      }
    };

    checkPaymentReturn();
  }, []);

  useEffect(() => {
    const intervalId = window.setInterval(() => {
      fetchConversationUnreadCounts();
    }, 10000);

    return () => {
      window.clearInterval(intervalId);
    };
  }, []);

  const fetchLatestWarrantyClaims = async (repairList: RepairOrder[]) => {
    if (!Array.isArray(repairList) || repairList.length === 0) {
      setLatestWarrantyClaimByRepairId({});
      return;
    }

    const claimEntries = await Promise.all(
      repairList.map(async (repair) => {
        try {
          const response = await axios.get(`/api/customer/repairs/${repair.id}/warranty-claims/latest`);
          const claim = response?.data?.data;
          if (!claim || typeof claim !== 'object') {
            return [repair.id, null] as const;
          }

          const mappedClaim: RepairWarrantyClaimStatus = {
            id: Number(claim.id || 0),
            claim_no: String(claim.claim_no || ''),
            status: String(claim.status || 'pending_repairer'),
            reason_code: claim.reason_code ? String(claim.reason_code) : undefined,
            source_channel: claim.source_channel ? String(claim.source_channel) : undefined,
            preferred_return_method: claim.preferred_return_method ? String(claim.preferred_return_method) : undefined,
            preferred_receive_method: claim.preferred_receive_method ? String(claim.preferred_receive_method) : undefined,
            created_at: claim.created_at || null,
            reviewed_at: claim.reviewed_at || null,
            rejection_reason: claim.rejection_reason || null,
            approved_repair_request_id: claim.approved_repair_request_id ? Number(claim.approved_repair_request_id) : null,
          };

          return [repair.id, mappedClaim] as const;
        } catch {
          return [repair.id, null] as const;
        }
      })
    );

    const nextClaims: Record<number, RepairWarrantyClaimStatus> = {};
    claimEntries.forEach(([repairId, claim]) => {
      if (claim) {
        nextClaims[repairId] = claim;
      }
    });

    setLatestWarrantyClaimByRepairId(nextClaims);
  };

  const fetchRepairs = async (options: { silent?: boolean; lightweight?: boolean } = {}) => {
    const { silent = false, lightweight = false } = options;

    try {
      if (!silent) {
        setLoading(true);
      }

      const response = await axios.get('/api/customer/repairs', {
        params: undefined,
      });
      if (response.data.success) {
        const repairList: RepairOrder[] = response.data.data;
        setOrders(repairList);
        if (!silent) {
          setLoading(false);
        }

        if (lightweight) {
          return;
        }

        await fetchConversationUnreadCounts();

        try {
          const refundResponse = await axios.get('/api/repair-pos/refunds/mine', { withCredentials: true });
          const refundRows = Array.isArray(refundResponse?.data?.data) ? refundResponse.data.data : [];
          const nextMap: Record<number, RepairRefundStatus> = {};

          refundRows.forEach((refund: any) => {
            const repairId = Number(refund?.module_reference_id || 0);
            if (!Number.isFinite(repairId) || repairId <= 0) return;
            const normalizedProofUrls = Array.isArray(refund?.execution_proof_urls)
              ? refund.execution_proof_urls
                  .filter((entry: unknown) => typeof entry === 'string' && entry.trim().length > 0)
                  .map((entry: string) => normalizeProofUrlForBrowser(entry))
                  .filter((entry: string) => entry.length > 0)
              : [];

            const mappedRefund: RepairRefundStatus = {
              id: Number(refund?.id || 0),
              status: String(refund?.status || 'requested'),
              repairer_status: refund?.repairer_status ? String(refund.repairer_status) : null,
              finance_status: refund?.finance_status ? String(refund.finance_status) : null,
              owner_status: refund?.owner_status ? String(refund.owner_status) : null,
              shop_owner_status: refund?.shop_owner_status ? String(refund.shop_owner_status) : null,
              requested_amount: Number(refund?.requested_amount || 0),
              approved_amount: refund?.approved_amount == null ? null : Number(refund.approved_amount),
              requested_at: refund?.requested_at || null,
              failure_reason: refund?.failure_reason || null,
              execution_channel: refund?.execution_channel ? String(refund.execution_channel) : null,
              execution_reference_masked: refund?.execution_reference_masked ? String(refund.execution_reference_masked) : null,
              execution_proof_urls: normalizedProofUrls,
              executed_at: refund?.executed_at || null,
            };

            if (!nextMap[repairId]) {
              nextMap[repairId] = mappedRefund;
              return;
            }

            // Keep latest row for status, but backfill proof details from related rows when needed.
            if (!nextMap[repairId].execution_reference_masked && mappedRefund.execution_reference_masked) {
              nextMap[repairId].execution_reference_masked = mappedRefund.execution_reference_masked;
            }

            if ((nextMap[repairId].execution_proof_urls?.length ?? 0) === 0 && normalizedProofUrls.length > 0) {
              nextMap[repairId].execution_proof_urls = normalizedProofUrls;
            }
          });

          setLatestRefundByRepairId(nextMap);
        } catch {
          setLatestRefundByRepairId({});
        }

        await fetchLatestWarrantyClaims(repairList);

        // Fetch capacity for shops that have a repair still waiting for acceptance
        const waitingShopIds = [...new Set(
          repairList
            .filter(o => o.status === 'new_request' || o.status === 'assigned_to_repairer')
            .map(o => o.shop_owner_id)
            .filter((id): id is number => typeof id === 'number')
        )];
        if (waitingShopIds.length > 0) {
          const results = await Promise.allSettled(
            waitingShopIds.map(id =>
              fetch(`/api/customer/shop/${id}/repair-capacity`, { headers: { Accept: 'application/json' } })
                .then(r => r.json())
                .then(data => ({ id, data }))
            )
          );
          const cache: Record<number, { active_count: number; limit: number; is_full: boolean }> = {};
          results.forEach(res => {
            if (res.status === 'fulfilled' && res.value.data?.success) {
              cache[res.value.id] = {
                active_count: res.value.data.active_count,
                limit: res.value.data.limit,
                is_full: res.value.data.is_full,
              };
            }
          });
          setShopCapacityCache(cache);
        }
      }
    } catch (error) {
      console.error('Failed to fetch repairs:', error);
      if (!silent) {
        Swal.fire({
          icon: 'error',
          title: 'Failed to load repairs',
          text: 'Please try refreshing the page',
          confirmButtonColor: '#000000',
        });
      }
    } finally {
      if (!silent) {
        setLoading(false);
      }
    }
  };

  const closeModifyModal = () => {
    if (isSavingModifyServices) return;

    setModifyOrder(null);
    setAvailableModifyServices([]);
    setSelectedModifyServiceIds([]);
    setPackageRemovalConfirmed(false);
  };

  const openModifyModal = async (order: RepairOrder) => {
    if (!order.shop_owner_id) return;

    setModifyOrder(order);
    setPackageRemovalConfirmed(!order.repair_package_id);
    setIsLoadingModifyServices(true);

    try {
      const response = await axios.get(`/api/repair-services?shop_id=${order.shop_owner_id}`);
      const services: RepairServiceSummary[] = Array.isArray(response.data?.data)
        ? response.data.data
        : [];
      const activeServiceIds = new Set(services.map((service) => service.id));

      setAvailableModifyServices(services);
      setSelectedModifyServiceIds(
        (order.services ?? [])
          .map((service) => service.id)
          .filter((serviceId) => activeServiceIds.has(serviceId)),
      );
    } catch {
      setModifyOrder(null);
      Swal.fire({
        icon: 'error',
        title: 'Unable to Load Services',
        text: 'Please try again.',
        confirmButtonColor: '#000000',
      });
    } finally {
      setIsLoadingModifyServices(false);
    }
  };

  const toggleModifyService = (serviceId: number) => {
    if (!packageRemovalConfirmed) return;

    setSelectedModifyServiceIds((current) =>
      current.includes(serviceId)
        ? current.filter((id) => id !== serviceId)
        : [...current, serviceId],
    );
  };

  const saveModifiedServices = async () => {
    if (!modifyOrder || selectedModifyServiceIds.length === 0) return;

    setIsSavingModifyServices(true);

    try {
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
      const response = await fetch(`/api/customer/repairs/${modifyOrder.id}/services`, {
        method: 'PATCH',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken || '',
          Accept: 'application/json',
        },
        body: JSON.stringify({
          service_ids: selectedModifyServiceIds,
          remove_package: Boolean(modifyOrder.repair_package_id),
        }),
      });
      const data = await response.json();

      if (!response.ok || !data.success) {
        throw new Error(data.message || 'Unable to update services.');
      }

      setModifyOrder(null);
      setAvailableModifyServices([]);
      setSelectedModifyServiceIds([]);
      setPackageRemovalConfirmed(false);
      await fetchRepairs();
      Swal.fire({
        icon: 'success',
        title: 'Services Updated',
        text: 'Your repair total and chat were updated.',
        confirmButtonColor: '#000000',
      });
    } catch (error: any) {
      Swal.fire({
        icon: 'error',
        title: 'Update Failed',
        text: error?.message || 'Please try again.',
        confirmButtonColor: '#000000',
      });
    } finally {
      setIsSavingModifyServices(false);
    }
  };

  const selectedModifyTotal = availableModifyServices
    .filter((service) => selectedModifyServiceIds.includes(service.id))
    .reduce((sum, service) => sum + Number(service.price || 0), 0);

  useEffect(() => {
    const intervalId = window.setInterval(async () => {
      if (typeof document !== 'undefined' && document.visibilityState !== 'visible') {
        return;
      }

      if (isPollingRepairsRef.current) {
        return;
      }

      isPollingRepairsRef.current = true;
      try {
        await fetchRepairs({ silent: true, lightweight: true });
      } finally {
        isPollingRepairsRef.current = false;
      }
    }, MY_REPAIRS_POLL_INTERVAL_MS);

    return () => {
      window.clearInterval(intervalId);
    };
  }, []);

  const confirmPickup = async (orderId: number) => {
    const result = await Swal.fire({
      title: 'Confirm receipt?',
      text: 'Confirm only after your repaired shoes are in your hands.',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Yes, I received them',
      cancelButtonText: 'Not yet',
      confirmButtonColor: '#000000',
      cancelButtonColor: '#6b7280',
      reverseButtons: true,
    });

    if (!result.isConfirmed) return;

    try {
      const response = await axios.post(`/api/customer/repairs/${orderId}/confirm-pickup`);
      
      if (response.data.success) {
        // Update local state
        setOrders(prev => 
          prev.map(order => 
            order.id === orderId 
              ? { ...order, status: 'picked_up' as const } 
              : order
          )
        );

        await Swal.fire({
          title: 'Receipt confirmed',
          text: 'Thank you for confirming that you received your repaired shoes.',
          icon: 'success',
          confirmButtonText: 'OK',
          confirmButtonColor: '#000000',
        });
      }
    } catch (error) {
      console.error('Failed to confirm pickup:', error);
      await Swal.fire({
        icon: 'error',
        title: 'Unable to confirm receipt',
        text: getRequestErrorMessage(error, 'Please refresh and try again.'),
        confirmButtonColor: '#000000',
      });
    }
  };

  const cancelRepair = async (orderId: number, reason?: string) => {
    if (!reason) {
      const result = await Swal.fire({
        title: 'Cancel Repair?',
        text: 'Are you sure you want to cancel this repair order?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, cancel it',
        cancelButtonText: 'Keep order',
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#6b7280',
        reverseButtons: true,
      });

      if (!result.isConfirmed) return;
    }

    try {
      const response = await axios.post(`/api/customer/repairs/${orderId}/cancel`);
      
      if (response.data.success) {
        setOrders(prev =>
          prev.map(order =>
            order.id === orderId
              ? { ...order, status: 'cancelled' as const }
              : order
          )
        );

        await Swal.fire({
          title: 'Repair Cancelled',
          text: reason ? 'Your repair order has been cancelled.' : 'Your repair order has been cancelled.',
          icon: 'success',
          confirmButtonText: 'OK',
          confirmButtonColor: '#000000',
        });
      }
    } catch (error) {
      console.error('Failed to cancel repair:', error);
      await Swal.fire({
        icon: 'error',
        title: 'Failed to cancel repair',
        text: 'Please try again',
        confirmButtonColor: '#000000',
      });
    }
  };

  const resetWarrantyModalState = () => {
    setWarrantyOrderId(null);
    setWarrantyReasonCode('issue_returned');
    setWarrantyReasonDetails('');
    setWarrantyIntakeMethod('walk_in');
    setWarrantyReceiveMethod('walk_in');
    setWarrantyImages([]);
  };

  const closeWarrantyModal = () => {
    if (isSubmittingWarrantyClaim) {
      return;
    }

    setShowWarrantyModal(false);
    resetWarrantyModalState();
  };

  const handleFileWarrantyClaim = async (order: RepairOrder) => {
    if (!canFileWarrantyClaim(order)) {
      await Swal.fire({
        icon: 'info',
        title: 'Warranty Claim Unavailable',
        text: getWarrantyClaimBlockReason(order),
        confirmButtonColor: '#000000',
      });
      return;
    }

    setWarrantyOrderId(order.id);
    setWarrantyReasonCode('issue_returned');
    setWarrantyReasonDetails('');
    setWarrantyIntakeMethod(getIntakeMethod(order));
    setWarrantyReceiveMethod(getReturnMethod(order));
    setWarrantyImages([]);
    setShowWarrantyModal(true);
  };

  const hasWarrantyDeliveryAddresses = () => {
    const order = orders.find((entry) => entry.id === warrantyOrderId);
    return (warrantyIntakeMethod === 'walk_in' || Boolean(getWarrantyAddress(order, 'intake')))
      && (warrantyReceiveMethod === 'walk_in' || Boolean(getWarrantyAddress(order, 'return')));
  };

  const handleWarrantyImageUpload = (e: React.ChangeEvent<HTMLInputElement>) => {
    if (!e.target.files) {
      return;
    }

    const filesArray = Array.from(e.target.files);
    const allowedImageMimeTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];

    const invalidFile = filesArray.find((file) => !allowedImageMimeTypes.includes(file.type.toLowerCase()));
    if (invalidFile) {
      Swal.fire({
        icon: 'warning',
        title: 'Invalid File Type',
        text: 'Only JPEG, PNG, and WEBP images are allowed for warranty claims.',
        confirmButtonColor: '#000000',
      });
      e.target.value = '';
      return;
    }

    const oversizedImage = filesArray.find((file) => file.size > MAX_REFUND_IMAGE_SIZE_BYTES);
    if (oversizedImage) {
      Swal.fire({
        icon: 'warning',
        title: 'Image Too Large',
        text: 'Each warranty image must be 20MB or smaller.',
        confirmButtonColor: '#000000',
      });
      e.target.value = '';
      return;
    }

    if (warrantyImages.length + filesArray.length > 10) {
      Swal.fire({
        icon: 'warning',
        title: 'Image Limit Exceeded',
        text: 'You can upload up to 10 images per warranty claim.',
        confirmButtonColor: '#000000',
      });
      e.target.value = '';
      return;
    }

    setWarrantyImages((prev) => [...prev, ...filesArray]);
    e.target.value = '';
  };

  const removeWarrantyImage = (index: number) => {
    setWarrantyImages((prev) => prev.filter((_, i) => i !== index));
  };

  const isWarrantyFormValid = () => {
    if (!warrantyIntakeMethod || !warrantyReceiveMethod) {
      return false;
    }

    if (!warrantyReasonCode) {
      return false;
    }

    if (warrantyReasonCode === 'other' && warrantyReasonDetails.trim().length === 0) {
      return false;
    }

    if (!hasWarrantyDeliveryAddresses()) {
      return false;
    }

    return warrantyImages.length > 0 && warrantyImages.length <= 10;
  };

  const handleSubmitWarrantyClaim = async () => {
    if (!warrantyOrderId) {
      return;
    }

    if (!warrantyReasonCode) {
      Swal.fire({
        icon: 'warning',
        title: 'Please select a reason',
        confirmButtonColor: '#000000',
      });
      return;
    }

    if (warrantyReasonCode === 'other' && warrantyReasonDetails.trim().length === 0) {
      Swal.fire({
        icon: 'warning',
        title: 'Please provide details',
        text: 'Details are required when selecting Other.',
        confirmButtonColor: '#000000',
      });
      return;
    }

    if (!hasWarrantyDeliveryAddresses()) {
      Swal.fire({
        icon: 'warning',
        title: 'Pinned Address Required',
        text: 'This repair has no saved pinned address to reuse. Choose walk-in or update the repair delivery address first.',
        confirmButtonColor: '#000000',
      });
      return;
    }

    if (warrantyImages.length === 0) {
      Swal.fire({
        icon: 'warning',
        title: 'Evidence images are required',
        text: 'Please upload at least one image before submitting.',
        confirmButtonColor: '#000000',
      });
      return;
    }

    if (warrantyImages.length > 10) {
      Swal.fire({
        icon: 'warning',
        title: 'Too many images',
        text: 'You can upload a maximum of 10 evidence images.',
        confirmButtonColor: '#000000',
      });
      return;
    }

    const modal = await Swal.fire({
      icon: 'question',
      title: 'Submit Warranty Claim?',
      text: 'Please confirm that your details and evidence images are correct before submitting.',
      showCancelButton: true,
      confirmButtonText: 'Yes, Submit',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#000000',
      cancelButtonColor: '#6b7280',
      reverseButtons: true,
    });

    if (!modal.isConfirmed) {
      return;
    }

    setIsSubmittingWarrantyClaim(true);

    const payload = new FormData();
    payload.append('reason_code', warrantyReasonCode);
    payload.append('reason_details', warrantyReasonDetails.trim() || 'Warranty claim filed by customer.');
    payload.append('same_issue_confirmation', '1');
    payload.append('preferred_return_method', warrantyIntakeMethod);
    payload.append('preferred_receive_method', warrantyReceiveMethod);

    warrantyImages.forEach((file: File, index: number) => {
      payload.append(`images[${index}]`, file);
    });

    try {
      const response = await fetch(`/api/customer/repairs/${warrantyOrderId}/warranty-claims`, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Accept': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        },
        body: payload,
      });

      const raw = await response.text();
      let data: any = null;
      try {
        data = raw ? JSON.parse(raw) : null;
      } catch {
        data = null;
      }

      if (!response.ok) {
        const validationMessage = Object.values(data?.errors ?? {}).flat().find(
          (message): message is string => typeof message === 'string' && message.trim().length > 0,
        );
        const errorMessage = validationMessage
          || data?.message
          || data?.errors?.repair?.[0]
          || data?.errors?.images?.[0]
          || 'Unable to file warranty claim right now.';

        throw new Error(errorMessage);
      }

      const createdClaim = data?.data;
      if (createdClaim && createdClaim.id) {
        setLatestWarrantyClaimByRepairId((prev) => ({
          ...prev,
          [warrantyOrderId]: {
            id: Number(createdClaim.id),
            claim_no: String(createdClaim.claim_no || ''),
            status: String(createdClaim.status || 'pending_repairer'),
            source_channel: String(createdClaim.source_channel || 'customer_portal'),
            created_at: new Date().toISOString(),
          },
        }));
      }

      await Swal.fire({
        icon: 'success',
        title: 'Warranty Claim Submitted',
        text: 'Your claim has been submitted for repairer review.',
        confirmButtonColor: '#000000',
      });

      setShowWarrantyModal(false);
      resetWarrantyModalState();
    } catch (error) {
      await Swal.fire({
        icon: 'error',
        title: 'Failed to Submit Claim',
        text: error instanceof Error ? error.message : 'Please try again later.',
        confirmButtonColor: '#000000',
      });
    } finally {
      setIsSubmittingWarrantyClaim(false);
    }
  };

  const handlePayNow = async (orderId: number) => {
    const selectedOrder = orders.find(o => o.id === orderId);
    if (!selectedOrder) return;

    setProcessingPayment(true);

    try {
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
      
      // Create dedicated retry session and persist fresh payment link metadata.
      const response = await fetch(`/api/customer/repairs/${orderId}/retry-payment-session`, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrfToken || '',
        },
      });

      if (!response.ok) {
        const errorData = await response.json().catch(() => ({}));
        if (errorData.error === 'shop_payment_not_configured') {
          throw new Error('This shop has not set up online payments yet. Please contact the shop directly to arrange payment.');
        }
        const firstValidationError = Object.values(errorData.errors ?? {}).flat().find(
          (entry) => typeof entry === 'string' && entry.trim(),
        );
        const message = typeof firstValidationError === 'string'
          ? firstValidationError
          : typeof errorData.error === 'string'
            ? errorData.error
            : typeof errorData.message === 'string'
              ? errorData.message
              : 'Failed to create payment link';
        throw new Error(message);
      }

      const paymentData = await response.json();
      const checkoutUrl = paymentData.checkout_url;

      if (!checkoutUrl) {
        throw new Error('Incomplete payment data received from PayMongo');
      }

      const fallbackBreakdown = getOrderBreakdown(selectedOrder);
      const subtotalAmount = Number(paymentData?.subtotal_amount ?? fallbackBreakdown.netSubtotal);
      const vatAmount = Number(paymentData?.vat_amount ?? fallbackBreakdown.vatAmount);
      const vatRate = Number(paymentData?.vat_rate ?? getOrderVatRate(selectedOrder));
      const totalAmount = Number(paymentData?.total_amount ?? fallbackBreakdown.grandTotal);

      const confirmResult = await Swal.fire({
        title: 'Confirm Repair Payment',
        html: `
          <div style="text-align:left; font-size:14px; line-height:1.7;">
            <div style="display:flex; justify-content:space-between;"><span>Subtotal</span><strong>${formatCurrency(subtotalAmount)}</strong></div>
            <div style="display:flex; justify-content:space-between;"><span>VAT (${vatRate}%)</span><strong>${formatCurrency(vatAmount)}</strong></div>
            <div style="margin-top:10px; border-top:1px solid #e5e7eb; padding-top:10px; display:flex; justify-content:space-between;"><span>Total</span><strong>${formatCurrency(totalAmount)}</strong></div>
          </div>
        `,
        icon: 'info',
        showCancelButton: true,
        confirmButtonText: 'Proceed to PayMongo',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#000000',
        cancelButtonColor: '#6b7280',
        buttonsStyling: false,
        customClass: {
          actions: '!mt-5 !w-full !gap-2 sm:!gap-3 !justify-end',
          confirmButton: '!m-0 !h-10 sm:!h-11 !rounded-xl !px-4 sm:!px-5 !text-sm !font-semibold !tracking-[0.01em] !bg-slate-950 hover:!bg-black focus:!ring-2 focus:!ring-slate-400',
          cancelButton: '!m-0 !h-10 sm:!h-11 !rounded-xl !px-4 sm:!px-5 !text-sm !font-semibold !text-slate-700 !bg-slate-100 hover:!bg-slate-200 focus:!ring-2 focus:!ring-slate-300',
        },
      });

      if (!confirmResult.isConfirmed) {
        setProcessingPayment(false);
        return;
      }

      // Store repair info so we can verify on return
      sessionStorage.setItem('pendingRepairId', orderId.toString());

      // Redirect to PayMongo payment page
      window.location.href = checkoutUrl;
    } catch (err: any) {
      console.error('Payment error:', err);
      const errorMessage = err?.message || 'Unable to process payment';
      setProcessingPayment(false);

      Swal.fire({
        icon: 'error',
        title: 'Payment Failed',
        text: errorMessage,
        confirmButtonColor: '#000000',
      });
    }
  };

  const handleSubmitRefund = async () => {
    if (!refundOrderId) return;

    const targetOrder = orders.find((entry) => entry.id === refundOrderId);
    if (!targetOrder) {
      Swal.fire({ icon: 'error', title: 'Repair Not Found', text: 'Unable to locate the selected repair request.', confirmButtonColor: '#000000' });
      return;
    }

    if (hasActiveWarrantyClaim(targetOrder)) {
      Swal.fire({
        icon: 'warning',
        title: 'Refund Not Allowed',
        text: 'Refund cannot be requested while a warranty claim is active for this repair.',
        confirmButtonColor: '#000000',
      });
      return;
    }

    if (hasReviewForOrder(targetOrder)) {
      Swal.fire({
        icon: 'warning',
        title: 'Refund Not Allowed',
        text: 'Refund request is not allowed after a review has been submitted for this repair.',
        confirmButtonColor: '#000000',
      });
      return;
    }

    const activeRefund = getLatestRefundForOrder(targetOrder);
    if (activeRefund && isRefundInProgress(activeRefund.status)) {
      Swal.fire({ icon: 'warning', title: 'Refund Already In Progress', text: 'A refund request is already being processed for this repair.', confirmButtonColor: '#000000' });
      return;
    }

    if (String(activeRefund?.status || '').toLowerCase() === 'succeeded') {
      Swal.fire({ icon: 'info', title: 'Refund Already Completed', text: 'This repair already has a completed refund.', confirmButtonColor: '#000000' });
      return;
    }

    const paidAmount = Number(targetOrder.total_paid_amount ?? 0);
    const refundedAmount = Number(targetOrder.total_refunded_amount ?? 0);
    const refundableAmount = Math.max(0, paidAmount - refundedAmount);
    const requiresPayoutDestination = targetOrder.refund_requires_payout_destination !== false;
    const originalMethodOnly = Boolean(targetOrder.refund_original_method_only);
    const paymentType = String(targetOrder.refund_payment_type ?? 'mixed');
    const isMixedPaymentRefund = paymentType === 'mixed';

    if (refundableAmount <= 0) {
      Swal.fire({ icon: 'warning', title: 'Refund Unavailable', text: 'No refundable balance is available for this repair.', confirmButtonColor: '#000000' });
      return;
    }
    
    if (!refundReason) {
      Swal.fire({ icon: 'warning', title: 'Please select a reason', confirmButtonColor: '#000000' });
      return;
    }

    if (!isRefundReasonValid()) {
      Swal.fire({ icon: 'warning', title: 'Please provide details for Other reason', confirmButtonColor: '#000000' });
      return;
    }
    
    if (!isMediaRequirementMet()) {
      Swal.fire({ icon: 'warning', title: 'Please upload at least one photo or video', confirmButtonColor: '#000000' });
      return;
    }

    if (requiresPayoutDestination) {
      if (!refundAccountName.trim() || !refundAccountRef.trim()) {
        Swal.fire({
          icon: 'warning',
          title: 'Incomplete Payout Details',
          text: 'Please provide payout account name and account reference/number.',
          confirmButtonColor: '#000000',
        });
        return;
      }

      if (!refundPayoutConsent) {
        Swal.fire({
          icon: 'warning',
          title: 'Confirmation Required',
          text: 'Please confirm that your payout destination details are correct.',
          confirmButtonColor: '#000000',
        });
        return;
      }
    }

    // Show confirmation before submitting
    const confirmationText = originalMethodOnly
      ? 'Your refund will be returned to your original payment method after approval. Please review your details before submitting.'
      : isMixedPaymentRefund
        ? 'For mixed payments: the online-paid portion returns to your original PayMongo method, and the POS-paid portion will be sent to your payout destination after approval. Please review your details before submitting.'
        : 'Your refund will be processed to your provided payout destination after approval. Please review your details before submitting.';

    const result = await Swal.fire({
      title: 'Submit Refund Request?',
      text: confirmationText,
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Yes, Submit',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#000000',
      cancelButtonColor: '#6b7280',
      reverseButtons: true,
    });

    if (!result.isConfirmed) return;

    setIsSubmittingRefund(true);
    try {
      const reasonCode = refundReason.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 100) || 'customer_refund_request';
      const otherReasonSummary = refundReason === 'Other' && refundOtherReason.trim().length > 0
        ? `Other reason details: ${refundOtherReason.trim()}`
        : '';
      const mediaSummary = refundMedia.length > 0
        ? `Uploaded media references: ${refundMedia.map((file) => file.name).join(', ')}`
        : '';
      const reasonNotes = [otherReasonSummary, refundNote.trim(), mediaSummary].filter(Boolean).join('\n').slice(0, 2000);

      const preferredReturnChannel: PreferredReturnChannel =
        originalMethodOnly
          ? 'card'
          : refundMethod === 'card'
            ? 'card'
            : refundMethod === 'bank_transfer'
              ? 'bank_transfer'
              : refundMethod === 'manual_cash'
                ? 'manual_cash'
                : 'gcash';

        const payload = new FormData();
        if (targetOrder.latest_pos_transaction_id && Number(targetOrder.latest_pos_transaction_id) > 0) {
          payload.append('source_transaction_id', String(targetOrder.latest_pos_transaction_id));
        }
        payload.append('request_type', 'full');
        payload.append('requested_amount', String(refundableAmount));
        payload.append('reason_code', reasonCode);
        payload.append('reason_notes', reasonNotes);
        payload.append('preferred_return_channel', preferredReturnChannel);
        payload.append('preferred_return_account_name', requiresPayoutDestination ? refundAccountName.trim() : '');
        payload.append('preferred_return_account_ref', requiresPayoutDestination ? refundAccountRef.trim() : '');
        payload.append('customer_payout_consent', requiresPayoutDestination ? (refundPayoutConsent ? '1' : '0') : '0');

        refundMedia.forEach((file, index) => {
          payload.append(`media[${index}]`, file);
        });

      const response = await fetch(`/api/customer/repairs/${targetOrder.id}/refunds`, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Accept': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        },
        body: payload,
      });

      const raw = await response.text();
      let data: any = null;

      try {
        data = raw ? JSON.parse(raw) : null;
      } catch {
        data = null;
      }

      if (!response.ok) {
        if (data?.message) {
          throw new Error(data.message);
        }

        if (response.status === 413) {
          throw new Error('Upload is too large. Please compress your video and try again.');
        }

        throw new Error(`Refund request failed (${response.status}). Please try again.`);
      }

      setShowRefundModal(false);
      setRefundOrderId(null);
      setRefundStep(1);
      setRefundReason('');
      setRefundOtherReason('');
      setRefundMedia([]);
      setRefundMethod('gcash');
      setRefundAccountName('');
      setRefundAccountRef('');
      setRefundPayoutConsent(false);
      setRefundNote('');

      const successMessage = originalMethodOnly
        ? 'Your refund request has been submitted successfully. Your refund will be returned to your original payment method after approval.'
        : isMixedPaymentRefund
          ? 'Your refund request has been submitted successfully. The online-paid portion will return to your original PayMongo method, and the POS-paid portion will be sent to your selected payout destination after approval.'
          : 'Your refund request has been submitted successfully. The refund will be sent to your selected payout destination after approval.';

      Swal.fire({
        icon: 'success',
        title: 'Refund Request Submitted',
        text: successMessage,
        confirmButtonColor: '#000000',
      });

      fetchRepairs();
    } catch (error) {
      console.error('Error submitting refund request:', error);
      Swal.fire({
        icon: 'error',
        title: 'Failed',
        text: error instanceof Error ? error.message : 'Unable to submit refund request. Please try again.',
        confirmButtonColor: '#000000',
      });
    } finally {
      setIsSubmittingRefund(false);
    }
  };

  const handleMediaUpload = (e: React.ChangeEvent<HTMLInputElement>) => {
    if (e.target.files) {
      const filesArray = Array.from(e.target.files);
      const invalidFile = filesArray.find(
        (file) => !isAllowedRefundImageFile(file) && !isAllowedRefundVideoFile(file)
      );

      if (invalidFile) {
        Swal.fire({
          icon: 'warning',
          title: 'Invalid File Type',
          text: 'Only JPG, JPEG, PNG, WEBP images and MP4, MOV, AVI, MKV, WEBM videos are allowed.',
          confirmButtonColor: '#000000',
        });
        e.target.value = '';
        return;
      }
      
      const newVideos = filesArray.filter((file) => isAllowedRefundVideoFile(file));
      const newImages = filesArray.filter((file) => isAllowedRefundImageFile(file));

      const oversizedVideo = newVideos.find(file => file.size > MAX_REFUND_VIDEO_SIZE_BYTES);
      if (oversizedVideo) {
        Swal.fire({
          icon: 'warning',
          title: 'Video Too Large',
          text: 'Refund video must be 256MB or smaller.',
          confirmButtonColor: '#000000',
        });
        e.target.value = '';
        return;
      }

      const oversizedImage = newImages.find(file => file.size > MAX_REFUND_IMAGE_SIZE_BYTES);
      if (oversizedImage) {
        Swal.fire({
          icon: 'warning',
          title: 'Image Too Large',
          text: 'Each refund image must be 20MB or smaller.',
          confirmButtonColor: '#000000',
        });
        e.target.value = '';
        return;
      }

      if (refundMedia.length + filesArray.length > 10) {
        Swal.fire({
          icon: 'warning',
          title: 'Media Limit Exceeded',
          text: 'You can upload up to 10 media files per refund request.',
          confirmButtonColor: '#000000',
        });
        e.target.value = '';
        return;
      }
      
      // Add files if within limits
      setRefundMedia(prev => [...prev, ...filesArray]);
    }
    
    // Reset the input so the same file can be selected again if needed
    e.target.value = '';
  };

  const removeMedia = (index: number) => {
    setRefundMedia(prev => prev.filter((_, i) => i !== index));
  };

  const isVideoFile = (file: File) => {
    return isAllowedRefundVideoFile(file);
  };

  const isMediaRequirementMet = () => {
    return refundMedia.length >= 1
      && refundMedia.every((file) => isAllowedRefundImageFile(file) || isAllowedRefundVideoFile(file));
  };

  const isRefundReasonValid = () => {
    if (!refundReason) {
      return false;
    }

    if (refundReason !== 'Other') {
      return true;
    }

    return refundOtherReason.trim().length > 0;
  };

  // Phase 10D - Review functions
  const openReviewModal = async (orderId: number) => {
    try {
      // Find the order to get the shop_id
      const order = orders.find(o => o.id === orderId);
      
      if (!order || !order.shop_id) {
        Swal.fire({
          icon: 'error',
          title: 'Error',
          text: 'Shop information not found',
          confirmButtonColor: '#000000',
        });
        return;
      }

      if (hasBlockingRefundForReview(order)) {
        Swal.fire({
          icon: 'info',
          title: 'Review Not Allowed',
          text: 'Review is no longer allowed once a refund request has been filed or completed for this repair.',
          confirmButtonColor: '#000000',
        });
        return;
      }

      if (hasReviewForOrder(order)) {
        Swal.fire({
          icon: 'info',
          title: 'Already Reviewed',
          text: 'You have already submitted a review for this repair.',
          confirmButtonColor: '#000000',
        });
        return;
      }

      // Navigate to the shop page where they can leave a review
      window.location.href = `/repair-shop/${order.shop_id}`;
    } catch (error) {
      console.error('Error navigating to shop:', error);
      Swal.fire({
        icon: 'error',
        title: 'Error',
        text: 'Failed to navigate to shop page',
        confirmButtonColor: '#000000',
      });
    }
  };

  const submitReview = async () => {
    if (!reviewOrderId) return;

    if (reviewRating === 0) {
      Swal.fire({
        icon: 'warning',
        title: 'Rating Required',
        text: 'Please select a star rating',
        confirmButtonColor: '#000000',
      });
      return;
    }

    try {
      const formData = new FormData();
      formData.append('rating', reviewRating.toString());
      if (reviewText) {
        formData.append('review_text', reviewText);
      }
      
      // Append review images
      reviewImages.forEach((file, index) => {
        formData.append(`review_images[${index}]`, file);
      });

      const response = await axios.post(
        `/api/customer/repairs/${reviewOrderId}/review`,
        formData,
        {
          headers: {
            'Content-Type': 'multipart/form-data',
          },
        }
      );

      if (response.data.success) {
        setShowReviewModal(false);
        setReviewOrderId(null);
        setReviewRating(0);
        setReviewText('');
        setReviewImages([]);

        Swal.fire({
          icon: 'success',
          title: 'Review Submitted!',
          text: response.data.message || 'Thank you for your feedback!',
          confirmButtonColor: '#000000',
        });
      }
    } catch (error: any) {
      console.error('Error submitting review:', error);
      Swal.fire({
        icon: 'error',
        title: 'Failed to Submit Review',
        text: error.response?.data?.message || 'Please try again',
        confirmButtonColor: '#000000',
      });
    }
  };

  const handleReviewImageUpload = (e: React.ChangeEvent<HTMLInputElement>) => {
    if (e.target.files) {
      const filesArray = Array.from(e.target.files);
      
      if (reviewImages.length + filesArray.length > 3) {
        Swal.fire({
          icon: 'warning',
          title: 'Image Limit',
          text: 'You can upload up to 3 images only',
          confirmButtonColor: '#000000',
        });
        return;
      }

      setReviewImages(prev => [...prev, ...filesArray]);
    }
    e.target.value = '';
  };

  const removeReviewImage = (index: number) => {
    setReviewImages(prev => prev.filter((_, i) => i !== index));
  };

  const getStatusColor = (order: RepairOrder) => {
    const activeRefund = latestRefundByRepairId[order.id];
    const refundStatus = String(activeRefund?.status || '').toLowerCase();

    if (['requested', 'processing'].includes(refundStatus)) {
      return 'text-blue-700';
    }

    if (refundStatus === 'approved') {
      return 'text-indigo-700';
    }

    if (refundStatus === 'succeeded') {
      return 'text-green-700';
    }

    if (refundStatus === 'failed' || refundStatus === 'rejected') {
      return 'text-red-700';
    }

    const status = order.status;
    switch (status) {
      case 'cancelled':
      case 'rejected':
      case 'repairer_rejected':
      case 'owner_rejected':
        return 'text-red-700';
      case 'repairer_accepted':
      case 'waiting_customer_confirmation':
        return 'text-blue-700';
      case 'owner_approval_pending':
        return 'text-blue-700';
      case 'assigned_to_repairer':
      case 'awaiting_parts':
        return 'text-yellow-700';
      case 'in_progress':
        return 'text-purple-700';
      case 'pending':
      case 'received':
      case 'completed':
      case 'ready_for_pickup':
      case 'shipped':
      case 'picked_up':
      case 'owner_approved':
        return 'text-green-700';
      default:
        return 'text-black';
    }
  };

  const getStatusText = (order: RepairOrder) => {
    const activeRefund = latestRefundByRepairId[order.id];
    const refundStatus = String(activeRefund?.status || '').toLowerCase();

    if (['requested', 'processing'].includes(refundStatus)) {
      return 'Refund Processing';
    }

    if (refundStatus === 'approved') {
      return 'Approved for Refund Execution';
    }

    if (refundStatus === 'succeeded') {
      return 'Refunded';
    }

    if (refundStatus === 'rejected') {
      return 'Refund Rejected';
    }

    if (refundStatus === 'failed') {
      return 'Refund Failed';
    }

    const hasCollectionSummary = order.collection_summary !== undefined
      || order.outstanding_balance !== undefined;
    const hasOutstandingBalance = getOrderOutstandingBalance(order) > 0;
    const isPaymentSettled = hasCollectionSummary
      ? !hasOutstandingBalance
      : order.payment_status === 'paid' || order.payment_status === 'completed';

    if (
      order.status === 'repairer_accepted' &&
      Boolean(order.conversation_id) &&
      isOnlineIntakeFlow(order) &&
      order.payment_status !== 'paid' &&
      order.payment_status !== 'completed' &&
      Boolean(order.payment_enabled) &&
      !isWarrantyNoChargeOrder(order) &&
      !processingPayment
    ) {
      return 'PAY NOW';
    }

    if (order.status === 'ready_for_pickup' && hasOutstandingBalance) {
      return '📦 Ready for Pickup · Waiting for Payment';
    }

    switch (order.status) {
      case 'new_request':
        return 'New Request';
      case 'assigned_to_repairer':
        return 'Assigned to Repairer';
      case 'repairer_accepted':
        return 'Repairer Accepted - Contact for Details';
      case 'waiting_customer_confirmation':
        return 'Confirmed - Work Starting';
      case 'owner_approval_pending':
        return isPaymentSettled ? 'Payment Received - Pending Owner Approval' : 'Pending Owner Approval';
      case 'owner_approved':
        return 'Approved - Work Starting';
      case 'owner_rejected':
        return 'Rejected by Shop Owner';
      case 'in_progress':
        return '🔧 Work In Progress';
      case 'awaiting_parts':
        return '⏳ Awaiting Parts';
      case 'completed':
        if (getReturnMethod(order) === 'walk_in') {
          return '✅ Marked received by repairer in-shop';
        }
        return '✅ Completed - QC Done';
      case 'ready_for_pickup':
        return '📦 Ready for Pickup';
      case 'shipped':
        return '🚚 Shipped - Awaiting Delivery';
      case 'picked_up':
        if (getReturnMethod(order) === 'walk_in') {
          return '✅ Marked received by repairer in-shop';
        }
        return '✅ Completed & Picked Up';
      case 'pending':
        return isPaymentSettled ? 'Payment Received - Ready to Start' : 'Pending - Ready to Start';
      case 'received':
        return 'Shoes Received - Work Starting Soon';
      case 'cancelled':
        return 'Cancelled';
      case 'rejected':
      case 'repairer_rejected':
        return 'Rejected';
      default:
        return order.status;
    }
  };

  const getRepairDuration = (order: RepairOrder) => {
    if (order.duration && order.duration.trim().length > 0) {
      return order.duration;
    }

    const startDate = new Date(order.created_at);
    const endDateValue = order.completed_at || order.estimated_completion;

    if (!endDateValue) {
      return '1-3 days';
    }

    const endDate = new Date(endDateValue);

    if (Number.isNaN(startDate.getTime()) || Number.isNaN(endDate.getTime())) {
      return '1-3 days';
    }

    const millisecondsPerDay = 1000 * 60 * 60 * 24;
    const totalDays = Math.max(1, Math.ceil((endDate.getTime() - startDate.getTime()) / millisecondsPerDay));

    return `${totalDays} day${totalDays > 1 ? 's' : ''}`;
  };

  const normalizeRepairIdList = (ids: number[]): number[] => {
    return Array.from(new Set(ids.filter((id) => Number.isFinite(id) && id > 0))).sort((left, right) => left - right);
  };

  const getRepairIdsByStatus = (status: RepairTab): number[] => {
    if (status === 'return_refund') {
      return orders.filter((order) => isReturnRefundRepair(order)).map((order) => order.id);
    }
    if (status === 'new_request') {
      // NEW REQUEST tab includes both new_request and assigned_to_repairer
      return orders
        .filter((order) => order.status === 'new_request' || order.status === 'assigned_to_repairer')
        .map((order) => order.id);
    }
    if (status === 'pending') {
      // PENDING tab includes pending, repairer_accepted, waiting_customer_confirmation, and owner_approval_pending
      return orders
        .filter((order) =>
          order.status === 'pending'
          || order.status === 'repairer_accepted'
          || order.status === 'waiting_customer_confirmation'
          || order.status === 'owner_approval_pending'
          || order.status === 'owner_approved'
        )
        .map((order) => order.id);
    }
    if (status === 'ready_for_pickup') {
      return orders
        .filter((order) => order.status === 'ready_for_pickup' || order.status === 'shipped')
        .map((order) => order.id);
    }

    return orders.filter((order) => order.status === status).map((order) => order.id);
  };

  const repairIdsByStatus = useMemo(() => {
    const next = {} as Record<RepairTab, number[]>;

    REPAIR_TABS.forEach((status) => {
      next[status] = normalizeRepairIdList(getRepairIdsByStatus(status));
    });

    return next;
  }, [latestRefundByRepairId, orders]);

  const getCountByStatus = (status: RepairTab) => repairIdsByStatus[status]?.length ?? 0;

  const notificationCountByStatus = useMemo(() => {
    const next = {} as Record<RepairTab, number>;

    REPAIR_TABS.forEach((status) => {
      const currentIds = repairIdsByStatus[status] ?? [];
      const seenIds = new Set(seenRepairIdsByTab[status] ?? []);
      next[status] = currentIds.filter((id) => !seenIds.has(id)).length;
    });

    return next;
  }, [repairIdsByStatus, seenRepairIdsByTab]);

  const getNotificationCountByStatus = (status: RepairTab) => notificationCountByStatus[status] ?? 0;

  useEffect(() => {
    if (typeof window === 'undefined') {
      return;
    }

    const currentIds = repairIdsByStatus[selectedTab] ?? [];

    setSeenRepairIdsByTab((prev) => {
      const existingIds = prev[selectedTab] ?? [];
      const hasSameIds = existingIds.length === currentIds.length
        && existingIds.every((id, index) => id === currentIds[index]);

      if (hasSameIds) {
        return prev;
      }

      const next = {
        ...prev,
        [selectedTab]: [...currentIds],
      };

      try {
        localStorage.setItem(REPAIR_TAB_NOTIFICATION_STORAGE_KEY, JSON.stringify(next));
      } catch (error) {
        console.warn('Failed to persist repair tab notification state:', error);
      }

      return next;
    });
  }, [repairIdsByStatus, selectedTab]);

  const filteredOrders = orders.filter(order => {
    if (selectedTab === 'return_refund') {
      return isReturnRefundRepair(order);
    }
    if (selectedTab === 'new_request') {
      // NEW REQUEST tab shows new_request and assigned_to_repairer
      return order.status === 'new_request' || order.status === 'assigned_to_repairer';
    }
    if (selectedTab === 'pending') {
      // PENDING tab shows all confirmation/approval pending statuses
      return order.status === 'pending' || 
             order.status === 'repairer_accepted' || 
             order.status === 'waiting_customer_confirmation' ||
             order.status === 'owner_approval_pending' ||
             order.status === 'owner_approved';
    }
    if (selectedTab === 'ready_for_pickup') {
      return order.status === 'ready_for_pickup' || order.status === 'shipped';
    }
    return order.status === selectedTab;
  });
  const getRepairPaymentHref = (order: RepairOrder) => {
    const params = new URLSearchParams({
      source: 'repair',
      repair_id: String(order.id),
      order_number: order.order_number,
      repair_type: order.repair_type,
      total: String(getOrderGrandTotal(order)),
    });

    return `/payment?${params.toString()}`;
  };

  const refundOrder = refundOrderId ? orders.find((o) => o.id === refundOrderId) : null;
  const warrantyOrder = warrantyOrderId ? orders.find((order) => order.id === warrantyOrderId) : undefined;
  const warrantyIntakeAddress = getWarrantyAddress(warrantyOrder, 'intake');
  const warrantyReturnAddress = getWarrantyAddress(warrantyOrder, 'return');
  const refundTotal = refundOrder ? getOrderGrandTotal(refundOrder) : 0;
  const refundPaidAmount = Number(refundOrder?.total_paid_amount ?? 0);
  const refundRefundedAmount = Number(refundOrder?.total_refunded_amount ?? 0);
  const refundAvailableAmount = Math.max(0, refundPaidAmount - refundRefundedAmount);
  const refundPaymentType = String(refundOrder?.refund_payment_type ?? 'mixed');
  const refundRequiresPayoutDestination = refundOrder ? (refundOrder.refund_requires_payout_destination !== false) : true;
  const refundOriginalMethodOnly = refundOrder ? Boolean(refundOrder.refund_original_method_only) : false;
  const refundMethodOptions = refundPaymentType === 'manual_only'
    ? [{ value: 'gcash', label: 'GCash' }]
    : refundPaymentType === 'mixed'
      ? [
          { value: 'gcash', label: 'GCash' },
          { value: 'card', label: 'Card' },
          { value: 'bank_transfer', label: 'Bank Transfer' },
        ]
      : [
          { value: 'gcash', label: 'GCash' },
          { value: 'card', label: 'Card' },
          { value: 'bank_transfer', label: 'Bank Transfer' },
        ];
  const showPayoutAccountFields = refundRequiresPayoutDestination;
  const tabButtonBaseClass =
    'relative inline-flex min-w-[112px] shrink-0 items-center justify-center gap-2 rounded-2xl border px-3 py-2.5 text-[10px] font-semibold uppercase tracking-[0.14em] transition-all duration-300 focus-visible:outline-none focus-visible:ring-2 sm:min-w-[128px] xl:min-w-0 xl:flex-1 xl:rounded-full xl:px-4 xl:text-[11px] xl:tracking-[0.16em]';
  const tabBadgeClass =
    'pointer-events-none absolute -right-1 top-1 z-10 min-w-[20px] h-[20px] rounded-full bg-red-600 flex items-center justify-center text-[8px] font-bold leading-none text-white';
  const actionButtonBaseClass =
    'inline-flex items-center justify-center gap-2 rounded-full border px-3 py-2 text-[10px] font-semibold uppercase tracking-[0.12em] transition-all duration-300 focus-visible:outline-none focus-visible:ring-2 sm:px-6 sm:py-2.5 sm:text-xs sm:tracking-[0.16em]';
  const actionButtonPrimaryClass =
    'border-[#16233b] bg-[#16233b] text-white hover:-translate-y-0.5 hover:bg-black focus-visible:ring-[#16233b]/45';
  const actionButtonSecondaryClass =
    'border-gray-300 bg-white text-gray-800 hover:-translate-y-0.5 hover:border-gray-400 hover:bg-gray-50 focus-visible:ring-gray-300';
  const actionButtonDangerClass =
    'border-red-600 bg-red-600 text-white hover:-translate-y-0.5 hover:bg-red-700 focus-visible:ring-red-300';
  const actionButtonDisabledClass = 'border-gray-300 bg-gray-200 text-gray-500 cursor-not-allowed';
  const mobileHeroFilterButtonBaseClass =
    'relative inline-flex min-w-[96px] shrink-0 flex-col items-center justify-center gap-1.5 overflow-visible rounded-2xl border pl-3 pr-5 py-3 text-[10px] font-semibold tracking-[0.01em] transition-all duration-300 focus-visible:outline-none focus-visible:ring-2';
  const getTabIcon = (tab: RepairTab) => {
    switch (tab) {
      case 'new_request':
        return (
          <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8">
            <path strokeLinecap="round" strokeLinejoin="round" d="M4 6h16M4 12h16M4 18h16" />
          </svg>
        );
      case 'pending':
        return (
          <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8">
            <path strokeLinecap="round" strokeLinejoin="round" d="M12 8v4l3 2m6-2a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
        );
      case 'received':
      case 'in_progress':
        return (
          <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8">
            <path strokeLinecap="round" strokeLinejoin="round" d="M3 7h13l4 4v6a2 2 0 01-2 2h-1m-10 0h8m-8 0a2 2 0 11-4 0 2 2 0 014 0zm8 0a2 2 0 104 0 2 2 0 00-4 0z" />
          </svg>
        );
      case 'ready_for_pickup':
        return (
          <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8">
            <path strokeLinecap="round" strokeLinejoin="round" d="M3 12h2l2 5h10l2-8H7m0 0L5.5 5H3" />
          </svg>
        );
      case 'picked_up':
        return (
          <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8">
            <path strokeLinecap="round" strokeLinejoin="round" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.02 3.138a1 1 0 00.95.69h3.3c.969 0 1.371 1.24.588 1.81l-2.67 1.94a1 1 0 00-.364 1.118l1.02 3.138c.3.922-.755 1.688-1.54 1.118l-2.67-1.94a1 1 0 00-1.176 0l-2.67 1.94c-.784.57-1.838-.196-1.539-1.118l1.02-3.138a1 1 0 00-.364-1.118l-2.67-1.94c-.784-.57-.38-1.81.588-1.81h3.3a1 1 0 00.95-.69l1.02-3.138z" />
          </svg>
        );
      case 'cancelled':
      case 'rejected':
      default:
        return (
          <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8">
            <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
          </svg>
        );
    }
  };

  const getTabLabel = (tab: RepairTab) => {
    switch (tab) {
      case 'new_request': return 'New Request';
      case 'pending': return 'Pending';
      case 'received': return 'Received';
      case 'in_progress': return 'In Progress';
      case 'ready_for_pickup': return 'Ready';
      case 'picked_up': return 'Completed';
      case 'return_refund': return 'Return/Refund';
      case 'cancelled': return 'Cancelled';
      case 'rejected': return 'Rejected';
      default: return 'Status';
    }
  };

  return (
    <CustomerFooterReveal>
    <div ref={revealRootRef} className="min-h-screen flex flex-col bg-[#f3f4f6] xl:bg-white">
      <Head title="Repairs" />
      <Navigation />

      <main className="flex-1">
        <div className="w-full pb-16 pt-24 sm:px-6 xl:pt-32 xl:px-10 2xl:px-14">
          {/* Mobile Tabs */}
          <div data-scroll-reveal className="scroll-reveal flex w-full gap-2 overflow-x-auto pb-3 pl-4 pr-4 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden xl:hidden">
            {REPAIR_TABS.map((tab) => (
              <button
                key={tab}
                onClick={() => setSelectedTab(tab)}
                className={`${mobileHeroFilterButtonBaseClass} ${
                  selectedTab === tab
                    ? 'border-[#16233b] bg-[#16233b] text-white shadow-[0_12px_28px_-18px_rgba(22,35,59,0.65)]'
                    : 'border-gray-200 bg-white text-black/70 hover:-translate-y-0.5 hover:border-gray-300 hover:text-black'
                }`}
              >
                {getTabIcon(tab)}
                <span className="leading-none">{getTabLabel(tab)}</span>
                {getNotificationCountByStatus(tab) > 0 && <span className={tabBadgeClass}>{getNotificationCountByStatus(tab)}</span>}
              </button>
            ))}
          </div>

          {/* Desktop Tabs */}
          <div data-scroll-reveal className="scroll-reveal mb-6 hidden w-full gap-2 overflow-x-auto pb-2 pt-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden xl:mb-12 xl:flex xl:gap-3 xl:pt-2">
            <button
              onClick={() => setSelectedTab('new_request')}
              className={`${tabButtonBaseClass} ${
                selectedTab === 'new_request'
                  ? 'border-[#16233b] bg-[#16233b] text-white shadow-[0_12px_28px_-18px_rgba(22,35,59,0.65)]'
                  : 'border-gray-200 bg-white text-black/70 hover:-translate-y-0.5 hover:border-gray-300 hover:text-black'
              }`}
            >
              NEW REQUEST
              {getNotificationCountByStatus('new_request') > 0 && (
                <span className={tabBadgeClass}>
                  {getNotificationCountByStatus('new_request')}
                </span>
              )}
            </button>
            <button
              onClick={() => setSelectedTab('pending')}
              className={`${tabButtonBaseClass} ${
                selectedTab === 'pending'
                  ? 'border-[#16233b] bg-[#16233b] text-white shadow-[0_12px_28px_-18px_rgba(22,35,59,0.65)]'
                  : 'border-gray-200 bg-white text-black/70 hover:-translate-y-0.5 hover:border-gray-300 hover:text-black'
              }`}
            >
              PENDING
              {getNotificationCountByStatus('pending') > 0 && (
                <span className={tabBadgeClass}>
                  {getNotificationCountByStatus('pending')}
                </span>
              )}
            </button>
            <button
              onClick={() => setSelectedTab('received')}
              className={`${tabButtonBaseClass} ${
                selectedTab === 'received'
                  ? 'border-[#16233b] bg-[#16233b] text-white shadow-[0_12px_28px_-18px_rgba(22,35,59,0.65)]'
                  : 'border-gray-200 bg-white text-black/70 hover:-translate-y-0.5 hover:border-gray-300 hover:text-black'
              }`}
            >
              RECEIVED
              {getNotificationCountByStatus('received') > 0 && (
                <span className={tabBadgeClass}>
                  {getNotificationCountByStatus('received')}
                </span>
              )}
            </button>
            <button
              onClick={() => setSelectedTab('in_progress')}
              className={`${tabButtonBaseClass} ${
                selectedTab === 'in_progress'
                  ? 'border-[#16233b] bg-[#16233b] text-white shadow-[0_12px_28px_-18px_rgba(22,35,59,0.65)]'
                  : 'border-gray-200 bg-white text-black/70 hover:-translate-y-0.5 hover:border-gray-300 hover:text-black'
              }`}
            >
              IN PROGRESS
              {getNotificationCountByStatus('in_progress') > 0 && (
                <span className={tabBadgeClass}>
                  {getNotificationCountByStatus('in_progress')}
                </span>
              )}
            </button>
            <button
              onClick={() => setSelectedTab('ready_for_pickup')}
              className={`${tabButtonBaseClass} ${
                selectedTab === 'ready_for_pickup'
                  ? 'border-[#16233b] bg-[#16233b] text-white shadow-[0_12px_28px_-18px_rgba(22,35,59,0.65)]'
                  : 'border-gray-200 bg-white text-black/70 hover:-translate-y-0.5 hover:border-gray-300 hover:text-black'
              }`}
            >
              READY FOR PICKUP
              {getNotificationCountByStatus('ready_for_pickup') > 0 && (
                <span className={tabBadgeClass}>
                  {getNotificationCountByStatus('ready_for_pickup')}
                </span>
              )}
            </button>
            <button
              onClick={() => setSelectedTab('picked_up')}
              className={`${tabButtonBaseClass} ${
                selectedTab === 'picked_up'
                  ? 'border-[#16233b] bg-[#16233b] text-white shadow-[0_12px_28px_-18px_rgba(22,35,59,0.65)]'
                  : 'border-gray-200 bg-white text-black/70 hover:-translate-y-0.5 hover:border-gray-300 hover:text-black'
              }`}
            >
              COMPLETED
              {getNotificationCountByStatus('picked_up') > 0 && (
                <span className={tabBadgeClass}>
                  {getNotificationCountByStatus('picked_up')}
                </span>
              )}
            </button>
            <button
              onClick={() => setSelectedTab('return_refund')}
              className={`${tabButtonBaseClass} ${
                selectedTab === 'return_refund'
                  ? 'border-[#16233b] bg-[#16233b] text-white shadow-[0_12px_28px_-18px_rgba(22,35,59,0.65)]'
                  : 'border-gray-200 bg-white text-black/70 hover:-translate-y-0.5 hover:border-gray-300 hover:text-black'
              }`}
            >
              RETURN/REFUND
              {getNotificationCountByStatus('return_refund') > 0 && (
                <span className={tabBadgeClass}>
                  {getNotificationCountByStatus('return_refund')}
                </span>
              )}
            </button>
            <button
              onClick={() => setSelectedTab('cancelled')}
              className={`${tabButtonBaseClass} ${
                selectedTab === 'cancelled'
                  ? 'border-[#16233b] bg-[#16233b] text-white shadow-[0_12px_28px_-18px_rgba(22,35,59,0.65)]'
                  : 'border-gray-200 bg-white text-black/70 hover:-translate-y-0.5 hover:border-gray-300 hover:text-black'
              }`}
            >
              CANCELLED
              {getNotificationCountByStatus('cancelled') > 0 && (
                <span className={tabBadgeClass}>
                  {getNotificationCountByStatus('cancelled')}
                </span>
              )}
            </button>
            <button
              onClick={() => setSelectedTab('rejected')}
              className={`${tabButtonBaseClass} ${
                selectedTab === 'rejected'
                  ? 'border-[#16233b] bg-[#16233b] text-white shadow-[0_12px_28px_-18px_rgba(22,35,59,0.65)]'
                  : 'border-gray-200 bg-white text-black/70 hover:-translate-y-0.5 hover:border-gray-300 hover:text-black'
              }`}
            >
              REJECTED
              {getNotificationCountByStatus('rejected') > 0 && (
                <span className={tabBadgeClass}>
                  {getNotificationCountByStatus('rejected')}
                </span>
              )}
            </button>
          </div>

          <div className="mx-auto mt-6 max-w-6xl px-4 xl:px-0">

          {/* Loading State */}
          {loading && (
            <div data-scroll-reveal className="scroll-reveal text-center py-32">
              <div className="inline-block animate-spin rounded-full h-12 w-12 border-b-2 border-black"></div>
              <p className="mt-6 text-gray-500 text-sm">Loading your repairs...</p>
            </div>
          )}

          {/* Empty State */}
          {!loading && filteredOrders.length === 0 && (
            <div data-scroll-reveal className="scroll-reveal rounded-3xl border border-gray-200 bg-white px-5 py-14 text-center shadow-[0_20px_40px_-36px_rgba(15,23,42,0.7)] xl:rounded-none xl:border-0 xl:bg-gray-50 xl:py-20 xl:shadow-none">
              <div className="mb-6">
                <svg className="w-24 h-24 mx-auto text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
                </svg>
              </div>
              <h3 className="text-2xl font-semibold text-gray-800 mb-2">No Repairs Yet</h3>
              <p className="text-gray-500 mb-8">Book a repair service to see your repairs here!</p>
              <Link
                href="/repair-services"
                className={`${actionButtonBaseClass} ${actionButtonPrimaryClass}`}
              >
                Browse Repair Services
              </Link>
            </div>
          )}

          {/* Repair Orders List */}
          {!loading && filteredOrders.length > 0 && (
            <div className="space-y-4 sm:space-y-7 xl:space-y-8">
              {filteredOrders.map((order) => (
                <div
                  key={order.id}
                  data-repair-id={order.id}
                  data-scroll-reveal
                  className={`scroll-reveal border overflow-hidden transition-shadow duration-300 rounded-3xl bg-white shadow-[0_12px_35px_-32px_rgba(15,23,42,0.75)] hover:shadow-[0_18px_45px_-30px_rgba(15,23,42,0.65)] xl:rounded-none xl:shadow-none xl:hover:shadow-lg ${
                    highlightRepairId === order.id ? 'border-black bg-gray-50/40 xl:bg-gray-50/30' : 'border-gray-200'
                  }`}
                >
                  {/* Order Header */}
                  <div className="userside-order-date-header border-b border-gray-100 bg-linear-to-r from-white via-white to-gray-50 px-3 py-3 sm:px-8 sm:py-5 xl:border-gray-200 xl:bg-white">
                    <div className="flex items-start justify-between gap-3 sm:flex-wrap sm:items-center sm:gap-4">
                      <div className="flex items-center gap-3 sm:gap-8">
                        <div>
                          <p className="text-xs text-gray-400 uppercase tracking-wider mb-1">Order Date</p>
                          <p className="text-sm text-black">
                            {new Date(order.created_at).toLocaleDateString('en-US', {
                              year: 'numeric',
                              month: 'short',
                              day: 'numeric',
                            })}
                          </p>
                        </div>
                      </div>
                      <div className="ml-auto shrink-0 flex items-end gap-1.5">
                        <span
                          className={`inline-flex items-center justify-end px-3 py-1 text-[9px] font-semibold tracking-[0.12em] uppercase sm:px-4 sm:py-1.5 sm:text-xs whitespace-nowrap ${getStatusColor(
                            order
                          )}`}
                          title={getStatusText(order)}
                        >
                          <span className="max-w-28 truncate sm:max-w-none">{getStatusText(order)}</span>
                        </span>

                        <div className="flex items-center justify-end gap-2">

                        {(['repairer_accepted', 'pending'].includes(order.status)) &&
                          (getIntakeMethod(order) === 'walk_in' || order.conversation_id) &&
                          !order.estimated_completion && (
                          <button
                            onClick={() => {
                              setScheduleOrderId(order.id);
                              setScheduleShopId(order.shop_owner_id ?? null);
                              setScheduleVisibleMonthKey(getMonthKey(new Date()));
                              setScheduleSelectedDate('');
                              setShopClosedDayNumbers(new Set());
                              setShowScheduleModal(true);
                            }}
                            className="inline-flex h-9 w-9 items-center justify-center rounded-full border border-[#16233b] bg-[#16233b] text-white transition-all duration-300 hover:-translate-y-0.5 hover:bg-black"
                            title="Set Schedule"
                            aria-label="Set Schedule"
                          >
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                          </button>
                        )}

                        {((order.status === 'repairer_accepted' && order.conversation_id && order.payment_status !== 'paid' && order.payment_status !== 'completed') ||
                          (order.status === 'received' && order.conversation_id && getIntakeMethod(order) === 'walk_in')) && (
                          <Link
                            href={`/customer/conversations?conversation_id=${order.conversation_id}`}
                            className="relative inline-flex h-9 w-9 items-center justify-center rounded-full border border-gray-300 bg-white text-gray-700 transition-all duration-300 hover:-translate-y-0.5 hover:border-gray-400 hover:bg-gray-50"
                            title="Chat with Repairer"
                            aria-label="Chat with Repairer"
                          >
                            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                            </svg>
                            {getUnreadCountForConversation(order.conversation_id) > 0 && (
                              <span className="absolute -top-2 -right-2 min-w-4 h-4 px-1 rounded-full bg-red-600 text-white text-[10px] font-semibold leading-4 text-center">
                                {getUnreadCountForConversation(order.conversation_id) > 99 ? '99+' : getUnreadCountForConversation(order.conversation_id)}
                              </span>
                            )}
                          </Link>
                        )}

                        </div>
                      </div>
                    </div>
                  </div>

                  {/* Order Details */}
                  <div className="p-3 sm:p-8">
                    {order.status === 'repairer_accepted' && getIntakeMethod(order) === 'walk_in' && (
                      <div className="mb-5 rounded-2xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">
                        <p className="font-semibold">Accepted — Bring Item to Shop</p>
                        <p className="mt-1">
                          {isInitialPaymentDue(order)
                            ? 'Repair request accepted. Bring your shoes to the shop and complete the required payment so the shop can receive the item.'
                            : 'Repair request accepted. Bring your shoes to the shop so the shop can receive the item.'}
                        </p>
                      </div>
                    )}
                    <div className="rounded-2xl border border-gray-200 bg-gray-50 p-3 xl:hidden">
                      <div className="flex items-start gap-3">
                        <div className="h-14 w-14 shrink-0 overflow-hidden rounded-lg border border-gray-200 bg-white">
                          {order.image ? (
                            <img
                              src={order.image}
                              alt={order.repair_type}
                              className="h-full w-full object-cover"
                            />
                          ) : (
                            <div className="flex h-full w-full items-center justify-center text-gray-300">
                              <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path
                                  strokeLinecap="round"
                                  strokeLinejoin="round"
                                  strokeWidth={1.5}
                                  d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"
                                />
                              </svg>
                            </div>
                          )}
                        </div>
                        <div className="min-w-0 grow">
                          <h3 className="truncate text-sm font-semibold text-black">{order.repair_type}</h3>
                          <p className="mt-0.5 line-clamp-2 text-xs text-gray-500">{order.description}</p>
                          <p className="mt-1 text-xs text-gray-500">Duration: {getRepairDuration(order)}</p>
                        </div>
                        <div className="shrink-0 pl-2 text-right">
                          <p className="text-xs text-gray-400">Repair Total</p>
                          <p className="text-sm font-semibold text-black">
                            {isWarrantyNoChargeOrder(order) ? 'No-charge Warranty Rework' : formatCurrency(getOrderGrandTotal(order))}
                          </p>
                        </div>
                      </div>
                    </div>

                    <div className="hidden xl:flex xl:gap-6">
                      {/* Item Image */}
                      <div className="h-20 w-20 shrink-0 overflow-hidden border border-gray-200 bg-white sm:h-24 sm:w-24">
                        {order.image ? (
                          <img
                            src={order.image}
                            alt={order.repair_type}
                            className="w-full h-full object-cover"
                          />
                        ) : (
                          <div className="w-full h-full flex items-center justify-center text-gray-300">
                            <svg className="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                              <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                strokeWidth={1}
                                d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"
                              />
                            </svg>
                          </div>
                        )}
                      </div>

                      {/* Item Details */}
                      <div className="flex-1">
                        <h3 className="mb-2 text-base font-bold text-black sm:text-xl">{order.repair_type}</h3>
                        <p className="mb-4 text-sm text-gray-600 sm:text-base">{order.description}</p>

                        {order.repair_package_id && (
                          <div className="mb-4 rounded-lg border border-gray-200 bg-gray-50 p-3 sm:p-4">
                            <div className="flex items-center justify-between gap-4">
                              <div>
                                <p className="text-xs uppercase tracking-wide text-gray-500">Package</p>
                                <p className="text-sm font-semibold text-black">{order.pricing_breakdown?.package_name || 'Repair package selected'}</p>
                              </div>
                              <div className="text-right">
                                <p className="text-xs uppercase tracking-wide text-gray-500">Base Price</p>
                                <p className="text-sm font-semibold text-black">{formatCurrency(getOrderDisplayedBasePrice(order))}</p>
                                {isWarrantyNoChargeOrder(order) && (
                                  <p className="mt-1 text-[11px] text-gray-500">No additional charge</p>
                                )}
                              </div>
                            </div>

                            {!!order.included_services_snapshot?.length && (
                              <div className="mt-3">
                                <p className="text-xs font-medium uppercase tracking-wide text-gray-500 mb-2">Included Services</p>
                                <div className="flex flex-wrap gap-2">
                                  {order.included_services_snapshot.map((service) => (
                                    <span key={`included-${order.id}-${service.id}`} className="inline-flex items-center rounded-full bg-white px-3 py-1 text-xs text-gray-700 border border-gray-200">
                                      {service.name}
                                    </span>
                                  ))}
                                </div>
                              </div>
                            )}

                            {!!order.add_on_services_snapshot?.length && (
                              <div className="mt-3">
                                <div className="flex items-center justify-between gap-4 mb-2">
                                  <p className="text-xs font-medium uppercase tracking-wide text-gray-500">Add-ons</p>
                                  <p className="text-xs font-semibold text-black">{formatCurrency(order.add_ons_total)}</p>
                                </div>
                                <div className="flex flex-wrap gap-2">
                                  {order.add_on_services_snapshot.map((service) => (
                                    <span key={`addon-${order.id}-${service.id}`} className="inline-flex items-center rounded-full bg-white px-3 py-1 text-xs text-gray-700 border border-gray-200">
                                      {service.name}
                                    </span>
                                  ))}
                                </div>
                              </div>
                            )}
                          </div>
                        )}

                        <div className="mt-5 grid grid-cols-1 gap-4 md:grid-cols-3 sm:mt-6">
                          <div>
                            <p className="text-xs text-gray-400 uppercase tracking-wider mb-1">Shop Location</p>
                            {order.shop_id ? (
                              <Link
                                href={`/shop-profile/${order.shop_id}`}
                                className="text-sm text-black font-medium underline"
                              >
                                {order.shop_name}
                              </Link>
                            ) : (
                              <p className="text-sm text-black font-medium">{order.shop_name}</p>
                            )}
                            <p className="text-sm text-gray-500">{order.shop_address}</p>
                          </div>
                          <div>
                            <p className="text-xs text-gray-400 uppercase tracking-wider mb-1">Duration</p>
                            <p className="text-sm text-black font-medium">{getRepairDuration(order)}</p>
                            <p className="text-xs text-gray-500 mt-1">
                              Note: Duration starts once the status is IN PROGRESS.
                            </p>
                          </div>
                          <div>
                            <p className="text-xs text-gray-400 uppercase tracking-wider mb-1">To Shop (Intake)</p>
                            <p className="text-sm text-black font-medium">
                              {getIntakeMethodLabel(order)}
                            </p>
                          </div>
                          <div>
                            <p className="text-xs text-gray-400 uppercase tracking-wider mb-1">To Customer (Return)</p>
                            <p className="text-sm text-black font-medium">
                              {getReturnMethodLabel(order)}
                            </p>
                          </div>
                          {order.estimated_completion && (
                            <div>
                              <p className="text-xs text-gray-400 uppercase tracking-wider mb-1">
                                {(order.status === 'completed' || order.status === 'picked_up') && order.completed_at
                                  ? (getReturnMethod(order) === 'walk_in' ? 'Marked Received On' : 'Completed On')
                                  : 'Preferred Date'}
                              </p>
                              <p className="text-sm text-black font-medium">
                                {order.completed_at || order.estimated_completion}
                              </p>
                            </div>
                          )}
                        </div>

                      </div>

                    </div>

                    <div className="mt-5 grid grid-cols-1 gap-4 xl:hidden">
                      {order.repair_package_id && (
                        <div className="rounded-lg border border-gray-200 bg-gray-50 p-3">
                          <div className="flex items-center justify-between gap-3">
                            <div>
                              <p className="text-xs uppercase tracking-wide text-gray-500">Package</p>
                              <p className="text-sm font-semibold text-black">{order.pricing_breakdown?.package_name || 'Repair package selected'}</p>
                            </div>
                            <div className="text-right">
                              <p className="text-xs uppercase tracking-wide text-gray-500">Base Price</p>
                              <p className="text-sm font-semibold text-black">{formatCurrency(getOrderDisplayedBasePrice(order))}</p>
                              {isWarrantyNoChargeOrder(order) && (
                                <p className="mt-1 text-[11px] text-gray-500">No additional charge</p>
                              )}
                            </div>
                          </div>
                        </div>
                      )}

                      <div>
                        <p className="text-xs text-gray-400 uppercase tracking-wider mb-1">Shop Location</p>
                        {order.shop_id ? (
                          <Link
                            href={`/shop-profile/${order.shop_id}`}
                            className="text-sm font-medium text-black underline"
                          >
                            {order.shop_name}
                          </Link>
                        ) : (
                          <p className="text-sm font-medium text-black">{order.shop_name}</p>
                        )}
                        <p className="text-sm text-gray-500">{order.shop_address}</p>
                      </div>
                      <div className="space-y-3">
                        <div className="grid grid-cols-[118px_minmax(0,1fr)] items-start gap-x-3">
                          <p className="text-xs text-gray-400 uppercase tracking-wider">Duration</p>
                          <p className="text-right text-sm font-semibold leading-5 text-black">{getRepairDuration(order)}</p>
                        </div>
                        <div className="grid grid-cols-[118px_minmax(0,1fr)] items-start gap-x-3">
                          <span></span>
                          <p className="truncate text-right text-[10px] leading-4 text-gray-500 whitespace-nowrap">Note: Duration starts once the status is IN PROGRESS.</p>
                        </div>
                        <div className="grid grid-cols-[118px_minmax(0,1fr)] items-start gap-x-3">
                          <p className="text-xs text-gray-400 uppercase tracking-wider">To Shop (Intake)</p>
                          <p className="text-right text-sm font-medium leading-5 text-black wrap-break-word">{getIntakeMethodLabel(order)}</p>
                        </div>
                        <div className="grid grid-cols-[118px_minmax(0,1fr)] items-start gap-x-3">
                          <p className="text-xs text-gray-400 uppercase tracking-wider">To Customer (Return)</p>
                          <p className="text-right text-sm font-medium leading-5 text-black wrap-break-word">{getReturnMethodLabel(order)}</p>
                        </div>
                      </div>
                      {order.estimated_completion && (
                        <div className="grid grid-cols-[118px_minmax(0,1fr)] items-start gap-x-3">
                          <p className="text-xs text-gray-400 uppercase tracking-wider">
                            {(order.status === 'completed' || order.status === 'picked_up') && order.completed_at
                              ? (getReturnMethod(order) === 'walk_in' ? 'Marked Received On' : 'Completed On')
                              : 'Preferred Date'}
                          </p>
                          <p className="text-right text-sm font-medium leading-5 text-black wrap-break-word">{order.completed_at || order.estimated_completion}</p>
                        </div>
                      )}

                    </div>

                    <CustomerExternalTrackingCard
                      order={order}
                      leg="intake"
                      onRefresh={() => fetchRepairs({ silent: true })}
                    />

                    {order.pickup_recovery && (
                        <SponsoredIntakeReplanCard
                          order={order}
                          onRefresh={() => fetchRepairs({ silent: true })}
                          onPay={() => handlePayNow(order.id)}
                        />
                      )}

                    {order.return_recovery && (
                      <section
                        aria-label="Repair return recovery"
                        className="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-4 sm:p-6"
                      >
                        <h4 className="text-lg font-bold text-black">
                          {order.return_recovery.state === 'shop_pickup'
                            ? 'Ready for pickup at shop'
                            : order.return_recovery.label}
                        </h4>
                        <p className="mt-2 text-sm text-gray-700">
                          {order.return_recovery.message
                            || (order.return_recovery.state === 'awaiting_arrangement'
                              ? 'Your repaired shoes are safely at the shop. Choose re-delivery or free shop pickup.'
                              : order.return_recovery.state === 'awaiting_payment'
                                ? 'Confirm your return address, then pay the new delivery fee.'
                                : order.return_recovery.state === 'shop_pickup'
                                  ? 'Shop pickup is free. Wait for the shop to record the handoff before confirming receipt.'
                                  : 'Re-delivery payment received. Waiting for rider assignment.')}
                        </p>
                        {order.return_recovery.state === 'awaiting_arrangement'
                          && order.return_recovery.actions_available !== false && (
                          <CustomerReturnRecoveryActions
                            order={order}
                            onRefresh={() => fetchRepairs({ silent: true })}
                          />
                        )}
                        {order.return_recovery.state === 'shop_pickup' && (
                          <div className="mt-3 rounded-xl border border-amber-200 bg-white p-3 text-sm text-gray-700">
                            <p className="font-semibold text-black">{order.return_recovery.shop?.name || order.shop_name}</p>
                            <p className="mt-1">{order.return_recovery.shop?.address || order.shop_address}</p>
                          </div>
                        )}
                      </section>
                    )}

                    {(['completed', 'ready_for_pickup', 'shipped'] as RepairStatus[]).includes(order.status)
                      && (!order.return_recovery || order.return_recovery.state === 'awaiting_payment') && (
                      <ReturnDeliveryPlanCard
                        order={order}
                        onRefresh={() => fetchRepairs({ silent: true })}
                      />
                    )}

                    {(['completed', 'ready_for_pickup', 'shipped'] as RepairStatus[]).includes(order.status)
                      && !order.return_recovery && (
                      <CustomerExternalTrackingCard
                        order={order}
                        leg="return"
                        onRefresh={() => fetchRepairs({ silent: true })}
                      />
                    )}

                    <RepairLogisticsTracking
                      shipments={order.return_recovery
                        ? order.logistics_shipments?.filter((shipment) => shipment.purpose !== 'repair_return')
                        : order.logistics_shipments}
                    />

                    {(() => {
                      const latestRefund = getLatestRefundForOrder(order);
                      if (!latestRefund) return null;

                      return (
                        <div className="mt-6 border-t border-gray-200 pt-6">
                          <p className="mb-3 text-sm text-gray-500 uppercase tracking-wider">Refund Information</p>
                          <div className="space-y-3 sm:grid sm:grid-cols-2 sm:gap-y-4 sm:gap-x-10 sm:space-y-0">
                            <div className="flex items-start justify-between gap-3 sm:block">
                              <p className="text-xs text-gray-400 uppercase tracking-wider sm:mb-1">Status</p>
                              <p className="text-right text-sm text-black font-medium sm:text-left">
                                {getRefundStatusLabel(latestRefund)}
                              </p>
                            </div>

                            <div className="flex items-start justify-between gap-3 sm:block">
                              <p className="text-xs text-gray-400 uppercase tracking-wider sm:mb-1">Refund Amount</p>
                              <p className="text-right text-sm text-black font-medium sm:text-left">
                                {formatCurrency((latestRefund.approved_amount ?? latestRefund.requested_amount) || 0)}
                              </p>
                            </div>

                            <div className="flex items-start justify-between gap-3 sm:block">
                              <p className="text-xs text-gray-400 uppercase tracking-wider sm:mb-1">Reference</p>
                              <p className="text-right text-sm text-black font-medium sm:text-left">
                                {latestRefund.execution_reference_masked || '-'}
                              </p>
                            </div>

                            <div className="flex items-start justify-between gap-3 sm:block">
                              <p className="text-xs text-gray-400 uppercase tracking-wider sm:mb-1">Notes</p>
                              <p className="text-right text-sm text-black font-medium sm:text-left">
                                {latestRefund.failure_reason || '-'}
                              </p>
                            </div>

                            {!!latestRefund.execution_proof_urls?.length && (
                              <div className="sm:col-span-2">
                                <p className="text-xs text-gray-400 uppercase tracking-wider mb-2">Proof Images</p>
                                <div className="grid grid-cols-3 gap-2 sm:grid-cols-4">
                                  {latestRefund.execution_proof_urls
                                    .filter((proofUrl) => isProofImageUrl(proofUrl))
                                    .map((proofUrl, proofIndex) => (
                                      <a
                                        key={`refund-proof-thumb-detail-${order.id}-${proofIndex}`}
                                        href={proofUrl}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="overflow-hidden rounded border border-gray-300 bg-white"
                                        title={`Open proof ${proofIndex + 1}`}
                                      >
                                        <img
                                          src={proofUrl}
                                          alt={`Refund proof ${proofIndex + 1}`}
                                          className="h-16 w-full object-cover"
                                          loading="lazy"
                                        />
                                      </a>
                                    ))}
                                </div>
                              </div>
                            )}
                          </div>
                        </div>
                      );
                    })()}

                    {/* Shop at capacity notice — shown when the shop can't accept new repairs yet */}
                    {(order.status === 'new_request' || order.status === 'assigned_to_repairer') &&
                      order.shop_owner_id != null &&
                      shopCapacityCache[order.shop_owner_id]?.is_full && (
                      <div className="mt-6 flex items-start gap-3 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                        <svg className="mt-0.5 w-4 h-4 shrink-0 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                        </svg>
                        <span>
                          <strong>Shop is currently at full capacity</strong> ({shopCapacityCache[order.shop_owner_id].active_count}/{shopCapacityCache[order.shop_owner_id].limit} repairs active).
                          Your request is queued and will be accepted once capacity opens up.
                        </span>
                      </div>
                    )}

                    {/* Repair Total */}
                    <div className="mt-6 border-t border-gray-200 pt-5 sm:mt-8 sm:pt-6">
                      <div className="flex items-start justify-between gap-4">
                        <div>
                          <p className="text-sm text-gray-500 uppercase tracking-wider mb-1">Shop</p>
                          {order.shop_id ? (
                            <Link
                              href={`/shop-profile/${order.shop_id}`}
                              className="text-sm font-semibold text-black underline"
                            >
                              {order.shop_name}
                            </Link>
                          ) : (
                            <p className="text-sm font-semibold text-black">{order.shop_name}</p>
                          )}
                        </div>
                        <div className="text-right">
                          {isWarrantyNoChargeOrder(order) ? (
                            <div className="mb-2 space-y-1 text-xs text-gray-600">
                              <p className="font-semibold text-emerald-700">Warranty repair: Free</p>
                              {Number(order.intake_delivery_fee || 0) > 0 && (
                                <div className="flex items-center justify-end gap-3">
                                  <span>Pickup shipping fee</span>
                                  <span className="text-gray-800">{formatCurrency(order.intake_delivery_fee)}</span>
                                </div>
                              )}
                              {Number(order.return_delivery_fee || 0) > 0 && (
                                <div className="flex items-center justify-end gap-3">
                                  <span>{order.redelivery_payment_due ? 'New shipping fee' : 'Return shipping fee'}</span>
                                  <span className="text-gray-800">{formatCurrency(order.return_delivery_fee)}</span>
                                </div>
                              )}
                            </div>
                          ) : (
                            <div className="space-y-1 mb-2 text-xs text-gray-500">
                              <div className="flex items-center justify-end gap-3">
                                <span>Subtotal</span>
                                <span className="text-gray-700">{formatCurrency(getOrderSubtotal(order))}</span>
                              </div>
                              <div className="flex items-center justify-end gap-3">
                                <span>{`VAT (${getOrderVatRate(order)}%)`}</span>
                                <span className="text-gray-700">{formatCurrency(getOrderVatAmount(order))}</span>
                              </div>
                            </div>
                          )}
                          <p className="text-sm text-gray-500 uppercase tracking-wider mb-1">
                            {isWarrantyNoChargeOrder(order) ? 'Previously Paid' : 'Total Paid'}
                          </p>
                          <p className="font-bold text-black text-2xl">{formatCurrency(getOrderDisplayedPaidAmount(order))}</p>
                          {!isWarrantyNoChargeOrder(order) && (order.repair_package_id || Number(order.add_ons_total || 0) > 0) && (
                            <p className="mt-1 text-xs text-gray-500">
                              {order.repair_package_id
                                ? `Package ${formatCurrency(order.package_price)}${Number(order.add_ons_total || 0) > 0 ? ` + Add-ons ${formatCurrency(order.add_ons_total)}` : ''}`
                                : `Add-ons ${formatCurrency(order.add_ons_total)}`}
                            </p>
                          )}
                        </div>
                      </div>
                    </div>

                    {/* Action Buttons */}
                    <div className="mt-4 ml-auto flex w-full flex-wrap justify-end gap-2 border-t border-gray-200 pt-4 sm:mt-6 sm:gap-3 sm:pt-6 xl:gap-4">
                      {latestWarrantyClaimByRepairId[order.id] && (
                        <div className="mr-auto w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700 sm:w-auto">
                          <p className="font-semibold">{getWarrantyClaimStatusLabel(latestWarrantyClaimByRepairId[order.id].status)}</p>
                          <p>
                            {(latestWarrantyClaimByRepairId[order.id].claim_no || 'Warranty claim')}
                            {latestWarrantyClaimByRepairId[order.id].rejection_reason ? ` • ${latestWarrantyClaimByRepairId[order.id].rejection_reason}` : ''}
                          </p>
                        </div>
                      )}
                      {/* Chat with Repairer actions */}
                      {order.status === 'repairer_accepted' &&
                        order.conversation_id &&
                        order.shop_owner_id &&
                        ![
                          'paid',
                          'completed',
                          'down_payment_paid',
                          'partially_paid',
                          'partially_refunded',
                          'refunded',
                        ].includes(String(order.payment_status || '').toLowerCase()) &&
                        !order.payment_completed_at &&
                        Number(order.total_paid_amount || 0) === 0 && (
                          <button
                            type="button"
                            onClick={() => openModifyModal(order)}
                            disabled={processingPayment}
                            className={`${actionButtonBaseClass} ${
                              processingPayment
                                ? actionButtonDisabledClass
                                : 'border border-[#16233b] bg-white text-[#16233b] hover:bg-slate-50'
                            }`}
                          >
                            MODIFY
                          </button>
                        )}
                      {order.status === 'repairer_accepted' && order.conversation_id && order.payment_status !== 'paid' && order.payment_status !== 'completed' && (
                        <>
                          {isOnlineIntakeFlow(order) && order.payment_enabled && (
                            <button
                              onClick={() => handlePayNow(order.id)}
                              disabled={!order.payment_enabled || processingPayment}
                              className={`${actionButtonBaseClass} ${
                                order.payment_enabled && !processingPayment
                                  ? actionButtonPrimaryClass
                                  : actionButtonDisabledClass
                              }`}
                            >
                              {processingPayment ? 'PROCESSING...' : (isWarrantyNoChargeOrder(order) ? 'Pay pickup shipping fee' : 'PAY NOW')}
                            </button>
                          )}
                          <button
                            onClick={() => {
                              setCancelTargetOrderId(order.id);
                              setSelectedReason('');
                              setCancelNote('');
                              setShowCancelModal(true);
                            }}
                            disabled={processingPayment}
                            className={`${actionButtonBaseClass} ${processingPayment ? actionButtonDisabledClass : actionButtonDangerClass}`}
                          >
                            CANCEL REQUEST
                          </button>
                        </>
                      )}
                      
                      {/* Walk-in Repairs - Just chat, no need to confirm */}
                      {order.status === 'received' && order.conversation_id && getIntakeMethod(order) === 'walk_in' && (
                        <>
                          <div className="inline-flex items-center gap-2 rounded-full border border-emerald-200 bg-emerald-50 px-6 py-2.5 text-xs font-semibold uppercase tracking-[0.16em] text-emerald-700">
                            <svg className="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                              <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                            </svg>
                            SHOES RECEIVED
                          </div>
                        </>
                      )}
                      
                      {order.status === 'pending' && order.payment_status !== 'paid' && order.payment_status !== 'completed' && (
                        <>
                          {isOnlineIntakeFlow(order) && order.payment_enabled && (
                            <button
                              onClick={() => handlePayNow(order.id)}
                              disabled={!order.payment_enabled || processingPayment}
                              className={`${actionButtonBaseClass} ${
                                order.payment_enabled && !processingPayment
                                  ? actionButtonPrimaryClass
                                  : actionButtonDisabledClass
                              }`}
                            >
                              {processingPayment ? 'PROCESSING...' : (isWarrantyNoChargeOrder(order) ? 'Pay pickup shipping fee' : 'PAY NOW')}
                            </button>
                          )}
                          <button
                            onClick={() => {
                              setCancelTargetOrderId(order.id);
                              setSelectedReason('');
                              setCancelNote('');
                              setShowCancelModal(true);
                            }}
                            disabled={processingPayment}
                            className={`${actionButtonBaseClass} ${processingPayment ? actionButtonDisabledClass : actionButtonDangerClass}`}
                          >
                            CANCEL REPAIR
                          </button>
                        </>
                      )}
                      {(order.status === 'ready_for_pickup' || order.status === 'shipped') && (
                        <>
                          {/* For deposit_50 only — full_upfront is already paid */}
                          {order.redelivery_payment_due && (
                            <button
                              onClick={() => handlePayNow(order.id)}
                              disabled={!order.payment_enabled || processingPayment}
                              className={`${actionButtonBaseClass} ${
                                order.payment_enabled && !processingPayment
                                  ? actionButtonPrimaryClass
                                  : actionButtonDisabledClass
                              }`}
                            >
                              {processingPayment ? 'PROCESSING...' : 'Pay new shipping fee'}
                            </button>
                          )}
                          {!order.redelivery_payment_due
                            && order.status === 'ready_for_pickup'
                            && getOrderOutstandingBalance(order) > 0 && (
                            <button
                              onClick={() => handlePayNow(order.id)}
                              disabled={!order.payment_enabled || processingPayment}
                              className={`${actionButtonBaseClass} ${
                                order.payment_enabled && !processingPayment
                                  ? actionButtonPrimaryClass
                                  : actionButtonDisabledClass
                              }`}
                            >
                              {processingPayment ? 'PROCESSING...' : (isWarrantyNoChargeOrder(order) ? 'Pay return shipping fee' : 'Pay Remaining Balance')}
                            </button>
                          )}
                          {(!order.return_recovery || order.return_recovery.state === 'shop_pickup') && (() => {
                            const canConfirmReceive = Boolean(
                              order.pickup_enabled && order.return_logistics_locked_at,
                            );
                            const receiveTitle = canConfirmReceive
                              ? 'Confirm only after your repaired shoes are in your hands'
                              : 'Waiting for the shop to record the return handoff';
                            const receiveLabel = canConfirmReceive
                              ? 'Confirm I received my repaired shoes'
                              : 'Awaiting handoff';

                            return (
                          <button
                            type="button"
                            onClick={() => confirmPickup(order.id)}
                            disabled={!canConfirmReceive}
                            className={`${actionButtonBaseClass} ${
                              canConfirmReceive
                                ? actionButtonPrimaryClass
                                : actionButtonDisabledClass
                            }`}
                            title={receiveTitle}
                          >
                            {receiveLabel}
                          </button>
                            );
                          })()}
                        </>
                      )}
                      {(order.status === 'picked_up' || order.status === 'received') && (
                        <button
                          onClick={() => handleFileWarrantyClaim(order)}
                          disabled={!canFileWarrantyClaim(order)}
                          title={canFileWarrantyClaim(order) ? 'File a no-charge warranty claim for this repair.' : getWarrantyClaimBlockReason(order)}
                          className={`${actionButtonBaseClass} ${canFileWarrantyClaim(order) ? actionButtonSecondaryClass : actionButtonDisabledClass}`}
                        >
                          WARRANTY CLAIM
                        </button>
                      )}
                      {order.status === 'picked_up' && (
                        <>
                          <button
                            onClick={() => {
                              const refundTargetRepairId = getRefundAnchorRepairId(order);
                              setRefundOrderId(refundTargetRepairId);
                              setRefundStep(1);
                              setRefundReason('');
                              setRefundOtherReason('');
                              setRefundMedia([]);
                              setRefundMethod('gcash');
                              setRefundAccountName('');
                              setRefundAccountRef('');
                              setRefundPayoutConsent(false);
                              setRefundNote('');
                              setShowRefundModal(true);
                            }}
                            disabled={hasActiveWarrantyClaim(order) || hasReviewForOrder(order) || isRefundFlowLocked(getLatestRefundForOrder(order)?.status) || isRefundInProgress(getLatestRefundForOrder(order)?.status)}
                            title={hasReviewForOrder(order)
                              ? 'Refund is not allowed after a review has been submitted.'
                              : hasActiveWarrantyClaim(order)
                                ? 'Refund is unavailable while a warranty claim is active.'
                                : isRefundFlowLocked(getLatestRefundForOrder(order)?.status)
                                  ? 'Refund is already filed or completed for this repair.'
                                  : undefined}
                            className={`${actionButtonBaseClass} ${(hasActiveWarrantyClaim(order) || hasReviewForOrder(order) || isRefundFlowLocked(getLatestRefundForOrder(order)?.status) || isRefundInProgress(getLatestRefundForOrder(order)?.status)) ? actionButtonDisabledClass : actionButtonSecondaryClass}`}
                          >
                            REFUND
                          </button>
                          <button
                            onClick={() => openReviewModal(order.id)}
                            disabled={hasReviewForOrder(order) || hasBlockingRefundForReview(order)}
                            title={hasReviewForOrder(order)
                              ? 'You have already submitted a review for this repair.'
                              : hasBlockingRefundForReview(order)
                                ? 'Review is unavailable once refund is requested or completed.'
                                : undefined}
                            className={`${actionButtonBaseClass} ${(hasReviewForOrder(order) || hasBlockingRefundForReview(order)) ? actionButtonDisabledClass : actionButtonPrimaryClass}`}
                          >
                            {hasReviewForOrder(order) ? 'REVIEWED' : 'REVIEW'}
                          </button>
                        </>
                      )}
                    </div>
                  </div>
                </div>
              ))}
            </div>
          )}
          </div>
        </div>
        {modifyOrder && (
          <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/45 p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="modify-repair-services-title"
          >
            <div className="max-h-[85vh] w-full max-w-xl overflow-y-auto rounded-2xl bg-white p-5 shadow-xl sm:p-7">
              <div className="flex items-start justify-between gap-4">
                <div>
                  <h2 id="modify-repair-services-title" className="text-xl font-semibold text-slate-950">
                    Modify Repair Services
                  </h2>
                  <p className="mt-1 text-sm text-slate-500">
                    Choose at least one active service from {modifyOrder.shop_name}.
                  </p>
                </div>
                <button
                  type="button"
                  onClick={closeModifyModal}
                  disabled={isSavingModifyServices}
                  aria-label="Close modify services"
                  className="text-2xl leading-none text-slate-500 hover:text-black disabled:cursor-not-allowed disabled:opacity-50"
                >
                  &times;
                </button>
              </div>

              {modifyOrder.repair_package_id && !packageRemovalConfirmed && (
                <div className="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-4">
                  <p className="text-sm text-amber-900">
                    This repair uses a package. Remove it to switch to individually priced services.
                  </p>
                  <button
                    type="button"
                    onClick={() => setPackageRemovalConfirmed(true)}
                    className="mt-3 text-sm font-semibold text-amber-950 underline"
                  >
                    Remove Package
                  </button>
                </div>
              )}

              <div className="mt-5 space-y-2">
                {isLoadingModifyServices ? (
                  <p className="py-8 text-center text-sm text-slate-500">Loading services...</p>
                ) : availableModifyServices.length === 0 ? (
                  <p className="py-8 text-center text-sm text-slate-500">No active services are available.</p>
                ) : availableModifyServices.map((service) => {
                  const checked = selectedModifyServiceIds.includes(service.id);

                  return (
                    <label
                      key={service.id}
                      className={`flex items-center justify-between gap-4 rounded-xl border p-4 ${
                        packageRemovalConfirmed ? 'cursor-pointer' : 'cursor-not-allowed opacity-60'
                      }`}
                    >
                      <span className="flex items-center gap-3">
                        <input
                          type="checkbox"
                          checked={checked}
                          disabled={!packageRemovalConfirmed}
                          onChange={() => toggleModifyService(service.id)}
                        />
                        <span>
                          <span className="block text-sm font-semibold text-slate-950">{service.name}</span>
                          {service.duration && (
                            <span className="block text-xs text-slate-500">{service.duration}</span>
                          )}
                        </span>
                      </span>
                      <span className="text-sm font-semibold text-slate-950">
                        {formatCurrency(Number(service.price || 0))}
                      </span>
                    </label>
                  );
                })}
              </div>

              <div className="mt-6 flex flex-col gap-4 border-t border-slate-200 pt-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                  <p className="text-xs uppercase tracking-wider text-slate-500">Updated total</p>
                  <p className="text-xl font-bold text-slate-950">{formatCurrency(selectedModifyTotal)}</p>
                </div>
                <div className="flex justify-end gap-2">
                  <button
                    type="button"
                    onClick={closeModifyModal}
                    disabled={isSavingModifyServices}
                    className={`${actionButtonBaseClass} border border-slate-300 bg-white text-slate-700`}
                  >
                    CANCEL
                  </button>
                  <button
                    type="button"
                    onClick={saveModifiedServices}
                    disabled={
                      !packageRemovalConfirmed
                      || selectedModifyServiceIds.length === 0
                      || isSavingModifyServices
                    }
                    className={`${actionButtonBaseClass} ${
                      !packageRemovalConfirmed
                      || selectedModifyServiceIds.length === 0
                      || isSavingModifyServices
                        ? actionButtonDisabledClass
                        : actionButtonPrimaryClass
                    }`}
                  >
                    {isSavingModifyServices ? 'SAVING...' : 'SAVE CHANGES'}
                  </button>
                </div>
              </div>
            </div>
          </div>
        )}
        {showCancelModal && (
          <div className="fixed inset-0 z-50 flex items-center justify-center">
            <div className="absolute inset-0 bg-black opacity-40" onClick={() => setShowCancelModal(false)}></div>
            <div className="bg-white rounded-lg shadow-xl z-50 max-w-lg w-full mx-4">
              <div className="px-6 py-4 border-b">
                <h3 className="text-lg font-semibold">Cancel Repair</h3>
                <p className="text-sm text-gray-500">Select a reason for cancelling this repair.</p>
              </div>
              <div className="px-6 py-4">
                <div className="space-y-3">
                  {[
                    'Need to reschedule',
                    'No longer needed',
                    'Found another repair shop',
                    'Price is too high',
                    'Need to change item details',
                    'Shop not responsive',
                    'Other',
                  ].map((r) => (
                    <label key={r} className="flex items-center gap-3">
                      <input
                        type="radio"
                        name="cancel_reason"
                        value={r === 'Other' ? 'other' : r}
                        checked={selectedReason === (r === 'Other' ? 'other' : r)}
                        onChange={(e) => setSelectedReason(e.target.value)}
                        className="form-radio h-4 w-4 text-black"
                      />
                      <span className="text-sm text-gray-700">{r}</span>
                    </label>
                  ))}

                  {selectedReason === 'other' && (
                    <div>
                      <label className="block text-sm text-gray-600 mb-2">Note (optional)</label>
                      <textarea
                        value={cancelNote}
                        onChange={(e) => setCancelNote(e.target.value)}
                        className="w-full border border-gray-200 rounded p-2 text-sm"
                        rows={3}
                        placeholder="Add a note about the reason..."
                      />
                    </div>
                  )}
                </div>
              </div>
              <div className="px-6 py-4 border-t flex justify-end gap-3">
                <button
                  onClick={() => {
                    setShowCancelModal(false);
                    setCancelTargetOrderId(null);
                    setSelectedReason('');
                    setCancelNote('');
                  }}
                  className={`${actionButtonBaseClass} ${actionButtonSecondaryClass}`}
                >
                  Close
                </button>
                <button
                  onClick={async () => {
                    if (!cancelTargetOrderId) return;
                    if (!selectedReason) {
                      Swal.fire({ icon: 'warning', title: 'Please select a reason', confirmButtonColor: '#000000' });
                      return;
                    }
                    await cancelRepair(cancelTargetOrderId, selectedReason);
                    setShowCancelModal(false);
                    setCancelTargetOrderId(null);
                    setSelectedReason('');
                    setCancelNote('');
                  }}
                  disabled={!selectedReason}
                  className={`${actionButtonBaseClass} ${selectedReason ? actionButtonDangerClass : actionButtonDisabledClass}`}
                >
                  Cancel Repair
                </button>
              </div>
            </div>
          </div>
        )}
        {showRefundModal && (
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div className="absolute inset-0 bg-black opacity-40" onClick={() => setShowRefundModal(false)}></div>
            <div className="bg-white rounded-lg shadow-xl z-50 max-w-5xl w-full max-h-[90vh] flex flex-col">
              <div className="px-8 py-4 border-b shrink-0">
                <h3 className="text-xl font-semibold">Request Refund {refundStep === 2 && '- Payment Details'}</h3>
                <p className="text-sm text-gray-500 mt-1">
                  {refundStep === 1 ? 'Please provide details for your refund request.' : 'Select your refund method and review details.'}
                </p>
              </div>
              <div className="px-8 py-6 overflow-y-auto flex-1">
                {refundStep === 1 ? (
                  <div className="space-y-6">
                    {/* Reason Selection */}
                    <div>
                      <label className="block text-sm font-medium text-gray-700 mb-3">
                        Reason for Refund <span className="text-red-500">*</span>
                      </label>
                      <div className="grid grid-cols-2 gap-x-6 gap-y-3">
                        {[
                          'Service not satisfactory',
                          'Repair quality issues',
                          'Damage during repair',
                          'Item not properly repaired',
                          'Unfair pricing',
                          'Changed my mind',
                          'Better service elsewhere',
                          'Other',
                        ].map((r) => (
                          <label key={r} className="flex items-center gap-3">
                            <input
                              type="radio"
                              name="refund_reason"
                              value={r}
                              checked={refundReason === r}
                              onChange={(e) => {
                                const nextReason = e.target.value;
                                setRefundReason(nextReason);
                                if (nextReason !== 'Other') {
                                  setRefundOtherReason('');
                                }
                              }}
                              className="form-radio h-4 w-4 text-black shrink-0"
                            />
                            <span className="text-sm text-gray-700">{r}</span>
                          </label>
                        ))}
                      </div>

                      {refundReason === 'Other' && (
                        <div className="mt-4">
                          <label className="block text-sm font-medium text-gray-700 mb-2">
                            Please specify <span className="text-red-500">*</span>
                          </label>
                          <textarea
                            value={refundOtherReason}
                            onChange={(e) => setRefundOtherReason(e.target.value)}
                            className="w-full border-2 border-gray-200 rounded-lg p-3 text-sm focus:border-gray-400 focus:outline-none resize-none"
                            rows={3}
                            placeholder="Please provide details for your refund reason..."
                          />
                        </div>
                      )}
                    </div>

                    {/* Media Upload (Photos & Videos) */}
                    <div>
                      <label className="block text-sm font-medium text-gray-700 mb-1">
                        Upload Photos/Videos <span className="text-red-500">*</span>
                        {refundMedia.length > 0 && (
                          <span className="ml-2 text-xs text-gray-500">
                            ({refundMedia.length}/10 files)
                          </span>
                        )}
                      </label>
                      <p className="text-xs text-gray-600 mb-3">
                        <strong>Note:</strong> Upload at least one clear photo or video. Supported files: JPG, JPEG, PNG, WEBP, MP4, MOV, AVI, MKV, WEBM. Images must be 20MB or smaller; video must be 256MB or smaller.
                      </p>
                      
                      <div className="grid grid-cols-6 gap-3">
                        {/* Display uploaded media */}
                        {refundMedia.map((file, index) => (
                          <div key={index} className="relative group aspect-square">
                            {isVideoFile(file) ? (
                              <div className="w-full h-full flex flex-col items-center justify-center bg-gray-100 rounded-lg border-2 border-gray-200">
                                <svg className="w-8 h-8 text-gray-600 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                </svg>
                                <p className="text-xs text-gray-500 px-1 text-center truncate w-full">{file.name.split('.')[0]}</p>
                              </div>
                            ) : (
                              <img
                                src={URL.createObjectURL(file)}
                                alt={`Preview ${index + 1}`}
                                className="w-full h-full object-cover rounded-lg border-2 border-gray-200"
                              />
                            )}
                            <button
                              onClick={() => removeMedia(index)}
                              className="absolute top-2 right-2 bg-black bg-opacity-60 text-white rounded-full w-7 h-7 flex items-center justify-center text-lg hover:bg-opacity-80 opacity-0 group-hover:opacity-100 transition-opacity"
                            >
                              ×
                            </button>
                          </div>
                        ))}
                        
                        {/* Add more button */}
                        {refundMedia.length < 10 && (
                          <div className="relative aspect-square">
                            <input
                              type="file"
                              accept={REFUND_MEDIA_ACCEPT}
                              multiple
                              onChange={handleMediaUpload}
                              className="hidden"
                              id="media-upload"
                            />
                            <label
                              htmlFor="media-upload"
                              className="flex flex-col items-center justify-center w-full h-full border-2 border-dashed border-gray-300 rounded-lg cursor-pointer hover:border-gray-400 hover:bg-gray-50 transition-all group"
                            >
                              <div className="flex items-center justify-center w-12 h-12 rounded-full bg-gray-100 group-hover:bg-gray-200 transition-colors">
                                <svg className="w-6 h-6 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                                </svg>
                              </div>
                              <p className="text-xs text-gray-500 mt-2">Add Media</p>
                            </label>
                          </div>
                        )}
                      </div>
                    </div>
                  </div>
                ) : (
                  <div className="space-y-6">
                    {/* Refund Amount Display */}
                    <div className="bg-white border border-gray-200 rounded-lg p-6">
                      <h4 className="text-base font-bold mb-4">Refund Summary</h4>
                      <div className="space-y-3">
                        <div className="flex justify-between items-center">
                          <span className="text-sm text-gray-700">Repair Total:</span>
                          <span className="text-sm text-gray-900">{formatCurrency(refundTotal)}</span>
                        </div>
                        <div className="flex justify-between items-center">
                          <span className="text-sm text-gray-700">Total Paid:</span>
                          <span className="text-sm text-gray-900">{formatCurrency(refundPaidAmount)}</span>
                        </div>
                        <div className="flex justify-between items-center">
                          <span className="text-sm font-bold text-gray-900">Refundable Balance:</span>
                          <span className="text-sm font-bold text-green-600">{formatCurrency(refundAvailableAmount)}</span>
                        </div>
                      </div>
                    </div>

                    {/* Refund Method Selection */}
                    {refundRequiresPayoutDestination ? (
                      <>
                        {refundPaymentType === 'mixed' && (
                          <div>
                            <label className="block text-sm font-medium text-gray-700 mb-3">
                              Online-paid Portion Refund Method
                            </label>
                            <div className="border border-green-300 rounded-lg p-6 bg-green-50">
                              <div className="flex items-center justify-between mb-4">
                                <h4 className="text-base font-semibold text-green-900">Automatic Refund to Original PayMongo Method</h4>
                                <div className="flex items-center gap-2">
                                  <img src="/images/payment-logo/visa.png" alt="Visa" className="h-6" />
                                  <img src="/images/payment-logo/MAYA.png" alt="Maya" className="h-6" />
                                  <img src="/images/payment-logo/GCASH.png" alt="GCash" className="h-6" />
                                </div>
                              </div>
                              <p className="text-sm text-gray-700">
                                The online-paid portion of this mixed refund will be returned to your original PayMongo payment method automatically after approval.
                              </p>
                            </div>
                          </div>
                        )}

                        <div>
                          <label className="block text-sm font-medium text-gray-700 mb-3">
                            {refundPaymentType === 'manual_only'
                              ? 'Walk-in POS Refund Destination'
                              : 'POS-paid Portion Refund Destination'} <span className="text-red-500">*</span>
                          </label>

                          <div className="border border-gray-300 rounded-lg p-6 bg-white">
                            <div className="flex items-center justify-between mb-4">
                              <h4 className="text-base font-normal">Secure Refund Processing</h4>
                              <div className="flex items-center gap-2">
                                <img src="/images/payment-logo/visa.png" alt="Visa" className="h-6" />
                                <img src="/images/payment-logo/MAYA.png" alt="Maya" className="h-6" />
                                <img src="/images/payment-logo/GCASH.png" alt="GCash" className="h-6" />
                              </div>
                            </div>
                            <p className="text-sm text-gray-700 text-center">
                              {refundPaymentType === 'manual_only'
                                ? 'This refund came from pure walk-in POS payment. PayMongo refund is not available. Provide your GCash destination details so Finance/Shop Owner can execute the refund manually.'
                                : 'This is a mixed payment refund. The online-paid portion will return to the original PayMongo payment method, while the POS-paid portion will be refunded to the destination you provide below.'}
                            </p>
                          </div>

                          <div className="grid grid-cols-2 gap-3 mt-4">
                            {refundMethodOptions.map((method) => (
                              <label
                                key={method.value}
                                className={`flex items-center gap-3 p-4 border-2 rounded-lg cursor-pointer transition-all ${
                                  refundMethod === method.value
                                    ? 'border-black bg-gray-50'
                                    : 'border-gray-200 hover:border-gray-400'
                                }`}
                              >
                                <input
                                  type="radio"
                                  name="refund_method"
                                  value={method.value}
                                  checked={refundMethod === method.value}
                                  onChange={(e) => setRefundMethod(e.target.value)}
                                  className="form-radio h-4 w-4 text-black shrink-0"
                                />
                                <span className="text-sm font-medium text-gray-900">{method.label}</span>
                              </label>
                            ))}
                          </div>
                        </div>

                        {showPayoutAccountFields && (
                          <>
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                              <div>
                                <label className="block text-sm font-medium text-gray-700 mb-2">Account Name</label>
                                <input
                                  type="text"
                                  value={refundAccountName}
                                  onChange={(e) => setRefundAccountName(e.target.value)}
                                  className="w-full border-2 border-gray-200 rounded-lg p-3 text-sm focus:border-gray-400 focus:outline-none"
                                  placeholder="Name on destination account"
                                />
                              </div>
                              <div>
                                <label className="block text-sm font-medium text-gray-700 mb-2">Account Number / Reference</label>
                                <input
                                  type="text"
                                  value={refundAccountRef}
                                  onChange={(e) => setRefundAccountRef(e.target.value)}
                                  className="w-full border-2 border-gray-200 rounded-lg p-3 text-sm focus:border-gray-400 focus:outline-none"
                                  placeholder="e.g. mobile number or account ref"
                                />
                              </div>
                            </div>

                            <label className="flex items-start gap-3 p-3 border border-gray-200 rounded-lg bg-gray-50">
                              <input
                                type="checkbox"
                                checked={refundPayoutConsent}
                                onChange={(e) => setRefundPayoutConsent(e.target.checked)}
                                className="mt-1 h-4 w-4"
                              />
                              <span className="text-sm text-gray-700">
                                I confirm that the payout destination details above are correct.
                              </span>
                            </label>
                          </>
                        )}
                      </>
                    ) : (
                      <div>
                        <label className="block text-sm font-medium text-gray-700 mb-3">
                          Refund Method
                        </label>

                        <div className="border border-green-300 rounded-lg p-6 bg-green-50">
                          <div className="flex items-center justify-between mb-4">
                            <h4 className="text-base font-semibold text-green-900">Secure Refund to Original Payment Method</h4>
                            <div className="flex items-center gap-2">
                              <img src="/images/payment-logo/visa.png" alt="Visa" className="h-6" />
                              <img src="/images/payment-logo/MAYA.png" alt="Maya" className="h-6" />
                              <img src="/images/payment-logo/GCASH.png" alt="GCash" className="h-6" />
                            </div>
                          </div>
                          <p className="text-sm text-gray-700">
                            {refundOriginalMethodOnly
                              ? 'Your refund will be processed securely to the same payment method you used for this repair. If you paid with GCash, Maya, or Credit Card, your refund will go back to that account within 2-4 business days after approval.'
                              : 'Refund will follow the original payment channel when applicable.'}
                          </p>
                        </div>
                      </div>
                    )}

                    {/* Additional Note */}
                    <div>
                      <label className="block text-sm font-medium text-gray-700 mb-3">
                        Additional Note (Optional)
                      </label>
                      <textarea
                        value={refundNote}
                        onChange={(e) => setRefundNote(e.target.value)}
                        className="w-full border-2 border-gray-200 rounded-lg p-3 text-sm focus:border-gray-400 focus:outline-none resize-none"
                        rows={4}
                        placeholder="Add any additional information about your refund request..."
                      />
                    </div>
                  </div>
                )}
              </div>
              <div className="px-8 py-4 border-t flex justify-between gap-3 shrink-0">
                <div>
                  {refundStep === 2 && !isSubmittingRefund && (
                    <button
                      onClick={() => setRefundStep(1)}
                      className={`${actionButtonBaseClass} ${actionButtonSecondaryClass}`}
                    >
                      Back
                    </button>
                  )}
                </div>
                <div className="flex gap-3">
                  {!isSubmittingRefund && (
                    <button
                      onClick={() => {
                        setShowRefundModal(false);
                        setRefundOrderId(null);
                        setRefundStep(1);
                        setRefundReason('');
                        setRefundOtherReason('');
                        setRefundMedia([]);
                        setRefundMethod('gcash');
                        setRefundAccountName('');
                        setRefundAccountRef('');
                        setRefundPayoutConsent(false);
                        setRefundNote('');
                      }}
                      className={`${actionButtonBaseClass} ${actionButtonSecondaryClass}`}
                    >
                      Close
                    </button>
                  )}
                  {refundStep === 1 ? (
                    <button
                      onClick={() => {
                        if (!refundReason) {
                          Swal.fire({ icon: 'warning', title: 'Please select a reason', confirmButtonColor: '#000000' });
                          return;
                        }
                        if (!isRefundReasonValid()) {
                          Swal.fire({ icon: 'warning', title: 'Please provide details for Other reason', confirmButtonColor: '#000000' });
                          return;
                        }
                        if (!isMediaRequirementMet()) {
                          Swal.fire({ 
                            icon: 'warning', 
                            title: 'Invalid Media Upload', 
                            text: 'Please upload at least one photo or video before continuing.',
                            confirmButtonColor: '#000000' 
                          });
                          return;
                        }
                        setRefundStep(2);
                      }}
                      disabled={!isRefundReasonValid() || !isMediaRequirementMet()}
                      className={`${actionButtonBaseClass} ${
                        isRefundReasonValid() && isMediaRequirementMet()
                          ? actionButtonPrimaryClass
                          : actionButtonDisabledClass
                      }`}
                    >
                      Next
                    </button>
                  ) : (
                    <button
                      onClick={handleSubmitRefund}
                      disabled={!isRefundReasonValid() || !isMediaRequirementMet() || isSubmittingRefund}
                      className={`${actionButtonBaseClass} ${
                        isRefundReasonValid() && isMediaRequirementMet() && !isSubmittingRefund
                          ? actionButtonPrimaryClass
                          : actionButtonDisabledClass
                      }`}
                    >
                      {isSubmittingRefund ? 'Submitting...' : 'Submit Refund Request'}
                    </button>
                  )}
                </div>
              </div>
            </div>
          </div>
        )}

        {showWarrantyModal && (
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div className="absolute inset-0 bg-black opacity-40" onClick={closeWarrantyModal}></div>
            <div className="bg-white rounded-lg shadow-xl z-50 max-w-5xl w-full max-h-[90vh] flex flex-col">
              <div className="px-8 py-4 border-b shrink-0">
                <h3 className="text-xl font-semibold">File Warranty Claim</h3>
                <p className="text-sm text-gray-500 mt-1">Please provide details for your warranty claim.</p>
              </div>

              <div className="px-8 py-6 overflow-y-auto flex-1">
                <div className="space-y-6">
                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-3">
                      Reason for Warranty Claim <span className="text-red-500">*</span>
                    </label>
                    <div className="grid grid-cols-2 gap-x-6 gap-y-3">
                      {[
                        { value: 'issue_returned', label: 'Issue returned after completion' },
                        { value: 'same_defect', label: 'Same defect not fully fixed' },
                        { value: 'poor_workmanship', label: 'Workmanship concern' },
                        { value: 'other', label: 'Other' },
                      ].map((option) => (
                        <label key={option.value} className="flex items-center gap-3">
                          <input
                            type="radio"
                            name="warranty_reason"
                            value={option.value}
                            checked={warrantyReasonCode === option.value}
                            onChange={(e) => setWarrantyReasonCode(e.target.value)}
                            className="form-radio h-4 w-4 text-black shrink-0"
                          />
                          <span className="text-sm text-gray-700">{option.label}</span>
                        </label>
                      ))}
                    </div>

                    <div className="mt-4">
                      <label className="block text-sm font-medium text-gray-700 mb-2">
                        Details {warrantyReasonCode === 'other' && <span className="text-red-500">*</span>}
                      </label>
                      <textarea
                        value={warrantyReasonDetails}
                        onChange={(e) => setWarrantyReasonDetails(e.target.value)}
                        className="w-full border-2 border-gray-200 rounded-lg p-3 text-sm focus:border-gray-400 focus:outline-none resize-none"
                        rows={3}
                        placeholder="Describe the issue you observed..."
                      />
                    </div>
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-3">
                      How Will You Send The Item To The Shop? <span className="text-red-500">*</span>
                    </label>
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
                      {[
                        { value: 'walk_in', label: 'Walk-in', hint: 'Bring the item to the shop yourself.' },
                        { value: 'customer_delivery', label: 'Third-party courier', hint: 'You arrange and pay the courier directly.' },
                        { value: 'shop_pickup', label: 'Shop rider pickup', hint: 'Coverage and the delivery fee are checked before approval.' },
                      ].map((method) => (
                        <label
                          key={method.value}
                          className={`flex items-start gap-3 p-4 border-2 rounded-lg transition-all ${
                            method.value !== 'walk_in' && !warrantyIntakeAddress
                              ? 'cursor-not-allowed border-gray-200 bg-gray-100 opacity-60'
                              :
                            warrantyIntakeMethod === method.value
                                ? 'cursor-pointer border-black bg-gray-50'
                                : 'cursor-pointer border-gray-200 hover:border-gray-400'
                          }`}
                        >
                          <input
                            type="radio"
                            name="warranty_intake_method"
                            value={method.value}
                            checked={warrantyIntakeMethod === method.value}
                            disabled={method.value !== 'walk_in' && !warrantyIntakeAddress}
                            onChange={(e) => setWarrantyIntakeMethod(e.target.value as WarrantyIntakeMethod)}
                            className="form-radio h-4 w-4 text-black shrink-0 mt-0.5"
                          />
                          <span>
                            <span className="block text-sm font-medium text-gray-900">{method.label}</span>
                            <span className="block text-xs text-gray-500 mt-1">{method.hint}</span>
                          </span>
                        </label>
                      ))}
                    </div>
                    {warrantyIntakeMethod !== 'walk_in' && warrantyIntakeAddress && (
                      <p className="mt-2 rounded-lg bg-blue-50 px-3 py-2 text-xs text-blue-800">
                        Pinned intake address: {formatRepairAddress(warrantyIntakeAddress)}
                      </p>
                    )}
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-3">
                      How Do You Want To Receive The Reworked Shoes? <span className="text-red-500">*</span>
                    </label>
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
                      {[
                        { value: 'walk_in', label: 'Pick Up At Shop', hint: 'Pick up your repaired item at the shop once ready.' },
                        { value: 'customer_pickup', label: 'Third-party courier', hint: 'You arrange and pay the courier directly.' },
                        { value: 'shop_delivery', label: 'Shop rider delivery', hint: 'Coverage and the delivery fee are checked before approval.' },
                      ].map((method) => (
                        <label
                          key={method.value}
                          className={`flex items-start gap-3 p-4 border-2 rounded-lg transition-all ${
                            method.value !== 'walk_in' && !warrantyReturnAddress
                              ? 'cursor-not-allowed border-gray-200 bg-gray-100 opacity-60'
                              :
                            warrantyReceiveMethod === method.value
                                ? 'cursor-pointer border-black bg-gray-50'
                                : 'cursor-pointer border-gray-200 hover:border-gray-400'
                          }`}
                        >
                          <input
                            type="radio"
                            name="warranty_receive_method"
                            value={method.value}
                            checked={warrantyReceiveMethod === method.value}
                            disabled={method.value !== 'walk_in' && !warrantyReturnAddress}
                            onChange={(e) => setWarrantyReceiveMethod(e.target.value as WarrantyReceiveMethod)}
                            className="form-radio h-4 w-4 text-black shrink-0 mt-0.5"
                          />
                          <span>
                            <span className="block text-sm font-medium text-gray-900">{method.label}</span>
                            <span className="block text-xs text-gray-500 mt-1">{method.hint}</span>
                          </span>
                        </label>
                      ))}
                    </div>
                    {warrantyReceiveMethod !== 'walk_in' && warrantyReturnAddress && (
                      <p className="mt-2 rounded-lg bg-blue-50 px-3 py-2 text-xs text-blue-800">
                        Pinned return address: {formatRepairAddress(warrantyReturnAddress)}
                      </p>
                    )}
                    {(!warrantyIntakeAddress || !warrantyReturnAddress) && (
                      <p className="mt-2 text-xs text-amber-700">
                        Delivery choices without a pinned address are unavailable. Walk-in remains available.
                      </p>
                    )}
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">
                      Evidence Images <span className="text-red-500">*</span>
                      {warrantyImages.length > 0 && (
                        <span className="ml-2 text-xs text-gray-500">
                          ({warrantyImages.length}/10 files)
                        </span>
                      )}
                    </label>
                    <p className="text-xs text-gray-600 mb-3">
                      <strong>Note:</strong> Upload 1 to 10 clear images (JPEG/PNG/WEBP, 20MB max each).
                    </p>

                    <div className="grid grid-cols-6 gap-3">
                      {warrantyImages.map((file, index) => (
                        <div key={`${file.name}-${index}`} className="relative group aspect-square">
                          <img
                            src={URL.createObjectURL(file)}
                            alt={`Warranty evidence ${index + 1}`}
                            className="w-full h-full object-cover rounded-lg border-2 border-gray-200"
                          />
                          <button
                            type="button"
                            onClick={() => removeWarrantyImage(index)}
                            className="absolute top-2 right-2 bg-black bg-opacity-60 text-white rounded-full w-7 h-7 flex items-center justify-center text-lg hover:bg-opacity-80 opacity-0 group-hover:opacity-100 transition-opacity"
                          >
                            ×
                          </button>
                        </div>
                      ))}

                      {warrantyImages.length < 10 && (
                        <div className="relative aspect-square">
                          <input
                            id="warranty-images-upload"
                            type="file"
                            accept="image/jpeg,image/jpg,image/png,image/webp"
                            multiple
                            onChange={handleWarrantyImageUpload}
                            className="hidden"
                          />
                          <label
                            htmlFor="warranty-images-upload"
                            className="flex flex-col items-center justify-center w-full h-full border-2 border-dashed border-gray-300 rounded-lg cursor-pointer hover:border-gray-400 hover:bg-gray-50 transition-all group"
                          >
                            <div className="flex items-center justify-center w-12 h-12 rounded-full bg-gray-100 group-hover:bg-gray-200 transition-colors">
                              <svg className="w-6 h-6 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                              </svg>
                            </div>
                            <p className="text-xs text-gray-500 mt-2">Add Images</p>
                          </label>
                        </div>
                      )}
                    </div>
                  </div>
                </div>
              </div>

              <div className="px-8 py-4 border-t flex justify-end gap-3 shrink-0">
                {!isSubmittingWarrantyClaim && (
                  <button
                    onClick={closeWarrantyModal}
                    className={`${actionButtonBaseClass} ${actionButtonSecondaryClass}`}
                  >
                    Close
                  </button>
                )}
                <button
                  onClick={handleSubmitWarrantyClaim}
                  disabled={!isWarrantyFormValid() || isSubmittingWarrantyClaim}
                  className={`${actionButtonBaseClass} ${
                    isWarrantyFormValid() && !isSubmittingWarrantyClaim
                      ? actionButtonPrimaryClass
                      : actionButtonDisabledClass
                  }`}
                >
                  {isSubmittingWarrantyClaim ? 'Submitting...' : 'Submit Claim'}
                </button>
              </div>
            </div>
          </div>
        )}

        {/* Phase 10D - Review Modal */}
        {showReviewModal && (
          <div className="fixed inset-0 z-50 flex items-center justify-center">
            <div className="absolute inset-0 bg-black opacity-40" onClick={() => setShowReviewModal(false)}></div>
            <div className="bg-white rounded-lg shadow-xl z-50 max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto">
              <div className="px-6 py-4 border-b sticky top-0 bg-white">
                <h3 className="text-lg font-semibold">Write a Review</h3>
                <p className="text-sm text-gray-600 mt-1">Share your experience with this repair service</p>
              </div>

              <div className="px-6 py-4 space-y-6">
                {/* Star Rating */}
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-2">
                    Rating <span className="text-red-500">*</span>
                  </label>
                  <div className="flex gap-2">
                    {[1, 2, 3, 4, 5].map((star) => (
                      <button
                        key={star}
                        type="button"
                        onClick={() => setReviewRating(star)}
                        onMouseEnter={() => setHoveredRating(star)}
                        onMouseLeave={() => setHoveredRating(0)}
                        aria-label={`Rate ${star} star${star > 1 ? 's' : ''}`}
                        title={`Rate ${star} star${star > 1 ? 's' : ''}`}
                        className="transition-transform hover:scale-110"
                      >
                        <svg
                          className={`w-10 h-10 ${
                            star <= (hoveredRating || reviewRating)
                              ? 'text-yellow-400 fill-yellow-400'
                              : 'text-gray-300'
                          }`}
                          fill="none"
                          stroke="currentColor"
                          viewBox="0 0 24 24"
                        >
                          <path
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            strokeWidth={2}
                            d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"
                          />
                        </svg>
                      </button>
                    ))}
                  </div>
                  {reviewRating > 0 && (
                    <p className="text-sm text-gray-600 mt-2">
                      {reviewRating === 5 && '⭐ Excellent!'}
                      {reviewRating === 4 && '👍 Very Good!'}
                      {reviewRating === 3 && '👌 Good'}
                      {reviewRating === 2 && '😕 Could be better'}
                      {reviewRating === 1 && '😞 Needs improvement'}
                    </p>
                  )}
                </div>

                {/* Review Text */}
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-2">
                    Your Review (Optional)
                  </label>
                  <textarea
                    value={reviewText}
                    onChange={(e) => setReviewText(e.target.value)}
                    placeholder="Tell us about your experience with the repair service..."
                    maxLength={1000}
                    rows={4}
                    className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-black resize-none"
                  />
                  <p className="text-xs text-gray-500 mt-1">
                    {reviewText.length}/1000 characters
                  </p>
                </div>

                {/* Image Upload */}
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-2">
                    Photos (Optional)
                  </label>
                  <p className="text-xs text-gray-500 mb-2">Upload up to 3 images of the completed repair</p>
                  
                  <div className="flex flex-wrap gap-3">
                    {reviewImages.map((file, index) => (
                      <div key={index} className="relative group">
                        <img
                          src={URL.createObjectURL(file)}
                          alt={`Review ${index + 1}`}
                          className="w-24 h-24 object-cover rounded-lg"
                        />
                        <button
                          type="button"
                          onClick={() => removeReviewImage(index)}
                          aria-label="Remove review image"
                          title="Remove review image"
                          className="absolute -top-2 -right-2 bg-red-500 text-white rounded-full p-1 opacity-0 group-hover:opacity-100 transition-opacity"
                        >
                          <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                          </svg>
                        </button>
                      </div>
                    ))}
                    
                    {reviewImages.length < 3 && (
                      <label className="w-24 h-24 border-2 border-dashed border-gray-300 rounded-lg flex items-center justify-center cursor-pointer hover:border-gray-400 transition-colors">
                        <input
                          type="file"
                          accept="image/*"
                          multiple
                          onChange={handleReviewImageUpload}
                          aria-label="Upload review images"
                          className="hidden"
                        />
                        <svg className="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                        </svg>
                      </label>
                    )}
                  </div>
                </div>
              </div>

              <div className="px-6 py-4 border-t flex justify-end gap-3 sticky bottom-0 bg-white">
                <button
                  onClick={() => setShowReviewModal(false)}
                  className={`${actionButtonBaseClass} ${actionButtonSecondaryClass}`}
                >
                  Cancel
                </button>
                <button
                  onClick={submitReview}
                  disabled={reviewRating === 0}
                  className={`${actionButtonBaseClass} ${
                    reviewRating > 0
                      ? actionButtonPrimaryClass
                      : actionButtonDisabledClass
                  }`}
                >
                  Submit Review
                </button>
              </div>
            </div>
          </div>
        )}
        {/* Schedule Modal */}
        {showScheduleModal && scheduleCalendarData && (
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div className="absolute inset-0 bg-black opacity-50" onClick={() => setShowScheduleModal(false)} />
            <div className="bg-white rounded-xl shadow-2xl z-50 w-full max-w-md">
              <div className="px-6 py-4 border-b flex items-center justify-between">
                <div>
                  <h3 className="text-lg font-bold text-black">Set Your Schedule</h3>
                  <p className="text-sm text-gray-500 mt-0.5">Pick a drop-off date after discussing with your repairer.</p>
                </div>
                <button
                  onClick={() => setShowScheduleModal(false)}
                  aria-label="Close schedule modal"
                  title="Close"
                  className="text-gray-400 hover:text-gray-600 transition-colors"
                >
                  <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </button>
              </div>
              <div className="p-6">
                {/* Month navigation */}
                <div className="flex items-center justify-between mb-4">
                  <p className="font-semibold text-black">{scheduleCalendarData.monthLabel}</p>
                  <div className="flex gap-2">
                    <button
                      type="button"
                      onClick={() => {
                        const [y, m] = scheduleVisibleMonthKey.split('-').map(Number);
                        setScheduleVisibleMonthKey(getMonthKey(new Date(y, m - 2, 1)));
                      }}
                      aria-label="Previous month"
                      title="Previous month"
                      className="p-2 rounded-lg border border-gray-300 hover:bg-gray-50 transition-colors"
                    >
                      <svg className="w-4 h-4 text-gray-700" viewBox="0 0 20 20" fill="currentColor">
                        <path fillRule="evenodd" d="M12.78 15.78a.75.75 0 01-1.06 0L6.47 10.53a.75.75 0 010-1.06l5.25-5.25a.75.75 0 111.06 1.06L8.06 10l4.72 4.72a.75.75 0 010 1.06z" clipRule="evenodd" />
                      </svg>
                    </button>
                    <button
                      type="button"
                      onClick={() => {
                        const [y, m] = scheduleVisibleMonthKey.split('-').map(Number);
                        setScheduleVisibleMonthKey(getMonthKey(new Date(y, m, 1)));
                      }}
                      aria-label="Next month"
                      title="Next month"
                      className="p-2 rounded-lg border border-gray-300 hover:bg-gray-50 transition-colors"
                    >
                      <svg className="w-4 h-4 text-gray-700" viewBox="0 0 20 20" fill="currentColor">
                        <path fillRule="evenodd" d="M7.22 4.22a.75.75 0 011.06 0l5.25 5.25a.75.75 0 010 1.06l-5.25 5.25a.75.75 0 11-1.06-1.06L11.94 10 7.22 5.28a.75.75 0 010-1.06z" clipRule="evenodd" />
                      </svg>
                    </button>
                  </div>
                </div>
                {/* Legend */}
                <div className="flex flex-wrap gap-4 text-xs text-gray-600 mb-3">
                  <span className="inline-flex items-center gap-2"><span className="size-2.5 rounded-full bg-gray-200" />Available</span>
                  <span className="inline-flex items-center gap-2"><span className="size-2.5 rounded-full bg-gray-700" />Shop Closed</span>
                  <span className="inline-flex items-center gap-2"><span className="size-2.5 rounded-full bg-blue-600" />Your selection</span>
                </div>
                {/* Day headers */}
                <div className="grid grid-cols-7 gap-1 text-center text-[11px] font-semibold uppercase tracking-wide text-gray-500 mb-1">
                  {['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].map(d => <span key={d}>{d}</span>)}
                </div>
                {/* Calendar grid */}
                <div className="grid grid-cols-7 gap-1">
                  {Array.from({ length: scheduleCalendarData.firstWeekday }).map((_, i) => <div key={`e-${i}`} className="h-9" />)}
                  {Array.from({ length: scheduleCalendarData.totalDays }).map((_, i) => {
                    const day = i + 1;
                    const dateKey = `${scheduleVisibleMonthKey}-${String(day).padStart(2, '0')}`;
                    const isPast = dateKey <= scheduleCalendarData.todayKey;
                    const isShopClosed = shopClosedDayNumbers.has(new Date(`${dateKey}T00:00:00`).getDay());
                    const isSelected = scheduleSelectedDate === dateKey;
                    return (
                      <button
                        key={dateKey}
                        type="button"
                        disabled={isPast || isShopClosed}
                        onClick={() => setScheduleSelectedDate(dateKey)}
                        className={`h-9 rounded-lg border text-sm font-medium transition-colors ${
                          isPast
                            ? 'bg-gray-100 border-gray-200 text-gray-400 cursor-not-allowed'
                            : isSelected
                            ? 'bg-blue-600 border-blue-600 text-white'
                            : isShopClosed
                            ? 'bg-gray-700 border-gray-700 text-white cursor-not-allowed'
                            : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50'
                        }`}
                        title={isPast ? 'Past date' : isShopClosed ? 'Shop is closed this day' : 'Select this date'}
                      >
                        {day}
                      </button>
                    );
                  })}
                </div>
                {scheduleSelectedDate && (
                  <p className="mt-3 text-sm text-blue-700 font-medium text-center">
                    Selected: {new Date(`${scheduleSelectedDate}T00:00:00`).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })}
                  </p>
                )}
              </div>
              <div className="px-6 py-4 border-t flex justify-end gap-3">
                <button
                  onClick={() => setShowScheduleModal(false)}
                  className={`${actionButtonBaseClass} ${actionButtonSecondaryClass}`}
                >
                  Cancel
                </button>
                <button
                  onClick={handleConfirmSchedule}
                  disabled={!scheduleSelectedDate || isSubmittingSchedule}
                  className={`${actionButtonBaseClass} ${
                    scheduleSelectedDate && !isSubmittingSchedule
                      ? actionButtonPrimaryClass
                      : actionButtonDisabledClass
                  }`}
                >
                  {isSubmittingSchedule ? 'Saving...' : 'Confirm Schedule'}
                </button>
              </div>
            </div>
          </div>
        )}
      </main>
    </div>
    </CustomerFooterReveal>
  );
};

export default MyRepairs;
