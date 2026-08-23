import React, { useState, useEffect, useRef } from 'react';
import { createPortal } from 'react-dom';
import { Head, Link, usePage, router } from '@inertiajs/react';
import Navigation from '../Shared/Navigation';
import Swal from '@/Pages/UserSide/Shared/UserModal';
import axios from 'axios';
import { navigateBackOr } from '../Shared/backNavigation';
import { openTermsPolicyModal } from '../../../utils/termsPolicyModal';
import {
  PHILIPPINE_LOCATIONS,
  getCityMunicipalityOptions,
  normalizeCityMunicipalitySelection,
  normalizeProvinceSelection,
} from '@/data/philippineLocations';
import CustomerAddressMapPicker from '@/components/address/CustomerAddressMapPicker';

interface CartItem {
  id: string;
  pid: number;
  shop_owner_id?: number;
  name: string;
  price: number;
  size?: string;
  color?: string;
  qty: number;
  image?: string;
}

interface CheckoutData {
  items: CartItem[];
  total_amount: number;
  shipping_fee?: number;
  vat_amount?: number;
  vat_rate?: number;
  grand_total?: number;
  customer_name: string;
  customer_email: string;
  customer_phone: string;
  shipping_address: string;
  address_id?: number | null;
  shipping_region?: string | null;
  shipping_province?: string | null;
  shipping_city?: string | null;
  shipping_barangay?: string | null;
  shipping_postal_code?: string | null;
  shipping_address_line?: string | null;
  payment_method: string;
}

interface UserAddress {
  id: number;
  name?: string;
  phone?: string;
  address_line?: string;
  region?: string;
  province?: string;
  city?: string;
  barangay?: string;
  postal_code?: string;
  full_address?: string;
  is_default?: boolean;
  latitude?: number | null;
  longitude?: number | null;
}

interface ShippingEstimateData {
  distance_km: number;
  base_fee: number;
  min_fee: number;
  max_fee: number;
  distance_label?: string;
  customer_notice?: string;
  pay_after_order_notice?: string;
  shop_owned?: {
    available: boolean;
    reason: 'address_needs_pin' | 'shop_needs_pin' | 'outside_coverage' | 'logistics_unavailable' | null;
    distance_km: number | null;
    coverage_radius_km: number | null;
  };
}

interface AppliedVoucherSummary {
  id: number;
  name: string;
  code?: string | null;
  target: 'items' | 'shipping';
  scope: 'shop_wide' | 'product_specific';
  discount_mode: 'percentage' | 'fixed';
  value: number;
}

type VoucherClaimStatus = 'claimed' | 'claimable' | 'redeemed' | 'unavailable';
type VoucherEligibility = 'eligible' | 'minimum_spend' | 'not_applicable' | 'shipping_unavailable' | 'shipping_fee_required' | 'unavailable';

interface AvailableVoucherOption {
  id: number;
  name: string;
  code?: string | null;
  target: 'items' | 'shipping';
  scope: 'shop_wide' | 'product_specific';
  discount_mode: 'percentage' | 'fixed';
  value: number;
  min_spend: number;
  claim_status: VoucherClaimStatus;
  eligibility: VoucherEligibility;
  eligibility_message: string;
  eligible_subtotal: number;
  remaining_spend: number;
  claim_product_id: number | null;
  can_claim: boolean;
}

interface PromoPreviewData {
  shop_owner_id: number;
  sale_adjusted_subtotal: number;
  voucher_discount: number;
  final_subtotal: number;
  net_subtotal: number;
  vat_amount: number;
  vat_rate: number;
  raw_shipping_fee: number;
  shipping_voucher_discount: number;
  discounted_shipping_fee: number;
  shipping_voucher_error?: string | null;
  applied_voucher?: AppliedVoucherSummary | null;
  available_vouchers?: AvailableVoucherOption[];
  voucher_code_suggestions?: AvailableVoucherOption[];
  voucher_error?: string | null;
}

type PaymentMethod = 'paymongo';

const toFiniteNumber = (value: unknown, fallback = 0): number => {
  if (typeof value === 'number') {
    return Number.isFinite(value) ? value : fallback;
  }

  if (typeof value === 'string') {
    const normalized = value.replace(/[^0-9.-]+/g, '');
    const parsed = Number.parseFloat(normalized);
    return Number.isFinite(parsed) ? parsed : fallback;
  }

  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : fallback;
};

const formatVoucherMoney = (value: number): string => `₱${toFiniteNumber(value).toLocaleString(undefined, {
  minimumFractionDigits: 0,
  maximumFractionDigits: 2,
})}`;

const formatVoucherBenefit = (voucher: AvailableVoucherOption): string => {
  const value = toFiniteNumber(voucher.value);
  const targetLabel = voucher.target === 'shipping' ? 'shipping' : 'items';

  if (voucher.discount_mode === 'percentage') {
    return `${value.toLocaleString(undefined, { maximumFractionDigits: 2 })}% off ${targetLabel}`;
  }

  return `${formatVoucherMoney(value)} off ${targetLabel}`;
};

const voucherClaimStatusLabel = (status: VoucherClaimStatus): string => {
  if (status === 'claimed') return 'Claimed';
  if (status === 'claimable') return 'Available to claim';
  if (status === 'redeemed') return 'Already used';
  return 'Unavailable';
};

const voucherClaimStatusClass = (status: VoucherClaimStatus): string => {
  if (status === 'claimed') return 'border-blue-200 bg-blue-50 text-blue-700';
  if (status === 'claimable') return 'border-gray-200 bg-gray-50 text-gray-700';
  if (status === 'redeemed') return 'border-gray-200 bg-gray-100 text-gray-500';
  return 'border-red-200 bg-red-50 text-red-700';
};

const voucherEligibilityClass = (eligibility: VoucherEligibility): string => {
  if (eligibility === 'eligible') return 'text-emerald-700';
  if (eligibility === 'minimum_spend') return 'text-amber-700';
  if (eligibility === 'shipping_unavailable' || eligibility === 'shipping_fee_required') return 'text-red-600';
  return 'text-gray-500';
};

const Payment: React.FC = () => {
  const { auth } = usePage().props as any;
  const user = auth?.user;
  const searchParams = new URLSearchParams(window.location.search);
  const repairIdParam = searchParams.get('repair_id');
  const repairTotalParam = searchParams.get('total');
  const repairTypeParam = searchParams.get('repair_type');
  const repairOrderNumberParam = searchParams.get('order_number');
  const premiumPlanParam = searchParams.get('plan');
  const premiumPlanPrices: Record<string, number> = {
    basic: 249,
    pro: 399,
    premium: 599,
  };
  const isRepairPayment = searchParams.get('source') === 'repair' && !!repairIdParam && !!repairTotalParam;
  const isPremiumPayment = searchParams.get('source') === 'premium' && !!premiumPlanParam;

  const [checkoutData, setCheckoutData] = useState<CheckoutData | null>(null);
  const [isProcessing, setIsProcessing] = useState(false);
  const [payError, setPayError] = useState<string | null>(null);
  const [selectedPaymentMethod, setSelectedPaymentMethod] = useState<PaymentMethod>('paymongo');

  // Local state for editable fields
  const [customerEmail, setCustomerEmail] = useState('');
  const [customerName, setCustomerName] = useState('');
  const [customerPhone, setCustomerPhone] = useState('');
  const [shippingAddressLine, setShippingAddressLine] = useState('');
  const [shippingBarangay, setShippingBarangay] = useState('');
  const [shippingPostalCode, setShippingPostalCode] = useState('');
  const [shippingLatitude, setShippingLatitude] = useState<number | null>(null);
  const [shippingLongitude, setShippingLongitude] = useState<number | null>(null);
  const [shippingCity, setShippingCity] = useState('');
  const [shippingRegion, setShippingRegion] = useState('');
  const cityMunicipalityOptions = getCityMunicipalityOptions(shippingRegion);
  const [setAsDefaultAddress, setSetAsDefaultAddress] = useState(false);
  const [userAddresses, setUserAddresses] = useState<UserAddress[]>([]);
  const [isAddressSheetOpen, setIsAddressSheetOpen] = useState(false);
  const [isAddressLoading, setIsAddressLoading] = useState(false);
  const [isAddressSaving, setIsAddressSaving] = useState(false);
  const [addressSaveStatus, setAddressSaveStatus] = useState<string | null>(null);
  const [addressSheetMode, setAddressSheetMode] = useState<'list' | 'form'>('list');
  const [editingAddressId, setEditingAddressId] = useState<number | null>(null);
  const addressMapRef = useRef<HTMLDivElement | null>(null);
  const [shippingEstimate, setShippingEstimate] = useState<ShippingEstimateData | null>(null);
  const [shopOwnedCoverage, setShopOwnedCoverage] = useState<NonNullable<ShippingEstimateData['shop_owned']> | null>(null);
  const [isShippingEstimateLoading, setIsShippingEstimateLoading] = useState(false);
  const [shippingEstimateReason, setShippingEstimateReason] = useState<string | null>(null);
  const shippingEstimateRequestRef = useRef(0);
  const [promoPreview, setPromoPreview] = useState<PromoPreviewData | null>(null);
  const [isPromoPreviewLoading, setIsPromoPreviewLoading] = useState(false);
  const [selectedVoucherCampaignId, setSelectedVoucherCampaignId] = useState<number | null>(null);
  const [voucherCodeInput, setVoucherCodeInput] = useState('');
  const [appliedVoucherCode, setAppliedVoucherCode] = useState('');
  const [isVoucherSelectionEnabled, setIsVoucherSelectionEnabled] = useState(true);
  const [isVoucherSuggestionOpen, setIsVoucherSuggestionOpen] = useState(false);
  const [claimingVoucherCampaignId, setClaimingVoucherCampaignId] = useState<number | null>(null);
  const [voucherClaimError, setVoucherClaimError] = useState<string | null>(null);
  const [voucherPreviewRefreshKey, setVoucherPreviewRefreshKey] = useState(0);
  const [hasVoucherInputInteraction, setHasVoucherInputInteraction] = useState(false);
  const voucherInputContainerRef = useRef<HTMLDivElement | null>(null);
  const desktopCityDropdownRef = useRef<HTMLDivElement | null>(null);
  const sheetCityDropdownRef = useRef<HTMLDivElement | null>(null);
  const desktopProvinceDropdownRef = useRef<HTMLDivElement | null>(null);
  const sheetProvinceDropdownRef = useRef<HTMLDivElement | null>(null);
  const [isDesktopCityDropdownOpen, setIsDesktopCityDropdownOpen] = useState(false);
  const [isSheetCityDropdownOpen, setIsSheetCityDropdownOpen] = useState(false);
  const [isDesktopProvinceDropdownOpen, setIsDesktopProvinceDropdownOpen] = useState(false);
  const [isSheetProvinceDropdownOpen, setIsSheetProvinceDropdownOpen] = useState(false);
  const [paymentRecovery, setPaymentRecovery] = useState<{
    scope: 'order' | 'repair';
    id: number;
    reason: 'expired' | 'failed' | 'invalid_state';
  } | null>(null);
  const [isRecoveryCreating, setIsRecoveryCreating] = useState(false);
  const [policyShopOwnerId, setPolicyShopOwnerId] = useState<number | null>(null);
  const [activePolicyVersionId, setActivePolicyVersionId] = useState<number | null>(null);
  const [activePolicySections, setActivePolicySections] = useState<Record<string, string>>({});
  const [isLoadingPolicy, setIsLoadingPolicy] = useState(false);
  const [policyAccepted, setPolicyAccepted] = useState(false);

  const normalizeCheckoutPayload = (rawData: any): CheckoutData | null => {
    if (!rawData || !Array.isArray(rawData.items) || rawData.items.length === 0) {
      return null;
    }

    const normalizedItems: CartItem[] = rawData.items
      .map((item: any) => ({
        id: String(item?.id ?? ''),
        pid: Math.trunc(toFiniteNumber(item?.pid, 0)),
        shop_owner_id: Number.isFinite(Number(item?.shop_owner_id ?? item?.shop_id))
          ? Math.trunc(toFiniteNumber(item?.shop_owner_id ?? item?.shop_id, 0))
          : undefined,
        name: String(item?.name ?? ''),
        price: Math.max(0, toFiniteNumber(item?.price, 0)),
        qty: Math.max(1, Math.trunc(toFiniteNumber(item?.qty, 1))),
        size: item?.size ? String(item.size) : undefined,
        color: item?.color ? String(item.color) : undefined,
        image: item?.image ? String(item.image) : undefined,
      }))
      .filter((item: CartItem) => item.id && item.pid > 0);

    if (normalizedItems.length === 0) {
      return null;
    }

    const recomputedTotalAmount = normalizedItems.reduce((sum: number, item: CartItem) => sum + (item.price * item.qty), 0);
    const parsedTotalAmount = Math.max(0, toFiniteNumber(rawData.total_amount, recomputedTotalAmount));

    return {
      items: normalizedItems,
      total_amount: parsedTotalAmount,
      shipping_fee: Math.max(0, toFiniteNumber(rawData.shipping_fee, 0)),
      vat_amount: rawData.vat_amount === undefined || rawData.vat_amount === null
        ? undefined
        : Math.max(0, toFiniteNumber(rawData.vat_amount, 0)),
      vat_rate: rawData.vat_rate === undefined || rawData.vat_rate === null
        ? undefined
        : Math.max(0, toFiniteNumber(rawData.vat_rate, 12)),
      grand_total: rawData.grand_total === undefined || rawData.grand_total === null
        ? undefined
        : Math.max(0, toFiniteNumber(rawData.grand_total, parsedTotalAmount)),
      customer_name: String(rawData.customer_name || user?.name || ''),
      customer_email: String(rawData.customer_email || user?.email || ''),
      customer_phone: String(rawData.customer_phone || user?.phone || ''),
      shipping_address: String(rawData.shipping_address || ''),
      address_id: rawData.address_id ?? null,
      shipping_region: rawData.shipping_region ?? null,
      shipping_province: rawData.shipping_province ?? null,
      shipping_city: rawData.shipping_city ?? null,
      shipping_barangay: rawData.shipping_barangay ?? null,
      shipping_postal_code: rawData.shipping_postal_code ?? null,
      shipping_address_line: rawData.shipping_address_line ?? null,
      payment_method: String(rawData.payment_method || 'paymongo'),
    };
  };

  const normalizeVoucherCode = (value: string) => value.trim().toUpperCase();

  const handleApplyVoucherCode = () => {
    const normalizedCode = normalizeVoucherCode(voucherCodeInput);
    if (!normalizedCode) {
      setAppliedVoucherCode('');
      setIsVoucherSuggestionOpen(false);
      return;
    }

    setIsVoucherSelectionEnabled(true);
    setSelectedVoucherCampaignId(null);
    setAppliedVoucherCode(normalizedCode);
    setHasVoucherInputInteraction(true);
    setIsVoucherSuggestionOpen(false);
  };

  const handleUseVoucher = (voucher: AvailableVoucherOption) => {
    const normalizedCode = normalizeVoucherCode(String(voucher.code || voucher.name || ''));
    if (!normalizedCode) {
      return;
    }

    setIsVoucherSelectionEnabled(true);
    setSelectedVoucherCampaignId(voucher.id);
    setHasVoucherInputInteraction(true);
    setVoucherCodeInput(normalizedCode);
    setAppliedVoucherCode(normalizedCode);
    setVoucherClaimError(null);
    setIsVoucherSuggestionOpen(false);
  };

  const handleClaimVoucher = async (voucher: AvailableVoucherOption, applyAfterClaim: boolean) => {
    if (!voucher.can_claim || voucher.claim_product_id === null || claimingVoucherCampaignId !== null) {
      return;
    }

    setClaimingVoucherCampaignId(voucher.id);
    setVoucherClaimError(null);

    try {
      const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
      const response = await fetch(`/api/products/${voucher.claim_product_id}/vouchers/${voucher.id}/claim`, {
        method: 'POST',
        credentials: 'include',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken,
          'X-Requested-With': 'XMLHttpRequest',
        },
      });
      const payload = await response.json().catch(() => ({})) as { success?: boolean; message?: string };

      if (!response.ok || payload.success === false) {
        throw new Error(payload.message || 'Unable to claim this voucher right now.');
      }

      if (applyAfterClaim) {
        handleUseVoucher(voucher);
      } else {
        setIsVoucherSuggestionOpen(true);
      }

      setVoucherPreviewRefreshKey((current) => current + 1);
    } catch (error) {
      setVoucherClaimError(error instanceof Error ? error.message : 'Unable to claim this voucher right now.');
      setIsVoucherSuggestionOpen(true);
    } finally {
      setClaimingVoucherCampaignId(null);
    }
  };

  const handleClearVoucherSelection = () => {
    setIsVoucherSelectionEnabled(false);
    setSelectedVoucherCampaignId(null);
    setAppliedVoucherCode('');
    setVoucherCodeInput('');
    setHasVoucherInputInteraction(true);
    setIsVoucherSuggestionOpen(false);
  };

  const handleProvinceChange = (province: string) => {
    setShippingRegion(normalizeProvinceSelection(province));
    setShippingCity('');
    setShippingEstimate(null);
    setShippingEstimateReason(null);
    setIsShippingEstimateLoading(false);
    setIsDesktopProvinceDropdownOpen(false);
    setIsSheetProvinceDropdownOpen(false);
    setIsDesktopCityDropdownOpen(false);
    setIsSheetCityDropdownOpen(false);
  };

  const handleDropdownTriggerKeyDown = (
    event: React.KeyboardEvent<HTMLButtonElement>,
    setOpen: React.Dispatch<React.SetStateAction<boolean>>,
    containerRef: React.RefObject<HTMLDivElement | null>,
  ) => {
    if (event.key === 'Escape') {
      setOpen(false);
      return;
    }
    if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return;

    event.preventDefault();
    setOpen(true);
    requestAnimationFrame(() => {
      const options = containerRef.current?.querySelectorAll<HTMLElement>('[role="option"]');
      options?.[event.key === 'ArrowUp' ? options.length - 1 : 0]?.focus();
    });
  };

  const handleListboxKeyDown = (
    event: React.KeyboardEvent<HTMLDivElement>,
    setOpen: React.Dispatch<React.SetStateAction<boolean>>,
    containerRef: React.RefObject<HTMLDivElement | null>,
  ) => {
    if (event.key === 'Escape') {
      event.preventDefault();
      setOpen(false);
      containerRef.current?.querySelector<HTMLButtonElement>('button')?.focus();
      return;
    }
    if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return;

    event.preventDefault();
    const options = Array.from(event.currentTarget.querySelectorAll<HTMLElement>('[role="option"]'));
    const currentIndex = options.indexOf(document.activeElement as HTMLElement);
    const offset = event.key === 'ArrowDown' ? 1 : -1;
    options[(currentIndex + offset + options.length) % options.length]?.focus();
  };

  const handleCityChange = (city: string) => {
    const selectedCity = normalizeCityMunicipalitySelection(shippingRegion, city);
    setShippingCity(selectedCity);
    setShippingEstimate(null);
    setShippingEstimateReason(null);
    setIsShippingEstimateLoading(Boolean(selectedCity));
    setIsDesktopCityDropdownOpen(false);
    setIsSheetCityDropdownOpen(false);
  };

  const handleAddressMapChange = (location: {
    displayName: string;
    region: string;
    province: string;
    city: string;
    barangay: string;
    postalCode: string;
    latitude: number;
    longitude: number;
  }) => {
    setShippingLatitude(location.latitude);
    setShippingLongitude(location.longitude);
    setShippingRegion(location.province || location.region);
    setShippingCity(location.city);
    setShippingBarangay(location.barangay);
    setShippingPostalCode(location.postalCode);
  };

  const formatAddressDisplay = (addr?: Partial<UserAddress> | null) => {
    if (!addr) return '';
    if (addr.full_address) return addr.full_address;
    return [addr.address_line, addr.barangay, addr.city, addr.province || addr.region, addr.postal_code].filter(Boolean).join(', ');
  };

  const applySelectedAddress = (addr: UserAddress) => {
    setSetAsDefaultAddress(Boolean(addr.is_default));
    setCustomerName(addr.name || '');
    setCustomerPhone(addr.phone || '');
    setShippingAddressLine(addr.address_line || '');
    const selectedProvince = normalizeProvinceSelection(addr.province || addr.region);
    const selectedCity = normalizeCityMunicipalitySelection(selectedProvince, addr.city);
    setShippingRegion(selectedProvince || addr.province || addr.region || '');
    setShippingCity(selectedCity || addr.city || '');
    setShippingBarangay(addr.barangay || '');
    setShippingPostalCode(addr.postal_code || '');
    setShippingLatitude(addr.latitude ?? null);
    setShippingLongitude(addr.longitude ?? null);
    setCheckoutData((prev) => prev
      ? {
          ...prev,
          address_id: addr.id,
          shipping_region: selectedProvince || addr.province || addr.region || null,
          shipping_province: selectedProvince || addr.province || addr.region || null,
          shipping_city: selectedCity || addr.city || null,
          shipping_barangay: addr.barangay || null,
          shipping_postal_code: addr.postal_code || null,
          shipping_address_line: addr.address_line || null,
        }
      : prev);
  };

  const restoreSelectedAddress = () => {
    const selectedAddress = userAddresses.find((address) => address.id === checkoutData?.address_id);
    if (selectedAddress) applySelectedAddress(selectedAddress);
  };

  const openAddressSheet = async () => {
    setAddressSheetMode('list');
    setEditingAddressId(null);
    setAddressSaveStatus(null);

    if (!user) {
      setIsAddressSheetOpen(true);
      return;
    }

    setIsAddressSheetOpen(true);
    setIsAddressLoading(true);
    try {
      const response = await fetch('/api/user/addresses', {
        method: 'GET',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
        },
        credentials: 'include',
      });

      if (response.ok) {
        const data = await response.json();
        const addresses: UserAddress[] = data.addresses || [];
        setUserAddresses(addresses);
      }
    } catch (error) {
      console.warn('Failed to load addresses for selector:', error);
    } finally {
      setIsAddressLoading(false);
    }
  };

  const handleUseAddressFromForm = async () => {
    const normalizedShippingCity = normalizeCityMunicipalitySelection(shippingRegion, shippingCity);
    if (!/^\d{11}$/.test(customerPhone)) {
      Swal.fire({
        icon: 'warning',
        title: 'Invalid phone number',
        text: 'Phone number must contain exactly 11 digits.',
        confirmButtonColor: '#000000',
      });
      return;
    }

    if (!customerName || !customerPhone || !shippingAddressLine || !shippingBarangay || !normalizedShippingCity || !shippingRegion || !shippingPostalCode) {
      Swal.fire({
        icon: 'warning',
        title: 'Missing fields',
        text: shippingCity && !normalizedShippingCity
          ? 'Please reselect a city or municipality for the selected province.'
          : 'Please fill all required address fields.',
        confirmButtonColor: '#000000',
      });
      return;
    }

    if (!user) {
      setIsAddressSheetOpen(false);
      setAddressSheetMode('list');
      return;
    }

    setIsAddressSaving(true);
    setAddressSaveStatus(null);
    try {
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
      const isEditingAddress = editingAddressId !== null;
      const targetUrl = isEditingAddress ? `/api/user/addresses/${editingAddressId}` : '/api/user/addresses';
      const response = await fetch(targetUrl, {
        method: isEditingAddress ? 'PUT' : 'POST',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken,
          'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'include',
        body: JSON.stringify({
          name: customerName,
          phone: customerPhone,
          address_line: shippingAddressLine,
          region: shippingRegion,
          province: shippingRegion,
          city: normalizedShippingCity,
          barangay: shippingBarangay,
          postal_code: shippingPostalCode,
          latitude: shippingLatitude,
          longitude: shippingLongitude,
          is_default: setAsDefaultAddress,
        }),
      });

      if (!response.ok) {
        throw new Error('Failed to save address');
      }

      const data = await response.json();
      const createdAddress: UserAddress | undefined = data.address;
      if (createdAddress) {
        setUserAddresses((prev) => {
          if (isEditingAddress) {
            return prev.map((addr) => (addr.id === createdAddress.id ? createdAddress : addr));
          }
          return [createdAddress, ...prev];
        });
        applySelectedAddress(createdAddress);
      }

      setEditingAddressId(null);
      setAddressSheetMode('list');
      setAddressSaveStatus('Address saved. You can select it from the list.');
    } catch (error) {
      setAddressSaveStatus('We could not save this address. Please try again.');
    } finally {
      setIsAddressSaving(false);
    }
  };

  const handleEditAddressFromList = (addr: UserAddress, focusMap = false) => {
    setIsAddressSheetOpen(true);
    setAddressSaveStatus(null);
    setEditingAddressId(addr.id);
    setSetAsDefaultAddress(Boolean(addr.is_default));
    setCustomerName(addr.name || '');
    setCustomerPhone(addr.phone || '');
    setShippingAddressLine(addr.address_line || '');
    const selectedProvince = normalizeProvinceSelection(addr.province || addr.region);
    const selectedCity = normalizeCityMunicipalitySelection(selectedProvince, addr.city);
    setShippingRegion(selectedProvince || addr.province || addr.region || '');
    setShippingCity(selectedCity || addr.city || '');
    setShippingBarangay(addr.barangay || '');
    setShippingPostalCode(addr.postal_code || '');
    setShippingLatitude(addr.latitude ?? null);
    setShippingLongitude(addr.longitude ?? null);
    setAddressSheetMode('form');
    if (focusMap) {
      requestAnimationFrame(() => {
        addressMapRef.current?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        addressMapRef.current?.querySelector<HTMLInputElement>('input')?.focus();
      });
    }
  };

  const handleDeleteAddressFromForm = async () => {
    if (!user || editingAddressId === null) return;

    const confirm = await Swal.fire({
      icon: 'warning',
      title: 'Delete this address?',
      text: 'This action cannot be undone.',
      showCancelButton: true,
      confirmButtonText: 'Delete',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#dc2626',
      cancelButtonColor: '#111827',
    });

    if (!confirm.isConfirmed) return;

    try {
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
      const response = await fetch(`/api/user/addresses/${editingAddressId}`, {
        method: 'DELETE',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken,
          'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'include',
      });

      if (!response.ok) {
        throw new Error('Failed to delete address');
      }

      const deletedId = editingAddressId;
      const nextAddresses = userAddresses.filter((addr) => addr.id !== deletedId);
      setUserAddresses(nextAddresses);

      if (checkoutData?.address_id === deletedId) {
        if (nextAddresses.length > 0) {
          const nextDefault = nextAddresses.find((addr) => addr.is_default) || nextAddresses[0];
          applySelectedAddress(nextDefault);
        } else {
          setCheckoutData((prev) => (prev ? { ...prev, address_id: null } : prev));
          setShippingAddressLine('');
          setShippingRegion('');
          setShippingCity('');
          setShippingBarangay('');
          setShippingPostalCode('');
        }
      }

      setEditingAddressId(null);
      setAddressSheetMode('list');
    } catch (error) {
      console.warn('Failed to delete address:', error);
      Swal.fire({
        icon: 'error',
        title: 'Delete failed',
        text: 'Unable to delete this address right now. Please try again.',
        confirmButtonColor: '#000000',
      });
    }
  };

  useEffect(() => {
    const loadCheckoutData = async () => {
      if (isRepairPayment) {
        const totalAmount = Number.parseFloat(repairTotalParam || '0') || 0;
        const repairName = repairTypeParam || (repairOrderNumberParam ? `Repair ${repairOrderNumberParam}` : 'Repair Service');
        const data: CheckoutData = {
          items: [
            {
              id: `repair-${repairIdParam}`,
              pid: Number(repairIdParam),
              name: repairName,
              price: totalAmount,
              qty: 1,
            },
          ],
          total_amount: totalAmount,
          customer_name: user?.name || '',
          customer_email: user?.email || '',
          customer_phone: user?.phone || '',
          shipping_address: '',
          address_id: null,
          shipping_region: null,
          shipping_province: null,
          shipping_city: null,
          shipping_barangay: null,
          shipping_postal_code: null,
          shipping_address_line: null,
          payment_method: 'paymongo',
        };

        setCheckoutData(data);
        setSelectedPaymentMethod('paymongo');
        setCustomerName(data.customer_name);
        setCustomerEmail(data.customer_email);
        setCustomerPhone(data.customer_phone);
        return;
      }

      if (isPremiumPayment) {
        const normalizedPlan = (premiumPlanParam || '').toLowerCase();
        const totalAmount = premiumPlanPrices[normalizedPlan] ?? 0;
        const premiumPlanName =
          normalizedPlan === 'basic'
            ? 'Premium Plan - Basic'
            : normalizedPlan === 'pro'
              ? 'Premium Plan - Pro'
              : 'Premium Plan - Premium';

        const data: CheckoutData = {
          items: [
            {
              id: `premium-${normalizedPlan || 'plan'}`,
              pid: 0,
              name: premiumPlanName,
              price: totalAmount,
              qty: 1,
            },
          ],
          total_amount: totalAmount,
          customer_name: user?.name || '',
          customer_email: user?.email || '',
          customer_phone: user?.phone || '',
          shipping_address: '',
          address_id: null,
          shipping_region: null,
          shipping_province: null,
          shipping_city: null,
          shipping_barangay: null,
          shipping_postal_code: null,
          shipping_address_line: null,
          payment_method: 'paymongo',
        };

        setCheckoutData(data);
        setSelectedPaymentMethod('paymongo');
        setCustomerName(data.customer_name);
        setCustomerEmail(data.customer_email);
        setCustomerPhone(data.customer_phone);
        return;
      }

      // First, try to get data from sessionStorage
      const stored = sessionStorage.getItem('checkoutData');
      if (stored) {
        try {
          const parsed = JSON.parse(stored);
          const data = normalizeCheckoutPayload(parsed);
          if (!data) {
            throw new Error('Invalid checkout payload');
          }
          setCheckoutData(data);
          setSelectedPaymentMethod('paymongo');
          // Sync local state with loaded data
          setCustomerEmail(data.customer_email || '');
          setCustomerName(data.customer_name || '');
          setCustomerPhone(data.customer_phone || '');
          setShippingAddressLine(data.shipping_address_line || '');
          setShippingBarangay(data.shipping_barangay || '');
          setShippingPostalCode(data.shipping_postal_code || '');
          const selectedProvince = normalizeProvinceSelection(data.shipping_province || data.shipping_region);
          const selectedCity = normalizeCityMunicipalitySelection(selectedProvince, data.shipping_city);
          setShippingRegion(selectedProvince || data.shipping_province || data.shipping_region || '');
          setShippingCity(selectedCity || data.shipping_city || '');
          return;
        } catch (e) {
          console.error('Failed to parse checkout data:', e);
        }
      }

      // If no sessionStorage data, load from cart API
      try {
        let cartItems: CartItem[] = [];
        let totalAmount = 0;

        if (user) {
          // Load from database cart
          const response = await axios.get('/api/cart');
          if (response.data.items && response.data.items.length > 0) {
            cartItems = response.data.items.map((item: any) => {
              const options = item.options ? (typeof item.options === 'string' ? JSON.parse(item.options) : item.options) : {};
              return {
                id: String(item.id),
                pid: Math.trunc(toFiniteNumber(item.pid ?? item.product_id, 0)),
                shop_owner_id: Math.trunc(toFiniteNumber(item.shop_owner_id ?? item.shop_id, 0)) || undefined,
                name: item.name || '',
                price: Math.max(0, toFiniteNumber(item.price, 0)),
                size: item.size,
                color: options.color || undefined,
                qty: Math.max(1, Math.trunc(toFiniteNumber(item.quantity ?? item.qty, 1))),
                image: item.image,
              };
            });
            totalAmount = cartItems.reduce((sum, item) => sum + item.price * item.qty, 0);
          }
        } else {
          // Load from localStorage for guests
          const raw = localStorage.getItem('ss_cart');
          if (raw) {
            const cart = JSON.parse(raw);
            cartItems = (cart || []).map((c: any) => {
              const price = (typeof c.price === 'number') ? c.price : (parseFloat(String(c.price).replace(/[^0-9.-]+/g, '')) || 0);
              return {
                id: String(c.id),
                pid: Math.trunc(toFiniteNumber(c.pid ?? c.product_id ?? c.id, 0)),
                shop_owner_id: Math.trunc(toFiniteNumber(c.shop_owner_id ?? c.shop_id, 0)) || undefined,
                name: c.name || '',
                price,
                size: c.size || undefined,
                color: c.color || undefined,
                qty: Math.max(1, Math.trunc(toFiniteNumber(c.qty, 1))),
                image: c.image || undefined,
              };
            });
            totalAmount = cartItems.reduce((sum, item) => sum + item.price * item.qty, 0);
          }
        }

        if (cartItems.length === 0) {
          router.visit('/checkout');
          return;
        }

        const data: CheckoutData = {
          items: cartItems,
          total_amount: totalAmount,
          customer_name: user?.name || '',
          customer_email: user?.email || '',
          customer_phone: user?.phone || '',
          shipping_address: '',
          address_id: null,
          shipping_region: null,
          shipping_province: null,
          shipping_city: null,
          shipping_barangay: null,
          shipping_postal_code: null,
          shipping_address_line: null,
          payment_method: 'paymongo',
        };

        setCheckoutData(data);
        setSelectedPaymentMethod('paymongo');
        setCustomerName(data.customer_name);
        setCustomerEmail(data.customer_email);
        setCustomerPhone(data.customer_phone);
      } catch (err) {
        console.error('Failed to load checkout data:', err);
        router.visit('/checkout');
      }
    };

    loadCheckoutData();
  }, []);

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const isFailed = params.get('paymongo_failed') === '1';
    const isExpired = params.get('paymongo_expired') === '1' || params.get('expired') === '1';
    const hasInvalidStateSignal = [
      params.get('paymongo_error') || '',
      params.get('error_code') || '',
      params.get('error') || '',
      params.get('state') || '',
      document.referrer || '',
      window.location.href || '',
    ].some((value) => /resource_invalid_state|invalid_state|grab_pay\/webhook/i.test(String(value)));

    if (!isFailed && !isExpired) {
      return;
    }

    const pendingOrderId = Number(sessionStorage.getItem('pendingOrderId') || '0');
    const pendingRepairId = Number(sessionStorage.getItem('pendingRepairId') || repairIdParam || '0');

    const recoveryReason: 'expired' | 'failed' | 'invalid_state' = isExpired
      ? 'expired'
      : (hasInvalidStateSignal ? 'invalid_state' : 'failed');

    if (isRepairPayment && Number.isFinite(pendingRepairId) && pendingRepairId > 0) {
      setPaymentRecovery({
        scope: 'repair',
        id: pendingRepairId,
        reason: recoveryReason,
      });
    } else if (Number.isFinite(pendingOrderId) && pendingOrderId > 0) {
      setPaymentRecovery({
        scope: 'order',
        id: pendingOrderId,
        reason: recoveryReason,
      });
    }

    params.delete('paymongo_failed');
    params.delete('paymongo_expired');
    params.delete('expired');
    params.delete('paymongo_error');
    params.delete('error_code');
    params.delete('error');
    params.delete('state');
    const queryString = params.toString();
    window.history.replaceState({}, '', `${window.location.pathname}${queryString ? `?${queryString}` : ''}`);
  }, [isRepairPayment, repairIdParam]);

  useEffect(() => {
    if (isRepairPayment || isPremiumPayment) {
      return;
    }

    const pendingOrderId = Number(sessionStorage.getItem('pendingOrderId') || '0');
    if (!Number.isFinite(pendingOrderId) || pendingOrderId <= 0) {
      return;
    }

    const params = new URLSearchParams(window.location.search);
    const hasExplicitPaymongoResult =
      params.get('paymongo_success') === '1'
      || params.get('paymongo_failed') === '1'
      || params.get('paymongo_expired') === '1'
      || params.get('expired') === '1';

    if (hasExplicitPaymongoResult) {
      return;
    }

    const referrer = (document.referrer || '').toLowerCase();
    const cameFromPaymongo = referrer.includes('paymongo');
    if (!cameFromPaymongo) {
      return;
    }

    let isCancelled = false;

    const releaseUnpaidOrder = async () => {
      try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        const verifyRes = await fetch(`/api/orders/${pendingOrderId}/verify-payment`, {
          method: 'POST',
          credentials: 'include',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
          },
        });

        const verifyData = await verifyRes.json().catch(() => ({}));
        if (verifyData?.success && verifyData?.payment_verified) {
          return;
        }

        await fetch('/orders/cancel', {
          method: 'POST',
          credentials: 'include',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
          },
          body: JSON.stringify({
            order_id: pendingOrderId,
            reason: 'payment_not_completed',
            note: 'Auto-cancelled after returning from PayMongo without confirmed payment.',
          }),
        });

        if (!isCancelled) {
          sessionStorage.removeItem('pendingOrderId');
          setPaymentRecovery(null);

          await Swal.fire({
            icon: 'info',
            title: 'Payment Not Completed',
            text: 'Your previous unpaid order was cancelled automatically. You can place a new order when ready.',
            confirmButtonColor: '#000000',
          });
        }
      } catch (error) {
        console.warn('Failed to auto-release unpaid order after PayMongo back navigation:', error);
      }
    };

    releaseUnpaidOrder();

    return () => {
      isCancelled = true;
    };
  }, [isPremiumPayment, isRepairPayment]);

  // Load saved user address and pre-fill form
  useEffect(() => {
    const loadSavedAddress = async () => {
      try {
        const response = await fetch('/api/user/addresses', {
          method: 'GET',
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
          },
          credentials: 'include',
        });

        if (response.ok) {
          const data = await response.json();
          const addresses: UserAddress[] = data.addresses || [];
          setUserAddresses(addresses);
          if (addresses.length > 0) {
            // Find default address or use first one
            const defaultAddress = addresses.find((addr: UserAddress) => addr.is_default) || addresses[0];
            
            if (defaultAddress) {
              applySelectedAddress(defaultAddress);
            }
          }
        }
      } catch (error) {
        console.warn('Failed to load saved addresses:', error);
      }
    };

    if (user) {
      // Load saved address after a short delay to ensure user is authenticated
      const timer = setTimeout(loadSavedAddress, 500);
      return () => clearTimeout(timer);
    }
  }, [user]);

  const requestShippingEstimate = async (
    addressId: number | null | undefined,
    signal?: AbortSignal,
  ): Promise<ShippingEstimateData> => {
    if (!checkoutData) throw new Error('Checkout data is unavailable.');

    const city = normalizeCityMunicipalitySelection(shippingRegion, shippingCity);
    const region = shippingRegion.trim();
    const itemPids = checkoutData.items
      .map((item) => Number(item.pid))
      .filter((pid) => Number.isInteger(pid) && pid > 0);
    if (!city || !region || itemPids.length === 0) {
      throw new Error('Unable to calculate shipping for the current address.');
    }

    const requestId = ++shippingEstimateRequestRef.current;
    setIsShippingEstimateLoading(true);
    let responseCoverage: NonNullable<ShippingEstimateData['shop_owned']> | null = null;

    try {
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
      const response = await fetch('/api/shipping/estimate', {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken,
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({
          item_pids: itemPids,
          address_id: addressId,
          shipping_address_line: shippingAddressLine,
          shipping_barangay: shippingBarangay,
          shipping_city: city,
          shipping_region: region,
          shipping_postal_code: shippingPostalCode,
          shipping_latitude: shippingLatitude,
          shipping_longitude: shippingLongitude,
        }),
        signal,
      });

      if (!response.ok) {
        let reason = `Unable to fetch shipping estimate (HTTP ${response.status}).`;
        try {
          const errorData = await response.json();
          reason = errorData?.reason || errorData?.message || reason;
        } catch {
          // Keep the status-based message when the response is not JSON.
        }
        if (response.status === 429) reason = 'Too many shipping estimate requests. Please wait a few seconds and try again.';
        if (response.status === 419) reason = 'Session expired or CSRF token mismatch. Please refresh the page and try again.';
        throw new Error(reason);
      }

      const data = await response.json();
      responseCoverage = data?.shop_owned ?? {
        available: false,
        reason: 'logistics_unavailable',
        distance_km: null,
        coverage_radius_km: null,
      };
      if (!data?.has_estimate) {
        throw new Error(data?.reason || 'Shipping fee is currently unavailable.');
      }

      const estimate: ShippingEstimateData = {
        distance_km: Number(data.distance_km || 0),
        base_fee: Number(data.base_fee || 0),
        min_fee: Number(data.min_fee || 0),
        max_fee: Number(data.max_fee || 0),
        distance_label: data.distance_label,
        customer_notice: data.customer_notice,
        pay_after_order_notice: data.pay_after_order_notice,
        shop_owned: data.shop_owned,
      };

      if (requestId !== shippingEstimateRequestRef.current || signal?.aborted) return estimate;
      setShippingEstimate(estimate);
      setShopOwnedCoverage(responseCoverage);
      setShippingEstimateReason(null);
      return estimate;
    } catch (error) {
      if (requestId === shippingEstimateRequestRef.current && !signal?.aborted) {
        const reason = error instanceof Error
          ? error.message
          : 'Network issue while calculating shipping estimate. Please check your connection and try again.';
        setShippingEstimate(null);
        setShopOwnedCoverage(responseCoverage ?? {
          available: false,
          reason: 'logistics_unavailable',
          distance_km: null,
          coverage_radius_km: null,
        });
        setShippingEstimateReason(reason);
      }
      throw error;
    } finally {
      if (requestId === shippingEstimateRequestRef.current && !signal?.aborted) {
        setIsShippingEstimateLoading(false);
      }
    }
  };

  useEffect(() => {
    if (!checkoutData || isPremiumPayment || isRepairPayment) {
      setShippingEstimate(null);
      setShopOwnedCoverage(null);
      setIsShippingEstimateLoading(false);
      setShippingEstimateReason(null);
      return;
    }

    const city = normalizeCityMunicipalitySelection(shippingRegion, shippingCity);
    const region = shippingRegion.trim();

    if (!city || !region) {
      setShippingEstimate(null);
      setShopOwnedCoverage(null);
      setIsShippingEstimateLoading(false);
      setShippingEstimateReason('Enter city and region to calculate shipping fee.');
      return;
    }

    const itemPids = checkoutData.items
      .map((item) => Number(item.pid))
      .filter((pid) => Number.isInteger(pid) && pid > 0);

    if (itemPids.length === 0) {
      setShippingEstimate(null);
      setShopOwnedCoverage(null);
      setIsShippingEstimateLoading(false);
      setShippingEstimateReason('Unable to resolve product location for shipping estimate.');
      return;
    }

    setShippingEstimate(null);
    setShopOwnedCoverage(null);
    setShippingEstimateReason(null);
    setIsShippingEstimateLoading(true);

    const controller = new AbortController();
    const timer = window.setTimeout(() => {
      requestShippingEstimate(checkoutData.address_id, controller.signal).catch((error) => {
        if (!controller.signal.aborted) console.warn('Shipping estimate lookup failed:', error);
      });
    }, 500);

    return () => {
      controller.abort();
      window.clearTimeout(timer);
    };
  }, [
    checkoutData,
    isPremiumPayment,
    isRepairPayment,
    shippingAddressLine,
    shippingBarangay,
    shippingCity,
    shippingRegion,
    shippingPostalCode,
    shippingLatitude,
    shippingLongitude,
  ]);

  useEffect(() => {
    if (!checkoutData || isPremiumPayment || isRepairPayment) {
      setPromoPreview(null);
      setIsPromoPreviewLoading(false);
      return;
    }

    if (!checkoutData.items || checkoutData.items.length === 0) {
      setPromoPreview(null);
      setIsPromoPreviewLoading(false);
      return;
    }

    const promoPreviewItems = checkoutData.items
      .map((item) => ({
        pid: Math.trunc(toFiniteNumber(item.pid, 0)),
        qty: Math.max(1, Math.trunc(toFiniteNumber(item.qty, 1))),
        price: Math.max(0, toFiniteNumber(item.price, 0)),
      }))
      .filter((item) => item.pid > 0);

    if (promoPreviewItems.length === 0) {
      setPromoPreview(null);
      setIsPromoPreviewLoading(false);
      return;
    }

    const controller = new AbortController();
    const timer = window.setTimeout(async () => {
      try {
        setIsPromoPreviewLoading(true);
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        const promoPayload: any = {
          items: promoPreviewItems,
          disable_voucher: !isVoucherSelectionEnabled,
          shipping_fee: Math.max(0, toFiniteNumber(shippingEstimate?.max_fee ?? checkoutData.shipping_fee, 0)),
          address_id: checkoutData.address_id,
          shipping_latitude: shippingLatitude,
          shipping_longitude: shippingLongitude,
        };

        if (selectedVoucherCampaignId !== null && selectedVoucherCampaignId > 0) {
          promoPayload.voucher_campaign_id = selectedVoucherCampaignId;
        }

        if (appliedVoucherCode.trim()) {
          promoPayload.voucher_code = appliedVoucherCode.trim();
        }

        const response = await fetch('/api/checkout/promo-preview', {
          method: 'POST',
          credentials: 'include',
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: JSON.stringify(promoPayload),
          signal: controller.signal,
        });

        if (!response.ok) {
          setPromoPreview(null);
          return;
        }

        const payload = await response.json();
        setPromoPreview(payload?.data || null);
      } catch (error) {
        if (!controller.signal.aborted) {
          setPromoPreview(null);
          console.warn('Promo preview lookup failed:', error);
        }
      } finally {
        if (!controller.signal.aborted) {
          setIsPromoPreviewLoading(false);
        }
      }
    }, 300);

    return () => {
      controller.abort();
      window.clearTimeout(timer);
    };
  }, [
    checkoutData,
    isPremiumPayment,
    isRepairPayment,
    selectedVoucherCampaignId,
    appliedVoucherCode,
    isVoucherSelectionEnabled,
    shippingEstimate?.max_fee,
    shippingLatitude,
    shippingLongitude,
    voucherPreviewRefreshKey,
  ]);

  useEffect(() => {
    if (isPremiumPayment) {
      setPolicyShopOwnerId(null);
      setActivePolicyVersionId(null);
      setActivePolicySections({});
      setPolicyAccepted(false);
      return;
    }

    if (isRepairPayment && repairIdParam) {
      let cancelled = false;

      const loadRepairShopOwner = async () => {
        try {
          const response = await fetch(`/api/customer/repairs/${repairIdParam}`, {
            headers: { Accept: 'application/json' },
            credentials: 'include',
          });

          if (!response.ok) {
            if (!cancelled) {
              setPolicyShopOwnerId(null);
            }
            return;
          }

          const data = await response.json();
          const shopOwnerId = Number(data?.data?.shop_owner_id || data?.data?.shop_id || 0);
          if (!cancelled) {
            setPolicyShopOwnerId(Number.isFinite(shopOwnerId) && shopOwnerId > 0 ? shopOwnerId : null);
          }
        } catch {
          if (!cancelled) {
            setPolicyShopOwnerId(null);
          }
        }
      };

      void loadRepairShopOwner();
      return () => {
        cancelled = true;
      };
    }

    const checkoutShopOwnerIds = Array.from(new Set(
      (checkoutData?.items || [])
        .map((item) => Number(item.shop_owner_id || 0))
        .filter((shopOwnerId) => Number.isFinite(shopOwnerId) && shopOwnerId > 0)
    ));

    if (checkoutShopOwnerIds.length === 1) {
      setPolicyShopOwnerId(checkoutShopOwnerIds[0]);
      return;
    }

    if (checkoutShopOwnerIds.length > 1) {
      setPolicyShopOwnerId(null);
      return;
    }

    const retailShopOwnerId = Number(promoPreview?.shop_owner_id || 0);
    if (Number.isFinite(retailShopOwnerId) && retailShopOwnerId > 0) {
      setPolicyShopOwnerId(retailShopOwnerId);
      return;
    }

    setPolicyShopOwnerId(null);
  }, [isPremiumPayment, isRepairPayment, repairIdParam, promoPreview?.shop_owner_id, checkoutData]);

  useEffect(() => {
    if (isPremiumPayment || isRepairPayment || !user || policyShopOwnerId) {
      return;
    }

    const checkoutItems = checkoutData?.items || [];
    if (checkoutItems.length === 0) {
      return;
    }

    let cancelled = false;

    const resolvePolicyContextFromCheckout = async () => {
      try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const response = await fetch('/api/policies/checkout/context', {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
          },
          credentials: 'include',
          body: JSON.stringify({
            items: checkoutItems.map((item) => ({ pid: Number(item.pid || 0) })),
          }),
        });

        if (!response.ok || cancelled) {
          return;
        }

        const payload = await response.json();
        const resolvedShopOwnerId = Number(payload?.data?.shop_owner_id || 0);
        if (Number.isFinite(resolvedShopOwnerId) && resolvedShopOwnerId > 0) {
          setPolicyShopOwnerId(resolvedShopOwnerId);
        }
      } catch (error) {
        console.warn('Failed to resolve checkout policy context:', error);
      }
    };

    void resolvePolicyContextFromCheckout();

    return () => {
      cancelled = true;
    };
  }, [isPremiumPayment, isRepairPayment, user, policyShopOwnerId, checkoutData]);

  useEffect(() => {
    if (isPremiumPayment || isRepairPayment || !user || policyShopOwnerId) {
      return;
    }

    const checkoutItems = checkoutData?.items || [];
    if (checkoutItems.length === 0) {
      return;
    }

    const hasShopOwnerMetadata = checkoutItems.some((item) => Number(item.shop_owner_id || 0) > 0);
    if (hasShopOwnerMetadata) {
      return;
    }

    let cancelled = false;

    const hydrateShopOwnerFromCart = async () => {
      try {
        const response = await fetch('/api/cart', {
          method: 'GET',
          headers: {
            Accept: 'application/json',
          },
          credentials: 'include',
        });

        if (!response.ok) {
          return;
        }

        const payload = await response.json();
        const cartItems = Array.isArray(payload?.items) ? payload.items : [];
        if (cartItems.length === 0) {
          return;
        }

        const checkoutPids = new Set(
          checkoutItems
            .map((item) => Number(item.pid || 0))
            .filter((pid) => Number.isFinite(pid) && pid > 0)
        );

        const matchingCartItems = cartItems.filter((item: any) => {
          const pid = Number(item?.pid ?? item?.product_id ?? 0);
          return Number.isFinite(pid) && checkoutPids.has(pid);
        });

        if (matchingCartItems.length === 0) {
          return;
        }

        const resolvedShopOwnerIds = Array.from(new Set(
          matchingCartItems
            .map((item: any) => Number(item?.shop_owner_id ?? item?.shop_id ?? 0))
            .filter((shopOwnerId: number) => Number.isFinite(shopOwnerId) && shopOwnerId > 0)
        ));

        if (resolvedShopOwnerIds.length !== 1 || cancelled) {
          return;
        }

        const resolvedShopOwnerId = resolvedShopOwnerIds[0];
        setPolicyShopOwnerId(resolvedShopOwnerId);

        setCheckoutData((prev) => {
          if (!prev) {
            return prev;
          }

          const updatedItems = prev.items.map((checkoutItem) => {
            const checkoutPid = Number(checkoutItem.pid || 0);
            const matching = matchingCartItems.find((cartItem: any) => Number(cartItem?.pid ?? cartItem?.product_id ?? 0) === checkoutPid);
            const matchingShopOwnerId = Number(matching?.shop_owner_id ?? matching?.shop_id ?? 0);

            if (matchingShopOwnerId > 0 && matchingShopOwnerId !== Number(checkoutItem.shop_owner_id || 0)) {
              return {
                ...checkoutItem,
                shop_owner_id: matchingShopOwnerId,
              };
            }

            return checkoutItem;
          });

          return {
            ...prev,
            items: updatedItems,
          };
        });
      } catch (error) {
        console.warn('Failed to resolve checkout shop owner from cart:', error);
      }
    };

    void hydrateShopOwnerFromCart();

    return () => {
      cancelled = true;
    };
  }, [isPremiumPayment, isRepairPayment, user, policyShopOwnerId, checkoutData]);

  useEffect(() => {
    if (isPremiumPayment || !policyShopOwnerId) {
      setActivePolicyVersionId(null);
      setActivePolicySections({});
      setPolicyAccepted(false);
      setIsLoadingPolicy(false);
      return;
    }

    let cancelled = false;

    const loadPolicy = async () => {
      setIsLoadingPolicy(true);

      try {
        const activeResponse = await fetch(`/api/policies/shops/${policyShopOwnerId}/active`, {
          headers: { Accept: 'application/json' },
          credentials: 'include',
        });

        if (!activeResponse.ok) {
          if (!cancelled) {
            setActivePolicyVersionId(null);
            setActivePolicySections({});
            setPolicyAccepted(false);
          }
          return;
        }

        const activePayload = await activeResponse.json();
        const resolvedVersionId = Number(activePayload?.data?.id || 0);
        const resolvedSections = (activePayload?.data?.policy_sections_json || {}) as Record<string, string>;

        if (!cancelled) {
          setActivePolicyVersionId(Number.isFinite(resolvedVersionId) && resolvedVersionId > 0 ? resolvedVersionId : null);
          setActivePolicySections(resolvedSections);
          setPolicyAccepted(false);
        }

        if (!user || !resolvedVersionId) {
          return;
        }

        const prefillResponse = await fetch(`/api/policies/shops/${policyShopOwnerId}/prefill`, {
          headers: { Accept: 'application/json' },
          credentials: 'include',
        });

        if (prefillResponse.ok) {
          const prefillPayload = await prefillResponse.json();
          if (!cancelled) {
            setPolicyAccepted(Boolean(prefillPayload?.data?.prefill_checked));
          }
        }
      } catch {
        if (!cancelled) {
          setActivePolicyVersionId(null);
          setActivePolicySections({});
          setPolicyAccepted(false);
        }
      } finally {
        if (!cancelled) {
          setIsLoadingPolicy(false);
        }
      }
    };

    void loadPolicy();

    return () => {
      cancelled = true;
    };
  }, [isPremiumPayment, policyShopOwnerId, user?.id]);

  const hasVisiblePolicyTerms = Object.entries(activePolicySections).some(([key, value]) => {
    if (key.startsWith('__')) return false;
    return String(value || '').trim().length > 0;
  });
  const isPolicyAcceptanceRequired = !isPremiumPayment && activePolicyVersionId !== null && hasVisiblePolicyTerms;

  const escapePolicyHtml = (value: string): string => {
    return value
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  };

  const buildPolicyModalHtml = (): string => {
    const getSectionTitleOverride = (key: string): string | null => {
      const override = String(activePolicySections[`__section_title__${key}`] ?? '').trim();
      return override.length > 0 ? override : null;
    };

    const getCustomSectionHeading = (key: string): string | null => {
      if (key.startsWith('custom_terms_retail_')) {
        return `Additional Terms ${key.replace('custom_terms_retail_', '') || '?'}`;
      }

      if (key.startsWith('custom_terms_repair_')) {
        return `Additional Terms ${key.replace('custom_terms_repair_', '') || '?'}`;
      }

      if (key.startsWith('custom_terms_')) {
        return `Additional Terms ${key.replace('custom_terms_', '') || '?'}`;
      }

      return null;
    };

    const scopedCustomSectionKeys = Object.keys(activePolicySections)
      .filter((key) => {
        if (isRepairPayment) {
          return key.startsWith('custom_terms_repair_');
        }

        return key.startsWith('custom_terms_retail_') || key.startsWith('custom_terms_');
      })
      .sort((a, b) => a.localeCompare(b, undefined, { numeric: true }));

    const orderedPolicySectionKeys = isRepairPayment
      ? ['refund_payment_terms_repair', 'refund_payment_terms', 'repair_service_terms']
      : ['refund_payment_terms_retail', 'refund_payment_terms', 'retail_terms'];

    const mergedPolicySectionKeys = [...orderedPolicySectionKeys, ...scopedCustomSectionKeys];

    const sectionHeadingMap: Record<string, string> = {
      refund_payment_terms: 'Refund and Payment Terms',
      refund_payment_terms_retail: 'Refund and Payment Terms',
      refund_payment_terms_repair: 'Refund and Payment Terms',
      retail_terms: 'Retail Terms',
      repair_service_terms: 'Repair Service Terms',
    };

    const entries = mergedPolicySectionKeys
      .map((key) => [key, activePolicySections[key]] as const)
      .filter(([, value]) => String(value || '').trim().length > 0);

    const sectionsHtml = entries.length === 0
      ? `
        <h3>1. Terms Unavailable</h3>
        <p>No applicable terms are available for this payment flow right now. Please try again later.</p>
      `
      : entries.map(([key, value], index) => {
        const customSectionHeading = getCustomSectionHeading(key);
        const heading = getSectionTitleOverride(key)
          ?? sectionHeadingMap[key]
          ?? customSectionHeading
          ?? key.replace(/_/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase());

        return `
          <h3>${index + 1}. ${escapePolicyHtml(heading)}</h3>
          <p>${escapePolicyHtml(String(value)).replace(/\n/g, '<br/>')}</p>
        `;
      }).join('');

    return `
      <div class="terms-modal">
        <div class="terms-modal__icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <rect x="8" y="3" width="8" height="4" rx="1"></rect>
            <path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"></path>
            <path d="M9 12h6"></path>
            <path d="M9 16h6"></path>
          </svg>
        </div>
        <p class="terms-modal__intro">Please read these terms before continuing your payment.</p>
        <div class="terms-modal__scroll">
          ${sectionsHtml}
        </div>
        <p class="terms-modal__hint">Scroll to the bottom to enable the Accept button.</p>
      </div>
    `;
  };

  const handlePolicyAcceptanceToggle = async (nextChecked: boolean) => {
    if (!nextChecked) {
      setPolicyAccepted(false);
      return;
    }

    if (!isPolicyAcceptanceRequired || !activePolicyVersionId) {
      await Swal.fire({
        icon: 'warning',
        title: 'Terms Not Available',
        text: 'No active terms are available for this payment yet. Please refresh and try again.',
        confirmButtonColor: '#000000',
      });
      setPolicyAccepted(false);
      return;
    }

    const result = await openTermsPolicyModal('TERMS OF SERVICE', buildPolicyModalHtml());
    setPolicyAccepted(Boolean(result.isConfirmed));
  };

  useEffect(() => {
    if (selectedVoucherCampaignId === null) {
      return;
    }

    const availableVoucherIds = [
      ...(promoPreview?.available_vouchers || []),
      ...(promoPreview?.voucher_code_suggestions || []),
    ].map((voucher) => voucher.id);
    if (!availableVoucherIds.includes(selectedVoucherCampaignId)) {
      setSelectedVoucherCampaignId(null);
    }
  }, [promoPreview, selectedVoucherCampaignId]);

  useEffect(() => {
    const handleClickOutsideVoucherInput = (event: MouseEvent | TouchEvent) => {
      const target = event.target as Node | null;
      if (!target) {
        return;
      }

      if (voucherInputContainerRef.current && !voucherInputContainerRef.current.contains(target)) {
        setIsVoucherSuggestionOpen(false);
      }
    };

    document.addEventListener('mousedown', handleClickOutsideVoucherInput);
    document.addEventListener('touchstart', handleClickOutsideVoucherInput);

    return () => {
      document.removeEventListener('mousedown', handleClickOutsideVoucherInput);
      document.removeEventListener('touchstart', handleClickOutsideVoucherInput);
    };
  }, []);

  useEffect(() => {
    const handleClickOutsideCityDropdown = (event: MouseEvent | TouchEvent) => {
      const target = event.target as Node | null;
      if (!target) {
        return;
      }

      if (desktopCityDropdownRef.current && !desktopCityDropdownRef.current.contains(target)) {
        setIsDesktopCityDropdownOpen(false);
      }

      if (sheetCityDropdownRef.current && !sheetCityDropdownRef.current.contains(target)) {
        setIsSheetCityDropdownOpen(false);
      }

      if (desktopProvinceDropdownRef.current && !desktopProvinceDropdownRef.current.contains(target)) {
        setIsDesktopProvinceDropdownOpen(false);
      }

      if (sheetProvinceDropdownRef.current && !sheetProvinceDropdownRef.current.contains(target)) {
        setIsSheetProvinceDropdownOpen(false);
      }
    };

    document.addEventListener('mousedown', handleClickOutsideCityDropdown);
    document.addEventListener('touchstart', handleClickOutsideCityDropdown);

    return () => {
      document.removeEventListener('mousedown', handleClickOutsideCityDropdown);
      document.removeEventListener('touchstart', handleClickOutsideCityDropdown);
    };
  }, []);

  useEffect(() => {
    if (isPremiumPayment || isRepairPayment) {
      return;
    }

    if (!isVoucherSelectionEnabled) {
      return;
    }

    const hasManualVoucherSelection = selectedVoucherCampaignId !== null || normalizeVoucherCode(appliedVoucherCode) !== '';
    if (hasManualVoucherSelection || hasVoucherInputInteraction) {
      return;
    }

    const suggestedVoucher = promoPreview?.applied_voucher
      || promoPreview?.available_vouchers?.[0]
      || null;

    const suggestedLabel = normalizeVoucherCode(String(suggestedVoucher?.code || suggestedVoucher?.name || ''));
    if (!suggestedLabel) {
      return;
    }

    if (normalizeVoucherCode(voucherCodeInput) === suggestedLabel) {
      return;
    }

    setVoucherCodeInput(suggestedLabel);
  }, [
    promoPreview,
    isPremiumPayment,
    isRepairPayment,
    isVoucherSelectionEnabled,
    selectedVoucherCampaignId,
    appliedVoucherCode,
    hasVoucherInputInteraction,
    voucherCodeInput,
  ]);

  // Validate postal code - only integers, no "e" or special characters
  const handlePostalCodeChange = (value: string, setter: (val: string) => void) => {
    const cleaned = value.replace(/[^\d]/g, '');
    setter(cleaned);
  };

  const handlePhoneChange = (value: string) => {
    setCustomerPhone(value.replace(/\D/g, '').slice(0, 11));
  };

  // Persist the delivery address before creating an order so its address_id is current.
  const saveAddressToAccount = async (): Promise<number | null> => {
    if (!user || isPremiumPayment || isRepairPayment) return checkoutData?.address_id ?? null;
    const normalizedShippingCity = normalizeCityMunicipalitySelection(shippingRegion, shippingCity);
    if (!normalizedShippingCity) {
      throw new Error('Please select a valid city or municipality before checkout.');
    }

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const targetAddressId = checkoutData?.address_id ?? null;
    const response = await fetch(targetAddressId ? `/api/user/addresses/${targetAddressId}` : '/api/user/addresses', {
      method: targetAddressId ? 'PUT' : 'POST',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken,
        'X-Requested-With': 'XMLHttpRequest',
      },
      credentials: 'include',
      body: JSON.stringify({
        name: customerName,
        phone: customerPhone,
        address_line: shippingAddressLine,
        region: shippingRegion,
        province: shippingRegion,
        city: normalizedShippingCity,
        barangay: shippingBarangay,
        postal_code: shippingPostalCode,
        latitude: shippingLatitude,
        longitude: shippingLongitude,
        is_default: setAsDefaultAddress,
      }),
    });

    if (!response.ok) {
      throw new Error('Unable to save delivery address. Please try again.');
    }

    const savedAddress: UserAddress | undefined = (await response.json()).address;
    if (!savedAddress?.id) {
      throw new Error('Unable to save delivery address. Please try again.');
    }

    setUserAddresses((prev) => targetAddressId
      ? prev.map((address) => address.id === savedAddress.id ? savedAddress : address)
      : [savedAddress, ...prev]);
    setCheckoutData((prev) => (prev ? { ...prev, address_id: savedAddress.id } : prev));
    return savedAddress.id;
  };

  const handleCreateNewPaymentSession = async () => {
    if (!paymentRecovery?.id || isRecoveryCreating) {
      return;
    }

    setIsRecoveryCreating(true);
    setPayError(null);

    try {
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
      const endpoint = paymentRecovery.scope === 'repair'
        ? `/api/customer/repairs/${paymentRecovery.id}/retry-payment-session`
        : `/api/orders/${paymentRecovery.id}/retry-payment-session`;

      const retryPayload = paymentRecovery.scope === 'repair'
        ? undefined
        : {
            shipping_fee: !isPremiumPayment && !isRepairPayment
              ? Math.max(0, Number(shippingEstimate?.max_fee ?? checkoutData?.shipping_fee ?? 0))
              : 0,
            subtotal_amount: Number(promoPreview?.final_subtotal ?? checkoutData?.total_amount ?? 0),
          };

      const response = await fetch(endpoint, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrfToken,
        },
        body: retryPayload ? JSON.stringify(retryPayload) : undefined,
      });

      const data = await response.json().catch(() => ({}));

      if (!response.ok) {
        throw new Error(data?.message || data?.error || 'Failed to create a new payment session.');
      }

      const checkoutUrl = data?.checkout_url;
      if (!checkoutUrl) {
        throw new Error('Incomplete payment data received from PayMongo');
      }

      if (paymentRecovery.scope === 'repair') {
        sessionStorage.setItem('pendingRepairId', String(paymentRecovery.id));
      } else {
        sessionStorage.setItem('pendingOrderId', String(paymentRecovery.id));
      }

      window.location.href = checkoutUrl;
    } catch (error: any) {
      const rawMessage = String(error?.message || 'Unable to create a new payment session.');
      const isInvalidState = /resource_invalid_state|invalid_state|grab_pay/i.test(rawMessage);
      const message = isInvalidState
        ? 'The previous wallet session became invalid or stale. Please create a fresh payment session and complete checkout in one attempt.'
        : rawMessage;
      setPayError(message);

      Swal.fire({
        icon: 'error',
        title: 'Unable to Create Session',
        text: message,
        confirmButtonColor: '#000000',
      });
    } finally {
      setIsRecoveryCreating(false);
    }
  };

  const handlePayNow = async () => {
    if (!checkoutData) return;

    if (isPolicyAcceptanceRequired && (!activePolicyVersionId || !policyAccepted)) {
      setPayError('Please review and accept the latest shop terms before proceeding to payment.');
      await Swal.fire({
        icon: 'warning',
        title: 'Terms Acceptance Required',
        text: 'Please review and accept the latest shop terms before proceeding to payment.',
        confirmButtonColor: '#000000',
      });
      return;
    }

    const normalizedOrderItems = checkoutData.items
      .map((item: any) => ({
        ...item,
        pid: Math.trunc(toFiniteNumber(item?.pid, 0)),
        qty: Math.max(1, Math.trunc(toFiniteNumber(item?.qty, 1))),
        price: Math.max(0, toFiniteNumber(item?.price, 0)),
      }))
      .filter((item: any) => item.pid > 0);

    if (normalizedOrderItems.length === 0) {
      setPayError('Unable to process checkout items. Please go back to cart and try again.');
      Swal.fire({
        icon: 'error',
        title: 'Invalid Cart Data',
        text: 'Some checkout items are invalid. Please go back to cart and try again.',
        confirmButtonColor: '#000000',
      });
      return;
    }

    let computedShippingFee = !isPremiumPayment && !isRepairPayment
      ? Math.max(0, Number(shippingEstimate?.max_fee ?? 0))
      : 0;
    const normalizedSubtotalAmount = Math.max(0, toFiniteNumber(promoPreview?.final_subtotal, checkoutData.total_amount));
    const normalizedShippingCity = normalizeCityMunicipalitySelection(shippingRegion, shippingCity);

    // Validate required fields
    if (!customerEmail || !customerName || !customerPhone) {
      setPayError('Please fill in all required contact information.');
      Swal.fire({
        icon: 'warning',
        title: 'Missing Information',
        text: 'Please fill in all required contact information.',
        confirmButtonColor: '#000000',
      });
      return;
    }

    if (!/^\d{11}$/.test(customerPhone)) {
      setPayError('Phone number must contain exactly 11 digits.');
      Swal.fire({
        icon: 'warning',
        title: 'Invalid phone number',
        text: 'Phone number must contain exactly 11 digits.',
        confirmButtonColor: '#000000',
      });
      return;
    }

    if (!shippingAddressLine || !shippingBarangay || !normalizedShippingCity || !shippingRegion || !shippingPostalCode) {
      setPayError('Please fill in all required shipping address fields.');
      Swal.fire({
        icon: 'warning',
        title: 'Missing Address',
        text: shippingCity && !normalizedShippingCity
          ? 'Please reselect a city or municipality for the selected province.'
          : 'Please fill in all required shipping address fields.',
        confirmButtonColor: '#000000',
      });
      return;
    }

    if (!isPremiumPayment && !isRepairPayment) {
      if (isShippingEstimateLoading) {
        setPayError('Shipping fee is still being calculated. Please wait a moment.');
        Swal.fire({
          icon: 'warning',
          title: 'Shipping Still Calculating',
          text: 'Please wait for the shipping fee to finish loading.',
          confirmButtonColor: '#000000',
        });
        return;
      }

      if (!shippingEstimate || computedShippingFee <= 0) {
        const reason = shippingEstimateReason || 'Unable to compute shipping fee. Please check your address details and try again.';
        setPayError(reason);
        Swal.fire({
          icon: 'warning',
          title: 'Shipping Fee Unavailable',
          text: reason,
          confirmButtonColor: '#000000',
        });
        return;
      }
    }

    setIsProcessing(true);
    setPayError(null);

    try {
      const savedAddressId = await saveAddressToAccount();

      if (!isPremiumPayment && !isRepairPayment) {
        try {
          const refreshedEstimate = await requestShippingEstimate(savedAddressId);
          computedShippingFee = Math.max(0, Number(refreshedEstimate.max_fee || 0));
          if (computedShippingFee <= 0) throw new Error('Shipping fee is unavailable.');
        } catch {
          const reason = 'Your address was saved, but shipping could not be refreshed. Please retry checkout.';
          setPayError(reason);
          await Swal.fire({
            icon: 'warning',
            title: 'Shipping Refresh Needed',
            text: reason,
            confirmButtonColor: '#000000',
          });
          setIsProcessing(false);
          return;
        }
      }

      // Create the order only after its delivery address is persisted.
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
      
      const orderData = {
        items: normalizedOrderItems,
        total_amount: normalizedSubtotalAmount,
        shipping_fee: computedShippingFee,
        customer_name: customerName,
        customer_email: customerEmail,
        customer_phone: customerPhone,
        shipping_address: `${shippingAddressLine}, ${shippingBarangay}, ${normalizedShippingCity}, ${shippingRegion} ${shippingPostalCode}`,
        address_id: savedAddressId,
        shipping_region: shippingRegion,
        shipping_province: shippingRegion,
        shipping_city: normalizedShippingCity,
        shipping_barangay: shippingBarangay,
        shipping_postal_code: shippingPostalCode,
        shipping_address_line: shippingAddressLine,
        payment_method: selectedPaymentMethod,
        disable_voucher: !isVoucherSelectionEnabled,
        voucher_campaign_id: selectedVoucherCampaignId,
        voucher_code: appliedVoucherCode.trim() || null,
        accepted_shop_policy_version_id: isPolicyAcceptanceRequired ? activePolicyVersionId : null,
        policy_accepted: isPolicyAcceptanceRequired ? policyAccepted : null,
      };

      console.log('Creating order with data:', orderData);

      const orderResponse = await fetch('/api/checkout/create-order', {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrfToken || '',
        },
        body: JSON.stringify(orderData),
      });

      if (!orderResponse.ok) {
        const errorData = await orderResponse.json();
        throw new Error(errorData.message || 'Failed to create order');
      }

      const orderResult = await orderResponse.json();
      console.log('Order created successfully:', orderResult);

      const orderId = orderResult.order?.id || orderResult.order_id;
      sessionStorage.setItem('pendingOrderId', orderId);

      // Create a dedicated payment retry session that also persists fresh link metadata.
      const response = await fetch(`/api/orders/${orderId}/retry-payment-session`, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrfToken || '',
        },
        body: JSON.stringify({
          shipping_fee: computedShippingFee,
          subtotal_amount: normalizedSubtotalAmount,
        }),
      });

      if (!response.ok) {
        const errorData = await response.json();
        if (errorData.error === 'shop_payment_not_configured') {
          throw new Error('This shop has not set up online payments yet. Please contact the shop owner.');
        }
        throw new Error(errorData.error || 'Failed to create payment link');
      }

      const paymentData = await response.json();
      const checkoutUrl = paymentData.checkout_url;

      if (!checkoutUrl) {
        throw new Error('Incomplete payment data received from PayMongo');
      }

      // Redirect to PayMongo payment page
      window.location.href = checkoutUrl;
    } catch (err: any) {
      console.error('Payment error:', err);
      const errorMessage = err?.message || 'Unable to process payment';
      setPayError(errorMessage);
      setIsProcessing(false);

      Swal.fire({
        icon: 'error',
        title: 'Payment Failed',
        text: errorMessage,
        confirmButtonColor: '#000000',
      });
    }
  };

  if (!checkoutData) {
    return <div>Loading...</div>;
  }

  const rawSubtotal = Math.max(0, toFiniteNumber(promoPreview?.final_subtotal, checkoutData.total_amount));
  const checkoutShipping = Math.max(0, toFiniteNumber(checkoutData.shipping_fee, 0));
  const selectedCity = normalizeCityMunicipalitySelection(shippingRegion, shippingCity);
  const hasSelectedCity = Boolean(selectedCity);
  const shipping = !isPremiumPayment && !isRepairPayment
    ? (hasSelectedCity
      ? Math.max(0, Number(shippingEstimate?.max_fee ?? checkoutShipping ?? 0))
      : 0)
    : 0;
  const previewRawShippingFee = toFiniteNumber(promoPreview?.raw_shipping_fee, Number.NaN);
  const hasMatchingShippingPreview = Number.isFinite(previewRawShippingFee)
    && Math.abs(previewRawShippingFee - shipping) < 0.01;
  const shippingVoucherDiscount = !isPremiumPayment && !isRepairPayment && hasMatchingShippingPreview
    ? Math.min(shipping, Math.max(0, toFiniteNumber(promoPreview?.shipping_voucher_discount, 0)))
    : 0;
  const discountedShipping = !isPremiumPayment && !isRepairPayment && hasMatchingShippingPreview
    ? Math.min(shipping, Math.max(0, toFiniteNumber(promoPreview?.discounted_shipping_fee, shipping)))
    : shipping;
  const parsedVatRate = toFiniteNumber(promoPreview?.vat_rate, toFiniteNumber(checkoutData.vat_rate, 12));
  const vatRatePercent = Number.isFinite(parsedVatRate) && parsedVatRate >= 0 ? parsedVatRate : 12;
  const hasStoredVat = (promoPreview?.vat_amount !== undefined && promoPreview?.vat_amount !== null)
    || (checkoutData.vat_amount !== undefined && checkoutData.vat_amount !== null);
  const parsedVatAmount = toFiniteNumber(promoPreview?.vat_amount, toFiniteNumber(checkoutData.vat_amount, 0));
  const normalizedRawSubtotal = Number.isFinite(rawSubtotal) ? Math.max(0, rawSubtotal) : 0;
  const safeVatRatePercent = Math.max(0, vatRatePercent);
  const derivedInclusiveVatAmount = safeVatRatePercent > 0
    ? (normalizedRawSubtotal * (safeVatRatePercent / (100 + safeVatRatePercent)))
    : 0;
  const promoNetSubtotal = toFiniteNumber(promoPreview?.net_subtotal, Number.NaN);
  const vatAmount = !isPremiumPayment && !isRepairPayment
    ? (hasStoredVat && Number.isFinite(parsedVatAmount) && parsedVatAmount >= 0
      ? parsedVatAmount
      : derivedInclusiveVatAmount)
    : 0;
  const subtotal = !isPremiumPayment && !isRepairPayment
    ? (Number.isFinite(promoNetSubtotal) && promoNetSubtotal >= 0
      ? promoNetSubtotal
      : (hasStoredVat ? normalizedRawSubtotal : Math.max(0, normalizedRawSubtotal - vatAmount)))
    : normalizedRawSubtotal;
  const productTotalInclusive = !isPremiumPayment && !isRepairPayment
    ? normalizedRawSubtotal
    : subtotal;
  const total = !isPremiumPayment && !isRepairPayment
    ? productTotalInclusive + discountedShipping
    : subtotal + discountedShipping;
  const vatLabel = `VAT (${vatRatePercent}%)`;
  const vatDisplay = `₱${vatAmount.toLocaleString()}`;
  const voucherDiscountAmount = !isPremiumPayment && !isRepairPayment
    ? Math.max(0, toFiniteNumber(promoPreview?.voucher_discount, 0))
    : 0;
  const appliedVoucherLabel = promoPreview?.applied_voucher?.name
    || promoPreview?.applied_voucher?.code
    || 'Voucher';
  const shippingVoucherLabel = promoPreview?.applied_voucher?.target === 'shipping'
    ? (promoPreview.applied_voucher.name || promoPreview.applied_voucher.code || 'Shipping voucher')
    : 'Shipping voucher';
  const availableVouchers = promoPreview?.available_vouchers || [];
  const voucherCodeSuggestions = promoPreview?.voucher_code_suggestions || [];
  const voucherSuggestionMap = new Map<number, AvailableVoucherOption>();
  [...availableVouchers, ...voucherCodeSuggestions].forEach((voucher) => {
    if (!voucherSuggestionMap.has(voucher.id)) {
      voucherSuggestionMap.set(voucher.id, voucher);
    }
  });
  const voucherSuggestions = Array.from(voucherSuggestionMap.values());
  const voucherSearchTerm = normalizeVoucherCode(voucherCodeInput);
  const filteredVoucherCodeSuggestions = voucherSuggestions.filter((voucher) => {
    const code = normalizeVoucherCode(voucher.code || '');
    const name = normalizeVoucherCode(voucher.name || '');

    if (!voucherSearchTerm) {
      return true;
    }

    return code.includes(voucherSearchTerm) || name.includes(voucherSearchTerm);
  });
  const hasExactVoucherSuggestionMatch = voucherSuggestions.some((voucher) => {
    const candidateCode = normalizeVoucherCode(String(voucher.code || voucher.name || ''));
    return candidateCode !== '' && candidateCode === normalizeVoucherCode(voucherCodeInput);
  });
  const voucherErrorMessage = promoPreview?.voucher_error || null;
  const shippingVoucherErrorMessage = promoPreview?.shipping_voucher_error || null;
  const showVoucherSuggestionDropdown = isVoucherSuggestionOpen && !hasExactVoucherSuggestionMatch;
  const itemCount = checkoutData.items.reduce((sum, item) => sum + Math.max(1, Math.trunc(toFiniteNumber(item.qty, 1))), 0);
  const hasShippingEstimate = Boolean(shippingEstimate) && hasSelectedCity;
  const shippingSummaryValue = hasSelectedCity
    ? (hasShippingEstimate ? `₱${discountedShipping.toLocaleString()}` : (isShippingEstimateLoading ? 'Calculating...' : 'Unavailable'))
    : '';
  const isShippingCalculating = hasSelectedCity && isShippingEstimateLoading;
  const shippingCarrierNote = hasShippingEstimate
    ? ''
    : (hasSelectedCity ? (shippingEstimateReason || 'Complete your delivery address to calculate shipping.') : '');
  const shippingPayLaterNotice = hasShippingEstimate
    ? ''
    : (hasSelectedCity ? 'Shipping fee must be calculated before you can continue to payment.' : '');
  const selectedSavedAddress = userAddresses.find((address) => address.id === checkoutData.address_id);
  const shopOwnedCoverageDetails = [
    shopOwnedCoverage?.distance_km != null ? `${shopOwnedCoverage.distance_km.toLocaleString()} km away` : null,
    shopOwnedCoverage?.coverage_radius_km != null ? `${shopOwnedCoverage.coverage_radius_km.toLocaleString()} km coverage radius` : null,
  ].filter(Boolean).join(' · ');
  const shopOwnedCoverageLabel = shopOwnedCoverage?.available
    ? 'Eligible for Shop-owned Logistics'
    : shopOwnedCoverage?.reason === 'outside_coverage'
      ? 'Outside Shop-owned coverage'
      : shopOwnedCoverage?.reason === 'address_needs_pin'
        ? 'Pin this address to check Shop-owned coverage'
        : shopOwnedCoverage?.reason === 'shop_needs_pin'
          ? 'Shop location is not pinned'
          : shopOwnedCoverage
            ? 'Shop-owned logistics is unavailable'
            : '';
  const shopOwnedCoverageNotice = shopOwnedCoverageLabel ? (
    <div
      role="status"
      aria-live="polite"
      className={`mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 rounded-md border px-3 py-2 text-xs leading-relaxed ${
        shopOwnedCoverage?.available
          ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
          : shopOwnedCoverage?.reason === 'outside_coverage'
            ? 'border-amber-200 bg-amber-50 text-amber-900'
            : 'border-gray-200 bg-gray-50 text-gray-700'
      }`}
    >
      <span className="font-medium">{shopOwnedCoverageLabel}</span>
      {shopOwnedCoverageDetails && <span>{shopOwnedCoverageDetails}</span>}
      {shopOwnedCoverage?.reason === 'address_needs_pin' && selectedSavedAddress && (
        <button
          type="button"
          onClick={() => handleEditAddressFromList(selectedSavedAddress, true)}
          className="font-semibold underline underline-offset-2"
        >
          Repin address
        </button>
      )}
    </div>
  ) : null;
  const fullShippingAddress = [shippingAddressLine, shippingBarangay, shippingCity, shippingRegion]
    .filter(Boolean)
    .join(', ');
  const deliveryName = customerName || user?.name || 'No delivery name yet';
  const deliveryPhone = customerPhone || user?.phone || 'No phone yet';
  const paymentBackFallbackHref = isPremiumPayment ? '/shop-owner/premium-benefits' : '/checkout';
  const checkoutPolicyShopOwnerIds = Array.from(new Set(
    (checkoutData?.items || [])
      .map((item) => Number(item.shop_owner_id || 0))
      .filter((shopOwnerId) => Number.isFinite(shopOwnerId) && shopOwnerId > 0)
  ));
  const hasMixedShopCart = checkoutPolicyShopOwnerIds.length > 1;
  const policyStatusText = isLoadingPolicy
    ? 'Loading shop terms...'
    : hasMixedShopCart
      ? 'Checkout currently includes multiple shops. Complete one shop per checkout to apply policy terms.'
      : (!policyShopOwnerId && !isPremiumPayment)
        ? 'We could not resolve this checkout\'s shop policy yet. Please refresh or return to checkout and try again.'
    : isPolicyAcceptanceRequired
      ? 'You must read and accept the latest published terms before payment.'
      : `No active published terms found for this shop yet${policyShopOwnerId ? ` (Shop ID: ${policyShopOwnerId})` : ''}.`;
  const showPolicyAcceptanceCard = !isPremiumPayment && hasVisiblePolicyTerms;

  return (
    <div className="min-h-screen flex flex-col bg-white">
      <style>{`
        .hide-scrollbar {
          -ms-overflow-style: none;
          scrollbar-width: none;
        }

        .hide-scrollbar::-webkit-scrollbar {
          width: 0;
          height: 0;
          display: none;
        }
      `}</style>
      <Head title="Payment" />

      {!isPremiumPayment && <div className="hidden xl:block"><Navigation /></div>}

      <main className={`flex-1 ${!isPremiumPayment ? 'xl:pt-28' : ''}`}>
        <div className="max-w-6xl mx-auto py-0 xl:py-10 px-4 xl:px-4 text-black">
          {paymentRecovery && (
            <div className="mx-4 xl:mx-0 mb-4 rounded-lg border border-amber-300 bg-amber-50 p-4">
              <p className="text-sm font-medium text-amber-900 mb-2">
                {paymentRecovery.reason === 'expired'
                  ? 'Payment session expired. Create a new payment session to continue.'
                  : paymentRecovery.reason === 'invalid_state'
                    ? 'PayMongo returned an invalid/stale wallet state for this attempt. Please create a fresh payment session and try again without using old tabs or back navigation.'
                    : 'Payment was not completed. You can create a new payment session and try again.'}
              </p>
              <button
                type="button"
                onClick={handleCreateNewPaymentSession}
                disabled={isRecoveryCreating}
                className={`inline-flex items-center rounded-md px-4 py-2 text-sm font-semibold text-white ${isRecoveryCreating ? 'bg-gray-400 cursor-not-allowed' : 'bg-gray-900 hover:bg-black'}`}
              >
                {isRecoveryCreating ? 'Creating...' : 'Create New Payment'}
              </button>
            </div>
          )}

          {isPremiumPayment && (
            <div className="mb-6 px-4 xl:px-0 pt-4 xl:pt-0">
              <Link
                href="/shop-owner/premium-benefits"
                className="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 hover:text-gray-900"
              >
                Back
              </Link>
            </div>
          )}

          <div className="xl:hidden min-h-screen bg-white pb-0">
            <div className="sticky top-0 z-20 bg-white border-b border-gray-200 px-4 py-4 flex items-center justify-between">
              <button
                type="button"
                onClick={() => navigateBackOr(paymentBackFallbackHref)}
                className="text-black text-xl leading-none"
                aria-label="Go back"
              >
                &larr;
              </button>
              <h1 className="text-2xl font-semibold text-black">Order summary</h1>
              <div className="w-6" />
            </div>

            <button
              type="button"
              onClick={openAddressSheet}
              className="w-full text-left bg-white px-4 py-4 border-b border-gray-200"
            >
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <p className="text-lg font-semibold text-black truncate">{deliveryName} ({deliveryPhone})</p>
                  <p className="text-sm text-gray-700 mt-1 line-clamp-2">
                    {fullShippingAddress || 'Add your shipping address in Contact and delivery details below.'}
                  </p>
                </div>
                <span className="text-gray-400 text-xl">&rsaquo;</span>
              </div>
            </button>

            <div className="mt-2 bg-white px-4 py-4 border-y border-gray-200">
              <div className="flex items-center justify-between mb-3">
                <h2 className="text-3xl font-semibold text-black">Your order</h2>
              </div>

              <div className="space-y-4">
                {checkoutData.items.map((item) => (
                  <div key={item.id} className="flex gap-3">
                    <div className="w-24 h-24 rounded-lg bg-gray-100 overflow-hidden shrink-0 border border-gray-200">
                      {item.image ? (
                        <img src={item.image} alt={item.name} className="w-full h-full object-cover" />
                      ) : isPremiumPayment ? (
                        <div className="flex h-full w-full items-center justify-center bg-slate-900 text-[10px] font-semibold tracking-wide text-white">
                          SOLESPACE
                        </div>
                      ) : null}
                    </div>
                    <div className="flex-1 min-w-0">
                      <p className="text-sm font-medium text-black line-clamp-2">{item.name}</p>
                      <p className="text-xs text-gray-600 mt-1">Qty: {item.qty}</p>
                      {item.size && <p className="text-xs text-gray-600">Size: {item.size}</p>}
                      {item.color && <p className="text-xs text-gray-600">Color: {item.color}</p>}
                      <p className="text-2xl font-bold text-gray-900 mt-2">₱{(item.price * item.qty).toLocaleString()}</p>
                    </div>
                  </div>
                ))}
              </div>

              <div className="mt-4 rounded-xl bg-gray-100 px-4 py-3 flex items-center justify-between">
                <span className="text-sm text-gray-700">Shipping fee</span>
                <div className="text-sm">
                  <span className="font-semibold text-black inline-flex items-center gap-2">
                    {isShippingCalculating && (
                      <span className="inline-flex h-4 w-4 items-center justify-center rounded-full border-2 border-gray-400 border-t-transparent animate-spin" aria-hidden="true" />
                    )}
                    <span>
                      {isShippingCalculating
                        ? 'Calculating...'
                        : hasShippingEstimate
                          ? `₱${discountedShipping.toLocaleString()}`
                          : 'Unavailable'}
                    </span>
                  </span>
                </div>
                {shippingVoucherDiscount > 0 && (
                  <p className="mt-1 text-right text-xs text-emerald-700">Shipping voucher saved ₱{shippingVoucherDiscount.toLocaleString()}</p>
                )}
              </div>
              {shopOwnedCoverageNotice}
            </div>

            <div className="mt-2 bg-white px-4 py-4 md:px-6 md:py-5 border-y border-gray-200">
              <h3 className="text-xl md:text-2xl font-semibold text-black mb-3">Order summary</h3>

              <div className="space-y-2.5 text-sm md:text-base">
                <div className="flex items-center justify-between">
                  <span className="font-semibold text-black">Product subtotal (Before VAT)</span>
                  <span className="font-semibold text-black">₱{subtotal.toLocaleString()}</span>
                </div>
                <div className="flex items-center justify-between pl-3 text-xs md:text-sm">
                  <span className="text-gray-600">Product total (VAT-inclusive)</span>
                  <span className="text-gray-600">₱{productTotalInclusive.toLocaleString()}</span>
                </div>
                {isPromoPreviewLoading && (
                  <div className="flex items-center justify-between pl-3 text-xs md:text-sm">
                    <span className="text-gray-500">Voucher check</span>
                    <span className="text-gray-500">Checking claimed vouchers...</span>
                  </div>
                )}
                {!isPromoPreviewLoading && voucherDiscountAmount > 0 && (
                  <div className="flex items-center justify-between pl-3 text-xs md:text-sm">
                    <span className="text-emerald-700 font-semibold">{appliedVoucherLabel}</span>
                    <span className="font-semibold text-emerald-700">-₱{voucherDiscountAmount.toLocaleString()}</span>
                  </div>
                )}
                {!isPromoPreviewLoading && shippingVoucherDiscount > 0 && (
                  <div className="flex items-center justify-between pl-3 text-xs md:text-sm">
                    <span className="text-emerald-700 font-semibold">{shippingVoucherLabel}</span>
                    <span className="font-semibold text-emerald-700">-₱{shippingVoucherDiscount.toLocaleString()}</span>
                  </div>
                )}

                <div className="pt-2">
                  <div className="flex items-start justify-between gap-3">
                    <span className="text-gray-700">Shipping</span>
                    <span className="text-black text-right max-w-[70%] wrap-break-word inline-flex items-center gap-2">
                      {isShippingCalculating && (
                        <span className="inline-flex h-4 w-4 items-center justify-center rounded-full border-2 border-gray-500 border-t-transparent animate-spin" aria-hidden="true" />
                      )}
                      <span>{shippingSummaryValue}</span>
                    </span>
                  </div>
                  {shippingVoucherDiscount > 0 && (
                    <div className="mt-1 flex items-center justify-between text-xs text-gray-500">
                      <span>Original shipping</span>
                      <span className="line-through">₱{shipping.toLocaleString()}</span>
                    </div>
                  )}
                  {shippingCarrierNote && <p className="text-xs text-gray-500 mt-1 leading-relaxed">{shippingCarrierNote}</p>}
                  {shippingPayLaterNotice && <p className="text-xs text-gray-500 mt-1 leading-relaxed">{shippingPayLaterNotice}</p>}
                  {shippingVoucherErrorMessage && <p className="text-xs font-medium text-red-600 mt-1 leading-relaxed">{shippingVoucherErrorMessage}</p>}
                </div>

                <div className="flex items-center justify-between pt-0.5">
                  <span className="text-gray-700">{vatLabel}</span>
                  <span className="text-gray-700">{vatDisplay}</span>
                </div>

                <div className="border-t border-gray-200 pt-3 mt-3 flex items-center justify-between">
                  <span className="text-lg md:text-2xl font-semibold text-black">Total</span>
                  <span className="text-2xl md:text-3xl font-bold text-black">₱{total.toLocaleString()}</span>
                </div>
              </div>

            </div>

            <div className="mt-2 bg-white px-4 py-5 border-y border-gray-200">
              <h3 className="text-3xl font-semibold text-black mb-4">Payment method</h3>

              <label
                className={`flex items-center justify-between px-3 py-3 border rounded-xl cursor-pointer transition-colors ${
                  selectedPaymentMethod === 'paymongo' ? 'border-gray-900 bg-gray-50' : 'border-gray-200 bg-white'
                }`}
              >
                <div className="flex items-center gap-3">
                  <span className="inline-flex min-w-14 items-center justify-center rounded-md bg-slate-900 px-2 py-1 text-[11px] font-semibold uppercase tracking-wide text-white">
                    Online
                  </span>
                  <div>
                    <p className="text-base font-medium text-black leading-tight">PayMongo card/e-wallet</p>
                    <p className="text-xs text-gray-500 mt-1">Card, GCash, and Maya checkout</p>
                  </div>
                </div>
                <input
                  type="radio"
                  name="mobile-payment-method"
                  value="paymongo"
                  checked={selectedPaymentMethod === 'paymongo'}
                  onChange={() => setSelectedPaymentMethod('paymongo')}
                  className="h-5 w-5 accent-indigo-600"
                />
              </label>

              <div className="pt-3">
                <p className="text-xs font-medium uppercase tracking-wide text-gray-500 mb-2">Supported</p>
                <div className="flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-2 py-2 w-fit">
                  <span className="inline-flex items-center justify-center w-10 h-7 bg-gray-50 rounded border border-gray-200">
                    <img src="/images/payment-logo/visa.png" alt="Visa" className="block h-full w-full object-contain" />
                  </span>
                  <span className="inline-flex items-center justify-center w-10 h-7 bg-gray-50 rounded border border-gray-200">
                    <img src="/images/payment-logo/GCASH.png" alt="GCash" className="block h-full w-full object-contain" />
                  </span>
                  <span className="inline-flex items-center justify-center w-10 h-7 bg-gray-50 rounded border border-gray-200">
                    <img src="/images/payment-logo/MAYA.png" alt="Maya" className="block h-full w-full object-contain" />
                  </span>
                </div>
              </div>

            </div>

            {showPolicyAcceptanceCard && (
              <div className="bg-white px-4 py-4 border-y border-gray-200 text-sm text-gray-700">
                <div className="rounded-lg border border-gray-300 bg-gray-50 px-3 py-3">
                  <div className="flex items-start gap-3">
                    <input
                      id="payment-policy-acceptance-mobile"
                      type="checkbox"
                      checked={policyAccepted}
                      disabled={isLoadingPolicy || !isPolicyAcceptanceRequired}
                      onChange={(event) => {
                        void handlePolicyAcceptanceToggle(event.target.checked);
                      }}
                      className="mt-1 h-4 w-4"
                    />
                    <div>
                      <label htmlFor="payment-policy-acceptance-mobile" className="text-sm font-medium text-black">
                        I have read and accept this shop&apos;s Terms and Conditions.
                      </label>
                      <p className="mt-1 text-xs text-gray-600">{policyStatusText}</p>
                      <button
                        type="button"
                        onClick={() => {
                          void handlePolicyAcceptanceToggle(true);
                        }}
                        disabled={isLoadingPolicy || !isPolicyAcceptanceRequired}
                        className="mt-2 text-xs font-semibold text-black underline disabled:cursor-not-allowed disabled:text-gray-400"
                      >
                        {isLoadingPolicy ? 'Loading terms...' : 'Read Terms and Conditions'}
                      </button>
                    </div>
                  </div>
                </div>
              </div>
            )}

            {payError && (
              <div className="mt-2 mx-4 p-4 bg-red-50 border border-red-200 rounded text-red-600 text-sm">
                {payError}
              </div>
            )}

            {isAddressSheetOpen && createPortal((
              <div
                role="dialog"
                aria-modal="true"
                aria-labelledby="address-sheet-title"
                className="fixed inset-0 z-[100001] overflow-y-auto bg-white xl:left-auto xl:w-[min(42rem,100%)] xl:border-l xl:border-gray-200 xl:shadow-2xl"
              >
                <div className="sticky top-0 z-10 bg-white border-b border-gray-200 px-4 py-4 flex items-center justify-between">
                  <button
                    type="button"
                    onClick={() => {
                      if (addressSheetMode === 'form') {
                        restoreSelectedAddress();
                        setEditingAddressId(null);
                        setAddressSheetMode('list');
                        return;
                      }
                      setIsAddressSheetOpen(false);
                    }}
                    className="text-black text-2xl leading-none"
                    aria-label="Close address selector"
                  >
                    &larr;
                  </button>
                  <h2 id="address-sheet-title" className="text-2xl font-semibold text-black">{addressSheetMode === 'form' ? (editingAddressId ? 'Edit address' : 'Add address') : 'Your addresses'}</h2>
                  <div className="w-6" />
                </div>

                {addressSaveStatus && (
                  <p aria-live="polite" className="border-b border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-700">
                    {addressSaveStatus}
                  </p>
                )}

                {addressSheetMode === 'list' ? (
                  <>
                    <button
                      type="button"
                      onClick={() => {
                        setEditingAddressId(null);
                        setAddressSaveStatus(null);
                        setSetAsDefaultAddress(false);
                        setAddressSheetMode('form');
                      }}
                      className="w-full px-4 py-5 border-b border-gray-200 flex items-center justify-between text-left"
                    >
                      <span className="text-2xl text-gray-500 leading-none">+</span>
                      <span className="ml-3 flex-1 text-2xl font-medium text-black">Add address</span>
                      <span className="text-2xl text-gray-400 leading-none">&rsaquo;</span>
                    </button>

                    {isAddressLoading ? (
                      <div className="px-4 py-6 text-base text-gray-600">Loading addresses...</div>
                    ) : userAddresses.length === 0 ? (
                      <div className="px-4 py-6 text-base text-gray-600">
                        No saved addresses yet. Tap "Add address" to add one.
                      </div>
                    ) : (
                      <div>
                        {userAddresses.map((addr) => {
                          const isSelected = checkoutData?.address_id === addr.id;
                          return (
                            <div
                              key={addr.id}
                              className={`w-full px-4 py-5 border-b border-gray-200 ${isSelected ? 'bg-gray-50' : 'bg-white'}`}
                            >
                              <div className="flex items-start justify-between gap-2">
                                <button
                                  type="button"
                                  onClick={() => {
                                    applySelectedAddress(addr);
                                    setIsAddressSheetOpen(false);
                                  }}
                                  className="min-w-0 text-left flex-1"
                                >
                                  <p className="text-2xl font-semibold text-black">{addr.name || 'Unnamed address'}</p>
                                  <p className="text-base text-gray-700 mt-1">{addr.phone || 'No phone'}</p>
                                  <p className="text-base text-gray-700 mt-2 leading-relaxed">{formatAddressDisplay(addr)}</p>
                                  {addr.is_default && (
                                    <span className="inline-flex mt-3 rounded bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700">Default</span>
                                  )}
                                </button>
                                <button
                                  type="button"
                                  onClick={(e) => {
                                    e.stopPropagation();
                                    handleEditAddressFromList(addr);
                                  }}
                                  className="text-gray-900 text-base font-semibold shrink-0 hover:text-black"
                                >
                                  Edit
                                </button>
                                {(addr.latitude == null || addr.longitude == null) && (
                                  <button
                                    type="button"
                                    onClick={(e) => {
                                      e.stopPropagation();
                                      handleEditAddressFromList(addr, true);
                                    }}
                                    className="shrink-0 text-sm font-semibold text-blue-700 underline"
                                  >
                                    Repin address
                                  </button>
                                )}
                              </div>
                            </div>
                          );
                        })}
                      </div>
                    )}
                  </>
                ) : (
                  <div className="px-4 py-5 space-y-3">
                    <input
                      type="email"
                      placeholder="Email"
                      value={customerEmail}
                      onChange={e => setCustomerEmail(e.target.value)}
                      className="w-full px-4 py-3 border border-gray-300 rounded text-black bg-white"
                    />
                    <input
                      type="text"
                      placeholder="Full name"
                      value={customerName}
                      onChange={e => setCustomerName(e.target.value)}
                      className="w-full px-4 py-3 border border-gray-300 rounded text-black bg-white"
                    />
                    <input
                      type="text"
                      placeholder="House No., Street, Subdivision / Building"
                      value={shippingAddressLine}
                      onChange={e => setShippingAddressLine(e.target.value)}
                      className="w-full px-4 py-3 border border-gray-300 rounded text-black bg-white"
                    />
                    <input
                      type="text"
                      placeholder="Barangay, Landmarks, Optional (LBC Branch)"
                      value={shippingBarangay}
                      onChange={e => setShippingBarangay(e.target.value)}
                      className="w-full px-4 py-3 border border-gray-300 rounded text-black bg-white"
                    />
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                      <div ref={sheetProvinceDropdownRef} className="relative">
                        <button
                          type="button"
                          onClick={() => setIsSheetProvinceDropdownOpen((prev) => !prev)}
                          onKeyDown={(event) => handleDropdownTriggerKeyDown(event, setIsSheetProvinceDropdownOpen, sheetProvinceDropdownRef)}
                          className="flex w-full items-center justify-between rounded border border-gray-300 bg-white px-4 py-3 text-left"
                          aria-label="Province"
                          aria-haspopup="listbox"
                          aria-expanded={isSheetProvinceDropdownOpen}
                        >
                          <span className={shippingRegion ? 'text-black' : 'text-gray-500'}>{shippingRegion || 'Select Province'}</span>
                          <span className={`text-gray-500 transition-transform ${isSheetProvinceDropdownOpen ? 'rotate-180' : ''}`}>▾</span>
                        </button>
                        {isSheetProvinceDropdownOpen && (
                          <div
                            role="listbox"
                            onKeyDown={(event) => handleListboxKeyDown(event, setIsSheetProvinceDropdownOpen, sheetProvinceDropdownRef)}
                            className="hide-scrollbar absolute left-0 right-0 top-full z-40 mt-1 max-h-56 overflow-y-auto rounded border border-gray-300 bg-white shadow-lg"
                          >
                            {PHILIPPINE_LOCATIONS.map((province) => (
                              <button
                                key={province.name}
                                type="button"
                                role="option"
                                aria-selected={shippingRegion === province.name}
                                onClick={() => handleProvinceChange(province.name)}
                                className={`w-full border-b border-gray-100 px-4 py-2 text-left text-sm hover:bg-gray-50 last:border-b-0 ${shippingRegion === province.name ? 'bg-gray-50 font-medium text-black' : 'text-black'}`}
                              >
                                {province.name}
                              </button>
                            ))}
                          </div>
                        )}
                      </div>
                      <div ref={sheetCityDropdownRef} className="relative">
                        <button
                          type="button"
                          onClick={() => setIsSheetCityDropdownOpen((prev) => !prev)}
                          onKeyDown={(event) => handleDropdownTriggerKeyDown(event, setIsSheetCityDropdownOpen, sheetCityDropdownRef)}
                          disabled={!shippingRegion}
                          className="flex w-full items-center justify-between rounded border border-gray-300 bg-white px-4 py-3 text-left disabled:cursor-not-allowed disabled:bg-gray-100 disabled:text-gray-400"
                          title="City/Municipality"
                          aria-label="City/Municipality"
                          aria-haspopup="listbox"
                          aria-expanded={isSheetCityDropdownOpen}
                        >
                          <span className={shippingCity ? 'text-black' : 'text-gray-500'}>{shippingCity || 'Select City/Municipality'}</span>
                          <span className={`text-gray-500 transition-transform ${isSheetCityDropdownOpen ? 'rotate-180' : ''}`}>▾</span>
                        </button>

                        {isSheetCityDropdownOpen && (
                          <div
                            role="listbox"
                            onKeyDown={(event) => handleListboxKeyDown(event, setIsSheetCityDropdownOpen, sheetCityDropdownRef)}
                            className="hide-scrollbar absolute left-0 right-0 top-full z-40 mt-1 max-h-56 overflow-y-auto rounded border border-gray-300 bg-white shadow-lg"
                          >
                            <button
                              type="button"
                              role="option"
                              aria-selected={!shippingCity}
                              onClick={() => handleCityChange('')}
                              className="w-full border-b border-gray-100 px-4 py-2 text-left text-sm text-gray-600 hover:bg-gray-50"
                            >
                              Select City/Municipality
                            </button>
                            {cityMunicipalityOptions.map((city) => (
                              <button
                                key={city}
                                type="button"
                                role="option"
                                aria-selected={shippingCity === city}
                                onClick={() => handleCityChange(city)}
                                className={`w-full border-b border-gray-100 px-4 py-2 text-left text-sm hover:bg-gray-50 last:border-b-0 ${shippingCity === city ? 'bg-gray-50 font-medium text-black' : 'text-black'}`}
                              >
                                {city}
                              </button>
                            ))}
                          </div>
                        )}
                      </div>
                      <input
                        type="text"
                        placeholder="Postal code"
                        value={shippingPostalCode}
                        onChange={e => handlePostalCodeChange(e.target.value, setShippingPostalCode)}
                        className="w-full px-4 py-3 border border-gray-300 rounded text-black bg-white"
                      />
                    </div>
                    <input
                      type="tel"
                      placeholder="Phone"
                      value={customerPhone}
                      onChange={e => handlePhoneChange(e.target.value)}
                      inputMode="numeric"
                      pattern="[0-9]*"
                      maxLength={11}
                      className="w-full px-4 py-3 border border-gray-300 rounded text-black bg-white"
                    />

                    <div ref={addressMapRef}>
                      <p className="mb-2 text-sm text-gray-600">
                        Pin the exact delivery entrance, then check the address details above.
                      </p>
                      <CustomerAddressMapPicker
                        value={shippingLatitude !== null && shippingLongitude !== null
                          ? { latitude: shippingLatitude, longitude: shippingLongitude }
                          : null}
                        onChange={handleAddressMapChange}
                      />
                    </div>

                    <button
                      type="button"
                      onClick={handleUseAddressFromForm}
                      disabled={isAddressSaving}
                      className="w-full mt-2 rounded-lg bg-gray-900 py-3 text-base font-semibold text-white disabled:cursor-not-allowed disabled:bg-gray-400"
                    >
                      {isAddressSaving ? 'Saving address...' : editingAddressId ? 'Save changes' : 'Use this address'}
                    </button>

                    {editingAddressId && (
                      <button
                        type="button"
                        onClick={handleDeleteAddressFromForm}
                        className="w-full mt-2 rounded-lg border border-red-200 bg-red-50 py-3 text-base font-semibold text-red-700"
                      >
                        Delete address
                      </button>
                    )}
                  </div>
                )}

              </div>
            ), document.body)}
          </div>

          <div className="hidden xl:grid grid-cols-1 md:grid-cols-3 gap-6 items-start">
            {/* Left: Payment Form (span 2 on md) */}
            <div className="md:col-span-2">
              {/* Contact Section */}
              <div className="mb-10">
                <div className="flex items-center justify-between mb-6">
                  <h2 className="text-xl font-bold text-black">Contact</h2>
                </div>
                <input
                  type="email"
                  placeholder="Email"
                  value={customerEmail}
                  onChange={e => setCustomerEmail(e.target.value)}
                  className="w-full px-4 py-3 border border-gray-300 rounded text-black bg-white text-base"
                />
                <label className="mt-6 flex items-center gap-3">
                  <input type="checkbox" defaultChecked className="w-4 h-4" />
                  <span className="text-sm text-black">Email me with news and offers</span>
                </label>
              </div>

              {/* Delivery Section */}
              <div className="mb-10">
                {!isPremiumPayment && (
                  <div className="mb-5 flex items-center justify-between gap-4">
                    <h2 className="text-xl font-bold text-black">Delivery</h2>
                    <button
                      type="button"
                      onClick={openAddressSheet}
                      className="rounded-md border border-gray-300 px-3 py-2 text-sm font-semibold text-black transition hover:bg-gray-50"
                    >
                      Manage addresses
                    </button>
                  </div>
                )}
                <div className="space-y-5">
                  {/* First Name & Last Name */}
                  <div className="grid grid-cols-2 gap-5">
                    <div>
                      <label className="block text-sm font-medium text-black mb-2.5">First name</label>
                      <input
                        type="text"
                        placeholder="First name"
                        value={customerName.split(' ')[0]}
                        onChange={e => setCustomerName(e.target.value + (customerName.split(' ').slice(1).join(' ') ? ' ' + customerName.split(' ').slice(1).join(' ') : ''))}
                        className="w-full px-4 py-3 border border-gray-300 rounded text-black bg-white text-base"
                      />
                    </div>
                    <div>
                      <label className="block text-sm font-medium text-black mb-2.5">Last name</label>
                      <input
                        type="text"
                        placeholder="Last name"
                        value={customerName.split(' ').slice(1).join(' ')}
                        onChange={e => setCustomerName(customerName.split(' ')[0] + (e.target.value ? ' ' + e.target.value : ''))}
                        className="w-full px-4 py-3 border border-gray-300 rounded text-black bg-white text-base"
                      />
                    </div>
                  </div>

                  {/* Address Line */}
                  {!isPremiumPayment && (
                    <div>
                      <label className="block text-sm font-medium text-black mb-2.5">House No., Street, Subdivision / Building</label>
                      <input
                        type="text"
                        placeholder="House No., Street, Subdivision / Building"
                        value={shippingAddressLine}
                        onChange={e => setShippingAddressLine(e.target.value)}
                        className="w-full px-4 py-3 border border-gray-300 rounded text-black bg-white text-base"
                      />
                    </div>
                  )}

                  {/* Barangay */}
                  {!isPremiumPayment && (
                    <div>
                      <label className="block text-sm font-medium text-black mb-2.5">Barangay, Landmarks, Optional (LBC Branch)</label>
                      <input
                        type="text"
                        placeholder="Barangay, Landmarks, Optional (LBC Branch)"
                        value={shippingBarangay}
                        onChange={e => setShippingBarangay(e.target.value)}
                        className="w-full px-4 py-3 border border-gray-300 rounded text-black bg-white text-base"
                      />
                    </div>
                  )}

                  {/* Province, city/municipality, and postal code */}
                  {!isPremiumPayment && (
                    <>
                      <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
                        <div>
                          <label className="block text-sm font-medium text-black mb-2.5">Province</label>
                          <div ref={desktopProvinceDropdownRef} className="relative">
                            <button
                              type="button"
                              onClick={() => setIsDesktopProvinceDropdownOpen((prev) => !prev)}
                              onKeyDown={(event) => handleDropdownTriggerKeyDown(event, setIsDesktopProvinceDropdownOpen, desktopProvinceDropdownRef)}
                              className="flex w-full items-center justify-between rounded border border-gray-300 bg-white px-4 py-3 text-left text-base"
                              aria-label="Province"
                              aria-haspopup="listbox"
                              aria-expanded={isDesktopProvinceDropdownOpen}
                            >
                              <span className={shippingRegion ? 'text-black' : 'text-gray-500'}>{shippingRegion || 'Select Province'}</span>
                              <span className={`text-gray-500 transition-transform ${isDesktopProvinceDropdownOpen ? 'rotate-180' : ''}`}>▾</span>
                            </button>
                            {isDesktopProvinceDropdownOpen && (
                              <div
                                role="listbox"
                                onKeyDown={(event) => handleListboxKeyDown(event, setIsDesktopProvinceDropdownOpen, desktopProvinceDropdownRef)}
                                className="hide-scrollbar absolute left-0 right-0 top-full z-40 mt-1 max-h-56 overflow-y-auto rounded border border-gray-300 bg-white shadow-lg"
                              >
                                {PHILIPPINE_LOCATIONS.map((province) => (
                                  <button
                                    key={province.name}
                                    type="button"
                                    role="option"
                                    aria-selected={shippingRegion === province.name}
                                    onClick={() => handleProvinceChange(province.name)}
                                    className={`w-full border-b border-gray-100 px-4 py-2 text-left text-sm hover:bg-gray-50 last:border-b-0 ${shippingRegion === province.name ? 'bg-gray-50 font-medium text-black' : 'text-black'}`}
                                  >
                                    {province.name}
                                  </button>
                                ))}
                              </div>
                            )}
                          </div>
                        </div>
                        <div>
                          <label className="block text-sm font-medium text-black mb-2.5">City/Municipality</label>
                          <div ref={desktopCityDropdownRef} className="relative">
                            <button
                              type="button"
                              onClick={() => setIsDesktopCityDropdownOpen((prev) => !prev)}
                              onKeyDown={(event) => handleDropdownTriggerKeyDown(event, setIsDesktopCityDropdownOpen, desktopCityDropdownRef)}
                              disabled={!shippingRegion}
                              className="flex w-full items-center justify-between rounded border border-gray-300 bg-white px-4 py-3 text-left text-base disabled:cursor-not-allowed disabled:bg-gray-100 disabled:text-gray-400"
                              title="City/Municipality"
                              aria-label="City/Municipality"
                              aria-haspopup="listbox"
                              aria-expanded={isDesktopCityDropdownOpen}
                            >
                              <span className={shippingCity ? 'text-black' : 'text-gray-500'}>{shippingCity || 'Select City/Municipality'}</span>
                              <span className={`text-gray-500 transition-transform ${isDesktopCityDropdownOpen ? 'rotate-180' : ''}`}>▾</span>
                            </button>

                            {isDesktopCityDropdownOpen && (
                              <div
                                role="listbox"
                                onKeyDown={(event) => handleListboxKeyDown(event, setIsDesktopCityDropdownOpen, desktopCityDropdownRef)}
                                className="hide-scrollbar absolute left-0 right-0 top-full z-40 mt-1 max-h-56 overflow-y-auto rounded border border-gray-300 bg-white shadow-lg"
                              >
                                <button
                                  type="button"
                                  role="option"
                                  aria-selected={!shippingCity}
                                  onClick={() => handleCityChange('')}
                                  className="w-full border-b border-gray-100 px-4 py-2 text-left text-sm text-gray-600 hover:bg-gray-50"
                                >
                                  Select City/Municipality
                                </button>
                                {cityMunicipalityOptions.map((city) => (
                                  <button
                                    key={city}
                                    type="button"
                                    role="option"
                                    aria-selected={shippingCity === city}
                                    onClick={() => handleCityChange(city)}
                                    className={`w-full border-b border-gray-100 px-4 py-2 text-left text-sm hover:bg-gray-50 last:border-b-0 ${shippingCity === city ? 'bg-gray-50 font-medium text-black' : 'text-black'}`}
                                  >
                                    {city}
                                  </button>
                                ))}
                              </div>
                            )}
                          </div>
                        </div>
                        <div>
                          <label className="block text-sm font-medium text-black mb-2.5">Postal code</label>
                          <input
                            type="text"
                            placeholder="Postal code"
                            inputMode="numeric"
                            value={shippingPostalCode}
                            onChange={e => handlePostalCodeChange(e.target.value, setShippingPostalCode)}
                            className="w-full px-4 py-3 border border-gray-300 rounded text-black bg-white text-base"
                          />
                        </div>
                      </div>
                    </>
                  )}

                  {!isPremiumPayment && (
                    <div>
                      <p className="mb-2 text-sm text-gray-600">
                        Pin the exact delivery entrance, then check the address details above.
                      </p>
                      <CustomerAddressMapPicker
                        value={shippingLatitude !== null && shippingLongitude !== null
                          ? { latitude: shippingLatitude, longitude: shippingLongitude }
                          : null}
                        onChange={handleAddressMapChange}
                      />
                    </div>
                  )}

                  {/* Phone */}
                  <div>
                    <label className="block text-sm font-medium text-black mb-2.5">Phone</label>
                    <input
                      type="tel"
                      placeholder="Phone"
                      value={customerPhone}
                      onChange={e => handlePhoneChange(e.target.value)}
                      inputMode="numeric"
                      pattern="[0-9]*"
                      maxLength={11}
                      className="w-full px-4 py-3 border border-gray-300 rounded text-black bg-white text-base"
                    />
                  </div>

                  {/* Delivery address persistence */}
                  <div className="pt-2">
                    <p className="text-sm text-gray-600">
                      Your delivery address will be saved or updated for order fulfillment.
                    </p>
                    <label className="mt-3 flex items-center gap-3">
                      <input
                        type="checkbox"
                        title="Set as my default delivery address"
                        aria-label="Set as my default delivery address"
                        checked={setAsDefaultAddress}
                        onChange={e => setSetAsDefaultAddress(e.target.checked)}
                        className="w-5 h-5"
                      />
                      <span className="text-base text-black">Set as my default delivery address</span>
                    </label>
                  </div>

                  {/* Text notification */}
                  <label className="flex items-center gap-3 pt-1">
                    <input type="checkbox" title="Text me with news and offers" aria-label="Text me with news and offers" className="w-5 h-5" />
                    <span className="text-base text-black">Text me with news and offers</span>
                  </label>
                </div>
              </div>

              {/* Payment Section */}
              <div className="mb-12">
                <h3 className="text-xl font-bold text-black mb-5">Payment</h3>
                <p className="text-sm text-gray-700 mb-6">All transactions are secure and encrypted.</p>

                {/* Online Payment Option Box */}
                <label className="flex items-center gap-3 px-4 py-3 border border-gray-300 rounded-lg cursor-pointer mb-4 bg-white">
                  <input
                    type="radio"
                    name="payment-method"
                    value="paymongo"
                    checked={selectedPaymentMethod === 'paymongo'}
                    onChange={() => setSelectedPaymentMethod('paymongo')}
                    className="w-5 h-5 shrink-0"
                  />
                  <div className="flex-1">
                    <span className="text-base font-semibold text-black block">Online Payment</span>
                    <p className="text-sm text-gray-600">Pay securely via PayMongo card, e-wallet or installment.</p>
                  </div>
                </label>

                {/* Secure Payments Box */}
                <div className="border border-gray-300 rounded-lg overflow-hidden">
                  <div className="bg-white px-4 py-3 border-b border-gray-200">
                    <div className="flex items-center justify-between">
                      <span className="text-sm font-semibold text-black">Secure Payments via PayMongo</span>
                      <div className="flex items-center gap-2">
                        {/* Visa */}
                        <span className="inline-flex items-center justify-center w-10 h-6 rounded border border-gray-300 bg-white">
                          <img src="/images/payment-logo/visa.png" alt="Visa" className="block h-full w-full object-contain" />
                        </span>
                        {/* GCash */}
                        <span className="inline-flex items-center justify-center w-10 h-6 rounded border border-gray-300 bg-white">
                          <img src="/images/payment-logo/GCASH.png" alt="GCash" className="block h-full w-full object-contain" />
                        </span>
                        {/* Maya */}
                        <span className="inline-flex items-center justify-center w-10 h-6 rounded border border-gray-300 bg-white">
                          <img src="/images/payment-logo/MAYA.png" alt="Maya" className="block h-full w-full object-contain" />
                        </span>
                      </div>
                    </div>
                  </div>

                  {/* Redirection Message */}
                  <div className="bg-blue-50 px-4 py-3 text-center">
                    <p className="text-sm text-gray-900">You'll be redirected to Secure Payments via PayMongo to complete your purchase.</p>
                  </div>
                </div>
              </div>

              {showPolicyAcceptanceCard && (
                <div className="mb-8 rounded-lg border border-gray-300 bg-gray-50 px-4 py-4">
                  <div className="flex items-start gap-3">
                    <input
                      id="payment-policy-acceptance-desktop"
                      type="checkbox"
                      checked={policyAccepted}
                      disabled={isLoadingPolicy || !isPolicyAcceptanceRequired}
                      onChange={(event) => {
                        void handlePolicyAcceptanceToggle(event.target.checked);
                      }}
                      className="mt-1 h-4 w-4"
                    />
                    <div>
                      <label htmlFor="payment-policy-acceptance-desktop" className="text-sm font-medium text-black">
                        I have read and accept this shop&apos;s Terms and Conditions.
                      </label>
                      <p className="mt-1 text-xs text-gray-600">{policyStatusText}</p>
                      <button
                        type="button"
                        onClick={() => {
                          void handlePolicyAcceptanceToggle(true);
                        }}
                        disabled={isLoadingPolicy || !isPolicyAcceptanceRequired}
                        className="mt-2 text-xs font-semibold text-black underline disabled:cursor-not-allowed disabled:text-gray-400"
                      >
                        {isLoadingPolicy ? 'Loading terms...' : 'Read Terms and Conditions'}
                      </button>
                    </div>
                  </div>
                </div>
              )}

              {/* Pay Now Button */}
              <button
                onClick={handlePayNow}
                disabled={isProcessing || (isPolicyAcceptanceRequired && !policyAccepted)}
                className={`w-full py-3 rounded-xl font-bold text-white mb-8 transition-colors text-base ${
                  (isProcessing || (isPolicyAcceptanceRequired && !policyAccepted)) ? 'bg-gray-400 cursor-not-allowed' : 'bg-gray-900 hover:bg-gray-800'
                }`}
              >
                {isProcessing ? 'Processing...' : 'Pay now'}
              </button>

              {payError && (
                <div className="p-4 bg-red-50 border border-red-200 rounded text-red-600 text-sm mb-4">
                  {payError}
                </div>
              )}

              {/* Footer Links */}
              <div className="flex gap-6 justify-center text-xs text-gray-600 border-t border-gray-200 pt-6">
                <a href="#" className="underline">Refund policy</a>
                <a href="#" className="underline">Privacy policy</a>
                <a href="#" className="underline">Terms of service</a>
                <a href="#" className="underline">Cancellations</a>
                <a href="#" className="underline">Contact</a>
              </div>
            </div>

            {/* Right: Order Summary (sticky on md) */}
            <aside className="md:col-span-1 md:sticky md:top-4">
              <div className="border border-gray-300 rounded-lg p-4 bg-white">
                {/* Product Items */}
                <div className="border-b border-gray-200 pb-4 mb-4">
                  {checkoutData.items.map((item) => (
                    <div key={item.id} className="flex gap-4 mb-4 last:mb-0">
                      <div className="w-14 h-14 bg-gray-100 rounded overflow-hidden shrink-0">
                        {item.image ? (
                          <img src={item.image} alt={item.name} className="w-full h-full object-cover" />
                        ) : isPremiumPayment ? (
                          <div className="flex h-full w-full items-center justify-center bg-slate-900 text-[10px] font-semibold tracking-wide text-white">
                            SOLESPACE
                          </div>
                        ) : null}
                      </div>
                      <div className="flex-1 min-w-0">
                        <p className="text-sm font-medium text-black truncate">{item.name}</p>
                        <p className="text-xs text-gray-600 mt-1">Qty: {item.qty}</p>
                        {item.size && <p className="text-xs text-gray-600">Size: {item.size}</p>}
                        {item.color && <p className="text-xs text-gray-600">Color: {item.color}</p>}
                      </div>
                      <div className="text-right">
                        <p className="text-sm font-semibold text-black">₱{(item.price * item.qty).toLocaleString()}</p>
                      </div>
                    </div>
                  ))}
                </div>

                {/* Auto-applied voucher */}
                <div className="mb-4 rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm">
                  {isPromoPreviewLoading ? (
                    <p className="text-gray-600">Checking claimed vouchers...</p>
                  ) : (
                    <div className="space-y-2">
                      <label className="block text-xs font-semibold uppercase tracking-wide text-gray-700">
                        Voucher
                      </label>
                      <p className="text-xs text-gray-600">Type a voucher code or choose from suggestions.</p>
                      <div ref={voucherInputContainerRef} className="relative">
                        <div className="flex items-center gap-2">
                          <input
                            type="text"
                            aria-label="Voucher code"
                            aria-expanded={showVoucherSuggestionDropdown}
                            value={voucherCodeInput}
                            onFocus={() => setIsVoucherSuggestionOpen(true)}
                            onClick={() => setIsVoucherSuggestionOpen(true)}
                            onChange={(e) => {
                              const nextVoucherCode = e.target.value.toUpperCase();
                              const normalizedNextVoucherCode = normalizeVoucherCode(nextVoucherCode);

                              setSelectedVoucherCampaignId(null);
                              setHasVoucherInputInteraction(true);
                              setVoucherCodeInput(nextVoucherCode);

                              if (normalizedNextVoucherCode === '') {
                                setAppliedVoucherCode('');
                                setIsVoucherSelectionEnabled(false);
                              }

                              setIsVoucherSuggestionOpen(true);
                            }}
                            onKeyDown={(e) => {
                              if (e.key === 'Enter') {
                                e.preventDefault();
                                handleApplyVoucherCode();
                              }

                              if (e.key === 'Escape') {
                                setIsVoucherSuggestionOpen(false);
                              }
                            }}
                            placeholder="Enter voucher code"
                            className="min-w-0 flex-1 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-black"
                          />
                          <button
                            type="button"
                            onClick={handleApplyVoucherCode}
                            className="min-h-11 shrink-0 rounded-md bg-gray-900 px-3 py-2 text-xs font-semibold text-white hover:bg-black focus:outline-none focus:ring-2 focus:ring-gray-900/20"
                          >
                            Apply
                          </button>
                        </div>

                        {showVoucherSuggestionDropdown && (
                          <div
                            role="listbox"
                            aria-label="Voucher suggestions"
                            onKeyDown={(event) => {
                              if (event.key === 'Escape') {
                                event.preventDefault();
                                setIsVoucherSuggestionOpen(false);
                              }
                            }}
                            className="absolute left-0 right-0 top-full z-30 mt-2 max-h-[min(32rem,calc(100vh-12rem))] overflow-y-auto rounded-xl border border-gray-200 bg-white p-2 shadow-md"
                          >
                            <div className="space-y-2">
                              {filteredVoucherCodeSuggestions.length > 0 ? (
                                filteredVoucherCodeSuggestions.map((voucher) => {
                                  const displayName = voucher.name || voucher.code || 'Voucher';
                                  const displayCode = normalizeVoucherCode(String(voucher.code || voucher.name || ''));
                                  const isEligible = voucher.eligibility === 'eligible';
                                  const canUseVoucher = voucher.claim_status === 'claimed' && isEligible;
                                  const isClaiming = claimingVoucherCampaignId === voucher.id;
                                  const minimumSpend = toFiniteNumber(voucher.min_spend);
                                  const eligibleSubtotal = toFiniteNumber(voucher.eligible_subtotal);
                                  const remainingSpend = toFiniteNumber(voucher.remaining_spend);
                                  const spendProgress = minimumSpend > 0
                                    ? Math.min(100, (eligibleSubtotal / minimumSpend) * 100)
                                    : 100;

                                  return (
                                    <div
                                      key={voucher.id}
                                      role="option"
                                      tabIndex={voucher.claim_status === 'redeemed' ? -1 : 0}
                                      aria-selected={selectedVoucherCampaignId === voucher.id}
                                      aria-disabled={!canUseVoucher && !voucher.can_claim}
                                      onKeyDown={(event) => {
                                        if ((event.key === 'Enter' || event.key === ' ') && canUseVoucher) {
                                          event.preventDefault();
                                          handleUseVoucher(voucher);
                                        }
                                      }}
                                      className={`relative overflow-hidden rounded-lg border text-left transition-colors focus:outline-none focus:ring-2 focus:ring-gray-900/20 ${
                                        selectedVoucherCampaignId === voucher.id
                                          ? 'border-gray-900 bg-gray-50'
                                          : 'border-gray-200 bg-white hover:border-gray-400'
                                      }`}
                                    >
                                      <div className="grid grid-cols-[4.5rem_minmax(0,1fr)_5.5rem] items-stretch">
                                        <div className="flex min-h-[10rem] flex-col items-center justify-center border-r border-dashed border-gray-200 bg-gray-50 px-2 py-3 text-center">
                                          <div
                                            className="flex h-14 w-14 items-center justify-center rounded-full border border-gray-200 bg-white text-xl font-semibold text-gray-900 sm:h-20 sm:w-20 sm:text-2xl"
                                            aria-hidden="true"
                                          >
                                            %
                                          </div>
                                          <span className="mt-2 rounded-md bg-[#d30005] px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-white sm:text-xs">
                                            {voucher.target === 'shipping' ? 'Shipping' : 'Shop'}
                                          </span>
                                        </div>

                                        <div className="min-w-0 px-2.5 py-3">
                                        <div className="flex flex-wrap items-start justify-between gap-2">
                                          <div className="min-w-0">
                                            <p className="truncate text-sm font-semibold text-black">{displayName}</p>
                                            {displayCode && normalizeVoucherCode(displayName) !== displayCode && (
                                              <p className="mt-0.5 truncate font-mono text-[11px] font-semibold tracking-wide text-gray-500">{displayCode}</p>
                                            )}
                                          </div>
                                          <span className={`shrink-0 rounded-full border px-2 py-1 text-[10px] font-semibold uppercase tracking-wide ${voucherClaimStatusClass(voucher.claim_status)}`}>
                                            {voucherClaimStatusLabel(voucher.claim_status)}
                                          </span>
                                        </div>

                                        <p className="mt-2 text-lg font-semibold leading-tight text-gray-900">
                                          {formatVoucherBenefit(voucher)}
                                        </p>

                                        {minimumSpend > 0 && (
                                          <>
                                            <p className="mt-1 text-sm text-gray-700">Min. spend {formatVoucherMoney(minimumSpend)}</p>
                                            <div className="mt-2 rounded-md bg-gray-50 px-2.5 py-2">
                                              <div className="flex items-center justify-between gap-2 text-[11px] text-gray-600">
                                                <span>{formatVoucherMoney(eligibleSubtotal)} eligible</span>
                                                <span>{remainingSpend > 0 ? `${formatVoucherMoney(remainingSpend)} more` : 'Requirement met'}</span>
                                              </div>
                                              <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-gray-200" aria-hidden="true">
                                                <div className="h-full rounded-full bg-[#d30005]" style={{ width: `${spendProgress}%` }} />
                                              </div>
                                            </div>
                                            <p className={`mt-2 text-xs font-medium ${remainingSpend > 0 ? 'text-amber-700' : 'text-emerald-700'}`}>
                                              {remainingSpend > 0 ? `Add ${formatVoucherMoney(remainingSpend)} more to unlock this voucher.` : 'Eligible for this order.'}
                                            </p>
                                          </>
                                        )}

                                        <div className="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-500">
                                          <span>{voucher.target === 'shipping' ? 'Shipping' : 'Items'}</span>
                                          <span aria-hidden="true">·</span>
                                          <span>{voucher.scope === 'shop_wide' ? 'Shop-wide' : 'Selected products'}</span>
                                          <span aria-hidden="true">·</span>
                                          <span>T&amp;C apply</span>
                                        </div>

                                        <p className={`mt-2 text-xs font-medium ${voucherEligibilityClass(voucher.eligibility)}`}>
                                          {voucher.eligibility_message}
                                        </p>
                                        </div>

                                        <div className="flex min-w-0 flex-col items-stretch justify-center gap-2 border-l border-gray-100 px-2 py-3">
                                        {isClaiming ? (
                                            <button type="button" disabled className="min-h-11 w-full whitespace-nowrap rounded-md bg-gray-200 px-1 text-[11px] font-semibold text-gray-500">
                                            Claiming…
                                          </button>
                                        ) : canUseVoucher ? (
                                          <button
                                            type="button"
                                            onClick={() => handleUseVoucher(voucher)}
                                              className="min-h-11 w-full whitespace-nowrap rounded-md bg-[#d30005] px-1 text-[11px] font-semibold text-white hover:bg-[#780700] focus:outline-none focus:ring-2 focus:ring-[#d30005]/30"
                                          >
                                            Use voucher
                                          </button>
                                        ) : voucher.claim_status === 'claimable' && voucher.can_claim ? (
                                          <button
                                            type="button"
                                            onClick={() => void handleClaimVoucher(voucher, isEligible)}
                                              className="min-h-11 w-full whitespace-nowrap rounded-md bg-[#d30005] px-1 text-[11px] font-semibold text-white hover:bg-[#780700] focus:outline-none focus:ring-2 focus:ring-[#d30005]/30"
                                          >
                                            {isEligible ? 'Claim & use' : 'Claim for later'}
                                          </button>
                                        ) : (
                                          <span className="text-right text-[11px] font-semibold text-gray-500">
                                            {voucher.claim_status === 'redeemed' ? 'Already used' : voucher.claim_status === 'claimed' ? 'Claimed' : 'Not available'}
                                          </span>
                                        )}
                                        </div>
                                      </div>

                                      {minimumSpend > 0 && remainingSpend > 0 && (
                                        <div className="border-t border-gray-200 px-4 py-3 text-base font-medium text-gray-900 sm:px-8 sm:text-lg">
                                          Add {formatVoucherMoney(remainingSpend)}, to get {formatVoucherBenefit(voucher).replace(/\s+(shipping|items)$/i, '')}
                                        </div>
                                      )}
                                    </div>
                                  );
                                })
                              ) : (
                                <div className="rounded-lg border border-dashed border-gray-200 px-3 py-4 text-center text-sm text-gray-500">
                                  No matching vouchers
                                </div>
                              )}
                            </div>
                          </div>
                        )}
                      </div>

                      {(selectedVoucherCampaignId !== null || appliedVoucherCode) && (
                        <button
                          type="button"
                          onClick={handleClearVoucherSelection}
                          className="text-xs font-medium text-gray-700 underline hover:text-black"
                        >
                          Clear voucher selection
                        </button>
                      )}

                      {voucherErrorMessage && (
                        <p className="text-xs font-medium text-red-600">{voucherErrorMessage}</p>
                      )}

                      {voucherClaimError && (
                        <p role="alert" className="text-xs font-medium text-red-600">{voucherClaimError}</p>
                      )}
                    </div>
                  )}
                </div>

                {/* Summary */}
                <div className="space-y-3 mb-4">
                  <div className="flex justify-between text-sm">
                    <span className="text-gray-600">Subtotal (Before VAT)</span>
                    <span className="text-black font-medium">₱{subtotal.toLocaleString()}</span>
                  </div>
                  {isPromoPreviewLoading && (
                    <div className="flex justify-between text-sm">
                      <span className="text-gray-500">Voucher check</span>
                      <span className="text-gray-500">Checking claimed vouchers...</span>
                    </div>
                  )}
                  {!isPromoPreviewLoading && voucherDiscountAmount > 0 && (
                    <div className="flex justify-between text-sm">
                      <span className="text-emerald-700 font-semibold">{appliedVoucherLabel}</span>
                      <span className="text-emerald-700 font-medium">-₱{voucherDiscountAmount.toLocaleString()}</span>
                    </div>
                  )}
                  {!isPromoPreviewLoading && shippingVoucherDiscount > 0 && (
                    <div className="flex justify-between text-sm">
                      <span className="text-emerald-700 font-semibold">{shippingVoucherLabel}</span>
                      <span className="text-emerald-700 font-medium">-₱{shippingVoucherDiscount.toLocaleString()}</span>
                    </div>
                  )}
                  <div className="text-sm">
                    <div className="flex items-start justify-between gap-3">
                      <span className="text-gray-600">Shipping</span>
                      <span className="text-black text-right font-medium max-w-[70%] wrap-break-word">{shippingSummaryValue}</span>
                    </div>
                    {shippingVoucherDiscount > 0 && (
                      <div className="mt-1 flex items-center justify-between text-xs text-gray-500">
                        <span>Original shipping</span>
                        <span className="line-through">₱{shipping.toLocaleString()}</span>
                      </div>
                    )}
                    {shippingCarrierNote && <p className="text-xs text-gray-500 mt-1 leading-relaxed">{shippingCarrierNote}</p>}
                    {shippingPayLaterNotice && <p className="text-xs text-gray-500 mt-1 leading-relaxed">{shippingPayLaterNotice}</p>}
                    {shippingVoucherErrorMessage && <p className="text-xs font-medium text-red-600 mt-1 leading-relaxed">{shippingVoucherErrorMessage}</p>}
                    {shopOwnedCoverageNotice}
                  </div>
                  <div className="flex justify-between text-sm">
                    <span className="text-gray-600">{vatLabel}</span>
                    <span className="text-black font-medium">{vatDisplay}</span>
                  </div>
                </div>

                {/* Total */}
                <div className="border-t border-gray-200 pt-4">
                  <div className="flex justify-between items-baseline">
                    <span className="text-black">Total</span>
                    <div className="flex items-baseline gap-1">
                      <span className="text-xs text-gray-600">PHP</span>
                      <span className="text-xl font-bold text-black">₱{total.toLocaleString()}</span>
                    </div>
                  </div>
                </div>
              </div>
            </aside>
          </div>

          <div className="xl:hidden sticky bottom-0 z-30 border-t border-gray-200 bg-white">
            <div className="px-4 py-3 flex items-end justify-between">
              <div>
                <p className="text-sm text-black">Total ({itemCount} item{itemCount > 1 ? 's' : ''})</p>
                <p className="text-3xl font-bold text-gray-900">₱{total.toLocaleString()}</p>
              </div>
              <button
                onClick={handlePayNow}
                disabled={isProcessing || (isPolicyAcceptanceRequired && !policyAccepted)}
                className={`px-6 py-3 rounded-full text-base font-semibold text-white transition-colors ${
                  (isProcessing || (isPolicyAcceptanceRequired && !policyAccepted)) ? 'bg-gray-400 cursor-not-allowed' : 'bg-gray-900 hover:bg-gray-800'
                }`}
              >
                {isProcessing ? 'Processing...' : 'Continue to payment'}
              </button>
            </div>
          </div>
        </div>
      </main>

      {/* Footer */}
      <footer className="hidden xl:block mt-12 bg-gray-100 text-slate-900">
        <div className="max-w-7xl mx-auto px-6 py-8">
          <div className="border-t border-gray-300 pt-6 text-xs text-slate-700 flex items-center justify-between">
            <div>© 2026 SOLESPACE. All rights reserved.</div>
            <div className="flex gap-6">
              <a href="#" className="hover:underline">Privacy</a>
              <a href="#" className="hover:underline">Terms</a>
              <a href="#" className="hover:underline">Cookies</a>
            </div>
          </div>
        </div>
      </footer>
    </div>
  );
};

export default Payment;
