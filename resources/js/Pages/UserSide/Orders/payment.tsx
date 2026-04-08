import React, { useState, useEffect, useRef } from 'react';
import { Head, Link, usePage, router } from '@inertiajs/react';
import Navigation from '../Shared/Navigation';
import Swal from '@/Pages/UserSide/Shared/UserModal';
import axios from 'axios';
import { navigateBackOr } from '../Shared/backNavigation';

interface CartItem {
  id: string;
  pid: number;
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
}

interface ShippingEstimateData {
  distance_km: number;
  base_fee: number;
  min_fee: number;
  max_fee: number;
  distance_label?: string;
  customer_notice?: string;
  pay_after_order_notice?: string;
}

interface AppliedVoucherSummary {
  id: number;
  name: string;
  code?: string | null;
  scope: 'shop_wide' | 'product_specific';
  discount_mode: 'percentage' | 'fixed';
  value: number;
}

interface AvailableVoucherOption {
  id: number;
  name: string;
  code?: string | null;
  discount_mode: 'percentage' | 'fixed';
  value: number;
  min_spend: number;
}

interface PromoPreviewData {
  shop_owner_id: number;
  sale_adjusted_subtotal: number;
  voucher_discount: number;
  final_subtotal: number;
  net_subtotal: number;
  vat_amount: number;
  vat_rate: number;
  applied_voucher?: AppliedVoucherSummary | null;
  available_vouchers?: AvailableVoucherOption[];
  voucher_code_suggestions?: AvailableVoucherOption[];
  voucher_error?: string | null;
}

type PaymentMethod = 'paymongo';

const DEFAULT_SHIPPING_REGION = 'Cavite';

const PH_CITY_OPTIONS = [
  'Bacoor',
  'Imus',
  'Dasmariñas',
  'General Trias',
  'Trece Martires',
  'Tagaytay',
  'City of Cavite',
];

const normalizeCitySelection = (city?: string | null) => {
  const trimmedCity = (city || '').trim();
  if (!trimmedCity) return '';
  return PH_CITY_OPTIONS.find((option) => option.toLowerCase() === trimmedCity.toLowerCase()) || '';
};

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
  const [shippingCity, setShippingCity] = useState('');
  const [shippingRegion, setShippingRegion] = useState('');
  const [saveAddressForLater, setSaveAddressForLater] = useState(true);
  const [userAddresses, setUserAddresses] = useState<UserAddress[]>([]);
  const [isAddressSheetOpen, setIsAddressSheetOpen] = useState(false);
  const [isAddressLoading, setIsAddressLoading] = useState(false);
  const [addressSheetMode, setAddressSheetMode] = useState<'list' | 'form'>('list');
  const [editingAddressId, setEditingAddressId] = useState<number | null>(null);
  const [shippingEstimate, setShippingEstimate] = useState<ShippingEstimateData | null>(null);
  const [isShippingEstimateLoading, setIsShippingEstimateLoading] = useState(false);
  const [shippingEstimateReason, setShippingEstimateReason] = useState<string | null>(null);
  const [promoPreview, setPromoPreview] = useState<PromoPreviewData | null>(null);
  const [isPromoPreviewLoading, setIsPromoPreviewLoading] = useState(false);
  const [selectedVoucherCampaignId, setSelectedVoucherCampaignId] = useState<number | null>(null);
  const [voucherCodeInput, setVoucherCodeInput] = useState('');
  const [appliedVoucherCode, setAppliedVoucherCode] = useState('');
  const [isVoucherSelectionEnabled, setIsVoucherSelectionEnabled] = useState(true);
  const [isVoucherSuggestionOpen, setIsVoucherSuggestionOpen] = useState(false);
  const [hasVoucherInputInteraction, setHasVoucherInputInteraction] = useState(false);
  const voucherInputContainerRef = useRef<HTMLDivElement | null>(null);
  const [paymentRecovery, setPaymentRecovery] = useState<{
    scope: 'order' | 'repair';
    id: number;
    reason: 'expired' | 'failed' | 'invalid_state';
  } | null>(null);
  const [isRecoveryCreating, setIsRecoveryCreating] = useState(false);

  const normalizeCheckoutPayload = (rawData: any): CheckoutData | null => {
    if (!rawData || !Array.isArray(rawData.items) || rawData.items.length === 0) {
      return null;
    }

    const normalizedItems: CartItem[] = rawData.items
      .map((item: any) => ({
        id: String(item?.id ?? ''),
        pid: Math.trunc(toFiniteNumber(item?.pid, 0)),
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

  const handleClearVoucherSelection = () => {
    setIsVoucherSelectionEnabled(false);
    setSelectedVoucherCampaignId(null);
    setAppliedVoucherCode('');
    setVoucherCodeInput('');
    setHasVoucherInputInteraction(true);
    setIsVoucherSuggestionOpen(false);
  };

  const handleCityChange = (city: string) => {
    const selectedCity = normalizeCitySelection(city);
    setShippingCity(selectedCity);
    setShippingRegion(selectedCity ? DEFAULT_SHIPPING_REGION : '');
    setShippingEstimate(null);
    setShippingEstimateReason(null);
    setIsShippingEstimateLoading(Boolean(selectedCity));
  };

  const formatAddressDisplay = (addr?: Partial<UserAddress> | null) => {
    if (!addr) return '';
    if (addr.full_address) return addr.full_address;
    return [addr.address_line, addr.barangay, addr.city, addr.province || addr.region, addr.postal_code].filter(Boolean).join(', ');
  };

  const applySelectedAddress = (addr: UserAddress) => {
    setCustomerName(addr.name || '');
    setCustomerPhone(addr.phone || '');
    setShippingAddressLine(addr.address_line || '');
    const selectedCity = normalizeCitySelection(addr.city);
    setShippingRegion(addr.region || (selectedCity ? DEFAULT_SHIPPING_REGION : ''));
    setShippingCity(selectedCity);
    setShippingBarangay(addr.barangay || '');
    setShippingPostalCode(addr.postal_code || '');
    setCheckoutData((prev) => prev
      ? {
          ...prev,
          address_id: addr.id,
          shipping_region: addr.region || null,
          shipping_province: addr.province || addr.region || null,
          shipping_city: addr.city || null,
          shipping_barangay: addr.barangay || null,
          shipping_postal_code: addr.postal_code || null,
          shipping_address_line: addr.address_line || null,
        }
      : prev);
  };

  const openAddressSheet = async () => {
    setAddressSheetMode('list');
    setEditingAddressId(null);

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
    if (!customerName || !customerPhone || !shippingAddressLine || !shippingBarangay || !shippingCity || !shippingRegion || !shippingPostalCode) {
      Swal.fire({
        icon: 'warning',
        title: 'Missing fields',
        text: 'Please fill all required address fields.',
        confirmButtonColor: '#000000',
      });
      return;
    }

    if (!user) {
      setIsAddressSheetOpen(false);
      setAddressSheetMode('list');
      return;
    }

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
          city: shippingCity,
          barangay: shippingBarangay,
          postal_code: shippingPostalCode,
          is_default: userAddresses.length === 0,
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
      setIsAddressSheetOpen(false);
      setAddressSheetMode('list');
    } catch (error) {
      console.warn('Failed to save address from sheet:', error);
      setEditingAddressId(null);
      setIsAddressSheetOpen(false);
      setAddressSheetMode('list');
    }
  };

  const handleEditAddressFromList = (addr: UserAddress) => {
    setEditingAddressId(addr.id);
    setCustomerName(addr.name || '');
    setCustomerPhone(addr.phone || '');
    setShippingAddressLine(addr.address_line || '');
    setShippingRegion(addr.region || (addr.city ? DEFAULT_SHIPPING_REGION : ''));
    setShippingCity(addr.city || '');
    setShippingBarangay(addr.barangay || '');
    setShippingPostalCode(addr.postal_code || '');
    setAddressSheetMode('form');
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
          const savedCity = normalizeCitySelection(data.shipping_city);
          setShippingCity(savedCity);
          setShippingRegion(data.shipping_region || (savedCity ? DEFAULT_SHIPPING_REGION : ''));
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

  useEffect(() => {
    if (!checkoutData || isPremiumPayment || isRepairPayment) {
      setShippingEstimate(null);
      setIsShippingEstimateLoading(false);
      setShippingEstimateReason(null);
      return;
    }

    const city = normalizeCitySelection(shippingCity);
    const region = shippingRegion.trim();

    if (!city || !region) {
      setShippingEstimate(null);
      setIsShippingEstimateLoading(false);
      setShippingEstimateReason('Enter city and region to calculate shipping fee.');
      return;
    }

    const itemPids = checkoutData.items
      .map((item) => Number(item.pid))
      .filter((pid) => Number.isInteger(pid) && pid > 0);

    if (itemPids.length === 0) {
      setShippingEstimate(null);
      setIsShippingEstimateLoading(false);
      setShippingEstimateReason('Unable to resolve product location for shipping estimate.');
      return;
    }

    setShippingEstimate(null);
    setShippingEstimateReason(null);
    setIsShippingEstimateLoading(true);

    const controller = new AbortController();
    const timer = window.setTimeout(async () => {
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
            shipping_address_line: shippingAddressLine,
            shipping_barangay: shippingBarangay,
            shipping_city: city,
            shipping_region: region,
            shipping_postal_code: shippingPostalCode,
          }),
          signal: controller.signal,
        });

        if (!response.ok) {
          let serverReason = `Unable to fetch shipping estimate (HTTP ${response.status}).`;
          try {
            const errorData = await response.json();
            serverReason = errorData?.reason || errorData?.message || serverReason;
          } catch {
            // Keep the default message when response is not JSON.
          }

          if (response.status === 429) {
            serverReason = 'Too many shipping estimate requests. Please wait a few seconds and try again.';
          }

          if (response.status === 419) {
            serverReason = 'Session expired or CSRF token mismatch. Please refresh the page and try again.';
          }

          setShippingEstimate(null);
          setShippingEstimateReason(serverReason);
          return;
        }

        const data = await response.json();
        if (data?.has_estimate) {
          setShippingEstimate({
            distance_km: Number(data.distance_km || 0),
            base_fee: Number(data.base_fee || 0),
            min_fee: Number(data.min_fee || 0),
            max_fee: Number(data.max_fee || 0),
            distance_label: data.distance_label,
            customer_notice: data.customer_notice,
            pay_after_order_notice: data.pay_after_order_notice,
          });
          setShippingEstimateReason(null);
        } else {
          setShippingEstimate(null);
          setShippingEstimateReason(data?.reason || 'Shipping fee is currently unavailable.');
        }
      } catch (error) {
        if (!controller.signal.aborted) {
          setShippingEstimate(null);
          setShippingEstimateReason('Network issue while calculating shipping estimate. Please check your connection and try again.');
          console.warn('Shipping estimate lookup failed:', error);
        }
      } finally {
        if (!controller.signal.aborted) {
          setIsShippingEstimateLoading(false);
        }
      }
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
  }, [checkoutData, isPremiumPayment, isRepairPayment, selectedVoucherCampaignId, appliedVoucherCode, isVoucherSelectionEnabled]);

  useEffect(() => {
    if (normalizeCitySelection(shippingCity) && !shippingRegion.trim()) {
      setShippingRegion(DEFAULT_SHIPPING_REGION);
    }
  }, [shippingCity, shippingRegion]);

  useEffect(() => {
    if (selectedVoucherCampaignId === null) {
      return;
    }

    const availableVoucherIds = (promoPreview?.available_vouchers || []).map((voucher) => voucher.id);
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

  // Save address to user account
  const saveAddressToAccount = async (orderId: number | undefined) => {
    if (!saveAddressForLater || !orderId) return;

    try {
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
      
      const addressData = {
        name: customerName,
        phone: customerPhone,
        address_line: shippingAddressLine,
        region: shippingRegion || DEFAULT_SHIPPING_REGION,
        province: shippingRegion || DEFAULT_SHIPPING_REGION, // Use region as province
        city: shippingCity,
        barangay: shippingBarangay,
        postal_code: shippingPostalCode,
        is_default: true,
      };

      const response = await fetch('/api/user/addresses', {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken,
          'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'include',
        body: JSON.stringify(addressData),
      });

      if (response.ok) {
        console.log('Address saved successfully');
      } else {
        console.warn('Failed to save address, but order was created');
      }
    } catch (error) {
      console.warn('Unable to save address:', error);
      // Don't fail - order was already created
    }
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

    const computedShippingFee = !isPremiumPayment && !isRepairPayment
      ? Math.max(0, Number(shippingEstimate?.max_fee ?? 0))
      : 0;
    const normalizedSubtotalAmount = Math.max(0, toFiniteNumber(promoPreview?.final_subtotal, checkoutData.total_amount));

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

    if (!shippingAddressLine || !shippingBarangay || !shippingCity || !shippingRegion || !shippingPostalCode) {
      setPayError('Please fill in all required shipping address fields.');
      Swal.fire({
        icon: 'warning',
        title: 'Missing Address',
        text: 'Please fill in all required shipping address fields.',
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
      // First, create the order
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
      
      const orderData = {
        items: normalizedOrderItems,
        total_amount: normalizedSubtotalAmount,
        shipping_fee: computedShippingFee,
        customer_name: customerName,
        customer_email: customerEmail,
        customer_phone: customerPhone,
        shipping_address: `${shippingAddressLine}, ${shippingBarangay}, ${shippingCity}, ${shippingRegion} ${shippingPostalCode}`,
        address_id: checkoutData.address_id ?? null,
        shipping_region: shippingRegion,
        shipping_province: null,
        shipping_city: shippingCity,
        shipping_barangay: shippingBarangay,
        shipping_postal_code: shippingPostalCode,
        shipping_address_line: shippingAddressLine,
        payment_method: selectedPaymentMethod,
        disable_voucher: !isVoucherSelectionEnabled,
        voucher_campaign_id: selectedVoucherCampaignId,
        voucher_code: appliedVoucherCode.trim() || null,
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

      // Save address if checkbox is checked
      await saveAddressToAccount(orderId);

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
  const selectedCity = normalizeCitySelection(shippingCity);
  const hasSelectedCity = Boolean(selectedCity);
  const shipping = !isPremiumPayment && !isRepairPayment
    ? (hasSelectedCity
      ? Math.max(0, Number(shippingEstimate?.max_fee ?? checkoutShipping ?? 0))
      : 0)
    : 0;
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
    ? productTotalInclusive + shipping
    : subtotal + shipping;
  const vatLabel = `VAT (${vatRatePercent}%)`;
  const vatDisplay = `₱${vatAmount.toLocaleString()}`;
  const voucherDiscountAmount = !isPremiumPayment && !isRepairPayment
    ? Math.max(0, toFiniteNumber(promoPreview?.voucher_discount, 0))
    : 0;
  const appliedVoucherLabel = promoPreview?.applied_voucher?.name
    || promoPreview?.applied_voucher?.code
    || 'Voucher';
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
  const showVoucherSuggestionDropdown = isVoucherSuggestionOpen && !hasExactVoucherSuggestionMatch;
  const itemCount = checkoutData.items.reduce((sum, item) => sum + Math.max(1, Math.trunc(toFiniteNumber(item.qty, 1))), 0);
  const hasShippingEstimate = Boolean(shippingEstimate) && hasSelectedCity;
  const shippingSummaryValue = hasSelectedCity
    ? (hasShippingEstimate ? `₱${shipping.toLocaleString()}` : (isShippingEstimateLoading ? 'Calculating...' : 'Unavailable'))
    : 'Select a city';
  const isShippingCalculating = hasSelectedCity && isShippingEstimateLoading;
  const shippingCarrierNote = hasShippingEstimate
    ? ''
    : (hasSelectedCity
      ? (shippingEstimateReason || 'Complete your delivery address to calculate shipping.')
      : 'Select a city to calculate shipping.');
  const shippingPayLaterNotice = hasShippingEstimate
    ? ''
    : (hasSelectedCity
      ? 'Shipping fee must be calculated before you can continue to payment.'
      : 'Shipping fee will appear after you select a city.');
  const fullShippingAddress = [shippingAddressLine, shippingBarangay, shippingCity, shippingRegion]
    .filter(Boolean)
    .join(', ');
  const deliveryName = customerName || user?.name || 'No delivery name yet';
  const deliveryPhone = customerPhone || user?.phone || 'No phone yet';
  const paymentBackFallbackHref = isPremiumPayment ? '/shop-owner/premium-benefits' : '/checkout';

  return (
    <div className="min-h-screen flex flex-col bg-white">
      <style>{`
        .voucher-suggestion-scroll {
          -ms-overflow-style: none;
          scrollbar-width: none;
        }

        .voucher-suggestion-scroll::-webkit-scrollbar {
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
                          ? `₱${shipping.toLocaleString()}`
                          : 'Unavailable'}
                    </span>
                  </span>
                </div>
              </div>
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
                  {shippingCarrierNote && <p className="text-xs text-gray-500 mt-1 leading-relaxed">{shippingCarrierNote}</p>}
                  {shippingPayLaterNotice && <p className="text-xs text-gray-500 mt-1 leading-relaxed">{shippingPayLaterNotice}</p>}
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

            <div className="bg-white px-4 py-4 border-y border-gray-200 text-sm text-gray-700">
              By placing an order, you agree to the Terms of Use and Sale and acknowledge that you have read the Privacy Policy.
            </div>

            {payError && (
              <div className="mt-2 mx-4 p-4 bg-red-50 border border-red-200 rounded text-red-600 text-sm">
                {payError}
              </div>
            )}

            {isAddressSheetOpen && (
              <div className="fixed inset-0 z-40 bg-white overflow-y-auto">
                <div className="sticky top-0 z-10 bg-white border-b border-gray-200 px-4 py-4 flex items-center justify-between">
                  <button
                    type="button"
                    onClick={() => {
                      if (addressSheetMode === 'form') {
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
                  <h2 className="text-2xl font-semibold text-black">{addressSheetMode === 'form' ? (editingAddressId ? 'Edit address' : 'Add address') : 'Your addresses'}</h2>
                  <div className="w-6" />
                </div>

                {addressSheetMode === 'list' ? (
                  <>
                    <button
                      type="button"
                      onClick={() => {
                        setEditingAddressId(null);
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
                    <div className="grid grid-cols-2 gap-3">
                      <input
                        type="text"
                        placeholder="Postal code"
                        value={shippingPostalCode}
                        onChange={e => handlePostalCodeChange(e.target.value, setShippingPostalCode)}
                        className="w-full px-4 py-3 border border-gray-300 rounded text-black bg-white"
                      />
                      <select
                        value={shippingCity}
                        onChange={(e) => handleCityChange(e.target.value)}
                        className="w-full px-4 py-3 border border-gray-300 rounded text-black bg-white"
                        title="City"
                        aria-label="City"
                      >
                        <option value="">Select City</option>
                        {PH_CITY_OPTIONS.map((city) => (
                          <option key={city} value={city}>{city}</option>
                        ))}
                      </select>
                    </div>
                    <input
                      type="tel"
                      placeholder="Phone"
                      value={customerPhone}
                      onChange={e => setCustomerPhone(e.target.value)}
                      className="w-full px-4 py-3 border border-gray-300 rounded text-black bg-white"
                    />

                    <button
                      type="button"
                      onClick={handleUseAddressFromForm}
                      className="w-full mt-2 rounded-lg bg-gray-900 py-3 text-base font-semibold text-white"
                    >
                      {editingAddressId ? 'Save changes' : 'Use this address'}
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
            )}
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
                {!isPremiumPayment && <h2 className="text-xl font-bold text-black mb-5">Delivery</h2>}
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

                  {/* Postal Code, City, and Region */}
                  {!isPremiumPayment && (
                    <>
                      <div className="grid grid-cols-2 gap-5">
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
                        <div>
                          <label className="block text-sm font-medium text-black mb-2.5">City</label>
                          <select
                            value={shippingCity}
                            onChange={(e) => handleCityChange(e.target.value)}
                            className="w-full px-4 py-3 border border-gray-300 rounded text-black bg-white text-base"
                            title="City"
                            aria-label="City"
                          >
                            <option value="">Select City</option>
                            {PH_CITY_OPTIONS.map((city) => (
                              <option key={city} value={city}>{city}</option>
                            ))}
                          </select>
                        </div>
                      </div>
                    </>
                  )}

                  {/* Phone */}
                  <div>
                    <label className="block text-sm font-medium text-black mb-2.5">Phone</label>
                    <input
                      type="tel"
                      placeholder="Phone"
                      value={customerPhone}
                      onChange={e => setCustomerPhone(e.target.value)}
                      className="w-full px-4 py-3 border border-gray-300 rounded text-black bg-white text-base"
                    />
                  </div>

                  {/* Save info */}
                  <label className="flex items-center gap-3 pt-2">
                    <input 
                      type="checkbox" 
                      title="Save my information for faster checkout"
                      aria-label="Save my information for faster checkout"
                      checked={saveAddressForLater}
                      onChange={e => setSaveAddressForLater(e.target.checked)}
                      className="w-5 h-5" 
                    />
                    <span className="text-base text-black">Save my information for a faster checkout</span>
                  </label>

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

              {/* Pay Now Button */}
              <button
                onClick={handlePayNow}
                disabled={isProcessing}
                className={`w-full py-3 rounded-xl font-bold text-white mb-8 transition-colors text-base ${
                  isProcessing ? 'bg-gray-400 cursor-not-allowed' : 'bg-gray-900 hover:bg-gray-800'
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
                      <div className="flex items-center gap-2">
                        <div ref={voucherInputContainerRef} className="relative flex-1">
                          <input
                            type="text"
                            aria-label="Voucher code"
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
                            className="w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-black"
                          />
                          {showVoucherSuggestionDropdown && (
                            <div className="voucher-suggestion-scroll absolute z-30 mt-1 max-h-44 w-full overflow-y-auto rounded-md border border-gray-200 bg-white shadow-lg">
                              {filteredVoucherCodeSuggestions.length > 0 ? (
                                filteredVoucherCodeSuggestions.map((voucher) => {
                                  const displayName = voucher.name || voucher.code || 'Voucher';
                                  const displayCode = normalizeVoucherCode(String(voucher.code || voucher.name || ''));

                                  return (
                                    <button
                                      key={voucher.id}
                                      type="button"
                                      onMouseDown={(e) => {
                                        e.preventDefault();
                                        const normalizedCode = normalizeVoucherCode(displayCode);
                                        setIsVoucherSelectionEnabled(true);
                                        setSelectedVoucherCampaignId(voucher.id);
                                        setHasVoucherInputInteraction(true);
                                        setVoucherCodeInput(normalizedCode);
                                        setAppliedVoucherCode(normalizedCode);
                                        setIsVoucherSuggestionOpen(false);
                                      }}
                                      className="w-full border-b border-gray-100 px-3 py-2 text-left text-sm text-black hover:bg-gray-50 last:border-b-0"
                                    >
                                      <span className="block font-medium text-black">{displayName}</span>
                                      {voucher.code && voucher.name && normalizeVoucherCode(voucher.code) !== normalizeVoucherCode(voucher.name) && (
                                        <span className="block text-xs text-gray-500">{normalizeVoucherCode(voucher.code)}</span>
                                      )}
                                    </button>
                                  );
                                })
                              ) : (
                                <div className="px-3 py-2 text-sm text-gray-500">No available vouchers</div>
                              )}
                            </div>
                          )}
                        </div>
                        <button
                          type="button"
                          onClick={handleApplyVoucherCode}
                          className="rounded-md bg-gray-900 px-3 py-2 text-xs font-semibold text-white hover:bg-black"
                        >
                          Apply
                        </button>
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
                  <div className="text-sm">
                    <div className="flex items-start justify-between gap-3">
                      <span className="text-gray-600">Shipping</span>
                      <span className="text-black text-right font-medium max-w-[70%] wrap-break-word">{shippingSummaryValue}</span>
                    </div>
                    {shippingCarrierNote && <p className="text-xs text-gray-500 mt-1 leading-relaxed">{shippingCarrierNote}</p>}
                    {shippingPayLaterNotice && <p className="text-xs text-gray-500 mt-1 leading-relaxed">{shippingPayLaterNotice}</p>}
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
                disabled={isProcessing}
                className={`px-6 py-3 rounded-full text-base font-semibold text-white transition-colors ${
                  isProcessing ? 'bg-gray-400 cursor-not-allowed' : 'bg-gray-900 hover:bg-gray-800'
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