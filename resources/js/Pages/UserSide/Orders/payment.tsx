import React, { useState, useEffect } from 'react';
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

type PaymentMethod = 'paymongo';

const PH_REGION_OPTIONS = [
  'Abra',
  'Agusan del Norte',
  'Agusan del Sur',
  'Aklan',
  'Albay',
  'Antique',
  'Apayao',
  'Aurora',
  'Basilan',
  'Bataan',
  'Batanes',
  'Batangas',
  'Benguet',
  'Biliran',
  'Bohol',
  'Bukidnon',
  'Bulacan',
  'Cagayan',
  'Camarines Norte',
  'Camarines Sur',
  'Camiguin',
  'Capiz',
  'Catanduanes',
  'Cavite',
  'Cebu',
  'Cotabato',
  'Compostela Valley',
  'Davao del Norte',
  'Davao del Sur',
  'Davao Occidental',
  'Davao Oriental',
  'Dinagat Islands',
  'Eastern Samar',
  'Guimaras',
  'Ifugao',
  'Ilocos Norte',
  'Ilocos Sur',
  'Iloilo',
  'Isabela',
  'Kalinga',
  'La Union',
  'Laguna',
  'Lanao del Norte',
  'Lanao del Sur',
  'Leyte',
  'Maguindanao',
  'Marinduque',
  'Masbate',
  'Metro Manila',
  'Misamis Occidental',
  'Misamis Oriental',
  'Mountain Province',
  'Negros Occidental',
  'Negros Oriental',
  'Northern Samar',
  'Nueva Ecija',
  'Nueva Vizcaya',
  'Occidental Mindoro',
  'Oriental Mindoro',
  'Palawan',
  'Pampanga',
  'Pangasinan',
  'Quezon',
  'Quirino',
  'Rizal',
  'Romblon',
  'Samar',
  'Sarangani',
  'Siquijor',
  'Sorsogon',
  'South Cotabato',
  'Southern Leyte',
  'Sultan Kudarat',
  'Sulu',
  'Surigao del Norte',
  'Surigao del Sur',
  'Tarlac',
  'Tawi-Tawi',
  'Zambales',
  'Zamboanga del Norte',
  'Zamboanga del Sur',
  'Zamboanga Sibugay',
];

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
  const [isRegionPickerOpen, setIsRegionPickerOpen] = useState(false);
  const [regionSearch, setRegionSearch] = useState('');
  const [shippingEstimate, setShippingEstimate] = useState<ShippingEstimateData | null>(null);
  const [isShippingEstimateLoading, setIsShippingEstimateLoading] = useState(false);
  const [shippingEstimateReason, setShippingEstimateReason] = useState<string | null>(null);
  const [paymentRecovery, setPaymentRecovery] = useState<{
    scope: 'order' | 'repair';
    id: number;
    reason: 'expired' | 'failed' | 'invalid_state';
  } | null>(null);
  const [isRecoveryCreating, setIsRecoveryCreating] = useState(false);

  const filteredRegions = PH_REGION_OPTIONS.filter((region) => region.toLowerCase().includes(regionSearch.trim().toLowerCase()));

  const formatAddressDisplay = (addr?: Partial<UserAddress> | null) => {
    if (!addr) return '';
    if (addr.full_address) return addr.full_address;
    return [addr.address_line, addr.barangay, addr.city, addr.province || addr.region, addr.postal_code].filter(Boolean).join(', ');
  };

  const applySelectedAddress = (addr: UserAddress) => {
    setCustomerName(addr.name || '');
    setCustomerPhone(addr.phone || '');
    setShippingAddressLine(addr.address_line || '');
    setShippingRegion(addr.region || '');
    setShippingCity(addr.city || '');
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
    setIsRegionPickerOpen(false);
    setRegionSearch('');

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
    setShippingRegion(addr.region || '');
    setShippingCity(addr.city || '');
    setShippingBarangay(addr.barangay || '');
    setShippingPostalCode(addr.postal_code || '');
    setAddressSheetMode('form');
    setIsRegionPickerOpen(false);
    setRegionSearch('');
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
          const data = JSON.parse(stored);
          setCheckoutData(data);
          setSelectedPaymentMethod('paymongo');
          // Sync local state with loaded data
          setCustomerEmail(data.customer_email || '');
          setCustomerName(data.customer_name || '');
          setCustomerPhone(data.customer_phone || '');
          setShippingAddressLine(data.shipping_address_line || '');
          setShippingBarangay(data.shipping_barangay || '');
          setShippingPostalCode(data.shipping_postal_code || '');
          setShippingCity(data.shipping_city || '');
          setShippingRegion(data.shipping_region || '');
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
                pid: item.pid || item.product_id,
                name: item.name || '',
                price: item.price || 0,
                size: item.size,
                color: options.color || undefined,
                qty: item.quantity || item.qty || 1,
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
                pid: c.pid || c.product_id || parseInt(c.id),
                name: c.name || '',
                price,
                size: c.size || undefined,
                color: c.color || undefined,
                qty: Number(c.qty || 1),
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

    const city = shippingCity.trim();
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

    const controller = new AbortController();
    const timer = window.setTimeout(async () => {
      setIsShippingEstimateLoading(true);

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
        region: shippingRegion,
        province: shippingRegion, // Use region as province
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
            subtotal_amount: Number(checkoutData?.total_amount ?? 0),
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

    const computedShippingFee = !isPremiumPayment && !isRepairPayment
      ? Math.max(0, Number(shippingEstimate?.max_fee ?? 0))
      : 0;

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
        items: checkoutData.items,
        total_amount: checkoutData.total_amount,
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
          subtotal_amount: Number(checkoutData.total_amount ?? 0),
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

  const rawSubtotal = Number(checkoutData.total_amount ?? 0);
  const checkoutShipping = Number(checkoutData.shipping_fee);
  const shipping = !isPremiumPayment && !isRepairPayment
    ? (Number.isFinite(checkoutShipping)
      ? Math.max(0, checkoutShipping)
      : Math.max(0, Number(shippingEstimate?.max_fee ?? 0)))
    : 0;
  const parsedVatRate = Number(checkoutData.vat_rate);
  const vatRatePercent = Number.isFinite(parsedVatRate) && parsedVatRate >= 0 ? parsedVatRate : 12;
  const hasStoredVat = checkoutData.vat_amount !== undefined && checkoutData.vat_amount !== null;
  const parsedVatAmount = Number(checkoutData.vat_amount);
  const normalizedRawSubtotal = Number.isFinite(rawSubtotal) ? Math.max(0, rawSubtotal) : 0;
  const safeVatRatePercent = Math.max(0, vatRatePercent);
  const derivedInclusiveVatAmount = safeVatRatePercent > 0
    ? (normalizedRawSubtotal * (safeVatRatePercent / (100 + safeVatRatePercent)))
    : 0;
  const vatAmount = !isPremiumPayment && !isRepairPayment
    ? (hasStoredVat && Number.isFinite(parsedVatAmount) && parsedVatAmount >= 0
      ? parsedVatAmount
      : derivedInclusiveVatAmount)
    : 0;
  const subtotal = !isPremiumPayment && !isRepairPayment
    ? (hasStoredVat ? normalizedRawSubtotal : Math.max(0, normalizedRawSubtotal - vatAmount))
    : normalizedRawSubtotal;
  const productTotalInclusive = !isPremiumPayment && !isRepairPayment
    ? (hasStoredVat ? subtotal + vatAmount : normalizedRawSubtotal)
    : subtotal;
  const total = !isPremiumPayment && !isRepairPayment
    ? productTotalInclusive + shipping
    : subtotal + shipping;
  const vatLabel = `VAT (${vatRatePercent}%)`;
  const vatDisplay = `₱${vatAmount.toLocaleString()}`;
  const itemCount = checkoutData.items.reduce((sum, item) => sum + item.qty, 0);
  const hasShippingEstimate = Boolean(shippingEstimate);
  const shippingSummaryValue = hasShippingEstimate ? `₱${shipping.toLocaleString()}` : (isShippingEstimateLoading ? 'Calculating...' : 'Unavailable');
  const shippingCarrierNote = hasShippingEstimate
    ? ''
    : (shippingEstimateReason || 'Complete your delivery address to calculate shipping.');
  const shippingPayLaterNotice = hasShippingEstimate
    ? ''
    : 'Shipping fee must be calculated before you can continue to payment.';
  const fullShippingAddress = [shippingAddressLine, shippingBarangay, shippingCity, shippingRegion]
    .filter(Boolean)
    .join(', ');
  const deliveryName = customerName || user?.name || 'No delivery name yet';
  const deliveryPhone = customerPhone || user?.phone || 'No phone yet';
  const paymentBackFallbackHref = isPremiumPayment ? '/shop-owner/premium-benefits' : '/checkout';

  return (
    <div className="min-h-screen flex flex-col bg-white">
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
                  <span className="font-semibold text-black">
                    {isShippingEstimateLoading
                      ? 'Calculating...'
                      : hasShippingEstimate
                        ? `₱${shipping.toLocaleString()}`
                        : 'Unavailable'}
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

                <div className="pt-2">
                  <div className="flex items-start justify-between gap-3">
                    <span className="text-gray-700">Shipping</span>
                    <span className="text-black text-right max-w-[70%] wrap-break-word">{shippingSummaryValue}</span>
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
                        setIsRegionPickerOpen(false);
                        setRegionSearch('');
                        setAddressSheetMode('list');
                        return;
                      }
                      setIsRegionPickerOpen(false);
                      setRegionSearch('');
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
                      <input
                        type="text"
                        placeholder="City"
                        value={shippingCity}
                        onChange={e => setShippingCity(e.target.value)}
                        className="w-full px-4 py-3 border border-gray-300 rounded text-black bg-white"
                      />
                    </div>
                    <button
                      type="button"
                      className="w-full px-4 py-3 border border-gray-300 rounded text-left text-black bg-white flex items-center justify-between"
                      title="Region"
                      aria-label="Region"
                      onClick={() => setIsRegionPickerOpen(true)}
                    >
                      <span className={shippingRegion ? 'text-black' : 'text-gray-500'}>{shippingRegion || 'Select Region'}</span>
                      <span className="text-gray-500 text-base leading-none">&#9662;</span>
                    </button>
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

                {isRegionPickerOpen && addressSheetMode === 'form' && (
                  <div
                    className="fixed inset-0 z-50 bg-black/40 flex items-end"
                    onClick={() => {
                      setIsRegionPickerOpen(false);
                      setRegionSearch('');
                    }}
                  >
                    <div
                      className="w-full max-h-[82vh] bg-white rounded-t-2xl shadow-xl"
                      onClick={(e) => e.stopPropagation()}
                    >
                      <div className="px-4 pt-4 pb-3 border-b border-gray-200 flex items-center justify-between gap-3">
                        <h3 className="text-lg font-semibold text-black">Select Region</h3>
                        <button
                          type="button"
                          className="text-sm font-medium text-gray-600"
                          onClick={() => {
                            setIsRegionPickerOpen(false);
                            setRegionSearch('');
                          }}
                        >
                          Close
                        </button>
                      </div>

                      <div className="p-4 border-b border-gray-100">
                        <input
                          type="text"
                          placeholder="Search region"
                          title="Search region"
                          aria-label="Search region"
                          value={regionSearch}
                          onChange={(e) => setRegionSearch(e.target.value)}
                          className="w-full px-4 py-3 border border-gray-300 rounded text-black bg-white"
                        />
                      </div>

                      <div className="max-h-[56vh] overflow-y-auto py-1">
                        {filteredRegions.length === 0 ? (
                          <p className="px-4 py-6 text-sm text-gray-600">No regions found.</p>
                        ) : (
                          filteredRegions.map((region) => {
                            const isSelected = shippingRegion === region;
                            return (
                              <button
                                key={region}
                                type="button"
                                onClick={() => {
                                  setShippingRegion(region);
                                  setIsRegionPickerOpen(false);
                                  setRegionSearch('');
                                }}
                                className={`w-full px-4 py-3 text-left text-sm border-b border-gray-100 ${isSelected ? 'bg-gray-100 font-medium text-black' : 'bg-white text-gray-800'}`}
                              >
                                {region}
                              </button>
                            );
                          })
                        )}
                      </div>
                    </div>
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
                          <input
                            type="text"
                            placeholder="City"
                            value={shippingCity}
                            onChange={e => setShippingCity(e.target.value)}
                            className="w-full px-4 py-3 border border-gray-300 rounded text-black bg-white text-base"
                          />
                        </div>
                      </div>

                      <div>
                        <label className="block text-sm text-black mb-2.5">Region</label>
                        <select className="w-full px-4 py-3 border border-gray-300 rounded text-black bg-white text-base" title="Region" aria-label="Region" value={shippingRegion || ''} onChange={e => setShippingRegion(e.target.value)}>
                          <option value="">Select Region</option>
                          {PH_REGION_OPTIONS.map((region) => (
                            <option key={region} value={region}>{region}</option>
                          ))}
                        </select>
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
                    className="w-5 h-5 flex-shrink-0"
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

                {/* Discount Code */}
                <div className="flex gap-2 mb-4">
                  <input
                    type="text"
                    placeholder="Discount code or gift card"
                    className="flex-1 px-3 py-2 border border-gray-300 rounded text-sm text-black placeholder-gray-400"
                  />
                  <button className="px-4 py-2 text-sm text-black border border-gray-300 rounded hover:bg-gray-50">
                    Apply
                  </button>
                </div>

                {/* Summary */}
                <div className="space-y-3 mb-4">
                  <div className="flex justify-between text-sm">
                    <span className="text-gray-600">Subtotal (Before VAT)</span>
                    <span className="text-black font-medium">₱{subtotal.toLocaleString()}</span>
                  </div>
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