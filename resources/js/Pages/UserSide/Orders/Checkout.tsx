import React, { useState, useEffect, useMemo, useRef } from 'react';
import { Head, Link, usePage, router } from '@inertiajs/react';
import Navigation from '../Shared/Navigation';
import Swal from '@/Pages/UserSide/Shared/UserModal';
import axios from 'axios';
import { dispatchCartAddedEvent } from '../../../types/cart-events';
import CustomerAddressMapPicker from '@/components/address/CustomerAddressMapPicker';

type CartItem = {
  id: string;
  name: string;
  price: number;
  compare_at_price?: number;
  size?: string;
  color?: string;
  qty: number;
  image?: string;
  stock_quantity?: number;
  pid?: string;
  options?: any;
  shop_id?: string | number;
  shop_owner_id?: string | number;
  shop_name?: string;
};

type ShopGroup = {
  shopKey: string;
  shopName: string;
  items: CartItem[];
};

const Checkout: React.FC = () => {
  const { auth } = usePage().props as any;
  const user = auth?.user;
  
  // Items state starts empty; load from localStorage on client mount
  const [items, setItems] = useState<CartItem[]>([]);
  const [selectedItems, setSelectedItems] = useState<Set<string>>(new Set());
  const [isPaying, setIsPaying] = useState(false);
  const [payError, setPayError] = useState<string | null>(null);
  const [payLink, setPayLink] = useState<string>('');
  const [recoveryOrderId, setRecoveryOrderId] = useState<number | null>(null);
  const [recoveryReason, setRecoveryReason] = useState<'expired' | 'failed' | null>(null);
  const [isCreatingRecoverySession, setIsCreatingRecoverySession] = useState(false);
  const [qtyUpdating, setQtyUpdating] = useState<Record<string, boolean>>({});
  const qtyUpdatingRef = useRef<Record<string, boolean>>({});
  const [showMobileDetails, setShowMobileDetails] = useState(false);
  const [orderNote, setOrderNote] = useState('');
  const [isCartLoading, setIsCartLoading] = useState(true);
  
  // Customer information
  const [customerName, setCustomerName] = useState('');
  const [customerEmail, setCustomerEmail] = useState('');
  const [customerPhone, setCustomerPhone] = useState('');
  const [shippingAddress, setShippingAddress] = useState('');
  const [addresses, setAddresses] = useState<any[]>([]);
  const [selectedAddressId, setSelectedAddressId] = useState<number | null>(null);
  const [showAddressSelector, setShowAddressSelector] = useState(false);
  const [editingAddressId, setEditingAddressId] = useState<number | null>(null);
  const [editingAddressData, setEditingAddressData] = useState<any>(null);
  const [showAddAddressModal, setShowAddAddressModal] = useState(false);
  const [addressesLoading, setAddressesLoading] = useState(false);
  const [addressSaving, setAddressSaving] = useState(false);
  const addressMapRef = useRef<HTMLDivElement | null>(null);
  const [newAddressData, setNewAddressData] = useState({
    name: '',
    phone: '',
    region: '',
    province: '',
    city: '',
    barangay: '',
    postal_code: '',
    address: '',
    latitude: null as number | null,
    longitude: null as number | null,
    is_default: false
  });
  const parseOptions = (rawOptions: any) => {
    if (!rawOptions) return {};
    if (typeof rawOptions === 'string') {
      try {
        return JSON.parse(rawOptions);
      } catch {
        return {};
      }
    }
    return rawOptions;
  };

  const getItemShopKey = (item: CartItem): string => {
    const rawShopKey = item.shop_id || item.shop_owner_id || item.shop_name || 'general';
    const key = String(rawShopKey || '').trim();
    return key || 'general';
  };

  const getItemShopName = (item: CartItem): string => {
    const shopKey = getItemShopKey(item);
    return item.shop_name || (shopKey !== 'general' ? `Shop ${shopKey}` : 'Unknown Shop');
  };

  const subtotal = items.filter(item => selectedItems.has(item.id)).reduce((s, it) => s + it.price * it.qty, 0);

  const shopGroups = useMemo<ShopGroup[]>(() => {
    const groups = new Map<string, ShopGroup>();

    items.forEach((item) => {
      const rawShopKey = item.shop_id || item.shop_owner_id || item.shop_name || 'general';
      const shopKey = String(rawShopKey);
      const shopName = item.shop_name || (shopKey !== 'general' ? `Shop ${shopKey}` : 'Unknown Shop');

      if (!groups.has(shopKey)) {
        groups.set(shopKey, { shopKey, shopName, items: [] });
      }

      groups.get(shopKey)?.items.push(item);
    });

    return Array.from(groups.values());
  }, [items]);

  const selectedCount = selectedItems.size;
  const selectedShopInfo = useMemo(() => {
    const firstSelected = items.find((item) => selectedItems.has(item.id));
    if (!firstSelected) return null;

    return {
      key: getItemShopKey(firstSelected),
      name: getItemShopName(firstSelected),
    };
  }, [items, selectedItems]);

  const selectedShopKey = selectedShopInfo?.key || null;

  // Load cart function (moved outside useEffect so it can be reused)
  const loadCart = async () => {
    setIsCartLoading(true);
    try {
      if (!user) {
        // If not authenticated, load from localStorage
        try {
          const raw = localStorage.getItem('ss_cart');
          const cart = raw ? JSON.parse(raw) : [];
          const parsed = (cart || []).map((c: any) => {
            const price = (typeof c.price === 'number') ? c.price : (parseFloat(String(c.price).replace(/[^0-9.-]+/g, '')) || 0);
            const options = parseOptions(c.options);
            const size = c.size || c.shoe_size || options.size || (c.meta && c.meta.size) || (c.attributes && c.attributes.size) || undefined;
            const color = c.color || options.color || undefined;
            const compareAt = options.compare_at_price !== undefined ? Number(options.compare_at_price) : undefined;
            return {
              id: String(c.id),
              name: c.name || '',
              price,
              compare_at_price: Number.isFinite(compareAt) ? compareAt : undefined,
              size,
              color,
              qty: Number(c.qty || 1),
              image: c.image || undefined,
              stock_quantity: c.stock_quantity || undefined,
              pid: c.pid || String(c.id),
              options,
              shop_id: c.shop_id || c.shop_owner_id || c.shop?.id || c.shopOwner?.id || 'general',
              shop_owner_id: c.shop_owner_id || c.shop_id || c.shop?.id || c.shopOwner?.id || 'general',
              shop_name: c.shop_name || c.business_name || c.shop?.business_name || c.shopOwner?.business_name || (c.shop_owner_id || c.shop_id || c.shop?.id || c.shopOwner?.id ? `Shop ${c.shop_owner_id || c.shop_id || c.shop?.id || c.shopOwner?.id}` : 'Unknown Shop'),
            };
          });
          setItems(parsed);
          setSelectedItems(new Set(parsed.map((item: CartItem) => item.id)));
        } catch (e) {
          setItems([]);
        }
        return;
      }

      try {
        // Sync localStorage cart to database first
        const raw = localStorage.getItem('ss_cart');
        if (raw) {
          const localCart = JSON.parse(raw);
          if (localCart && localCart.length > 0) {
            await axios.post('/api/cart/sync', { items: localCart });
            // Clear localStorage after successful sync
            localStorage.removeItem('ss_cart');
          }
        }

        // Load cart from database
        const response = await axios.get('/api/cart');
        if (response.data.items) {
          const parsed = response.data.items.map((item: any) => {
            // Extract color from options if available
            const options = parseOptions(item.options);
            const compareAt = options.compare_at_price !== undefined ? Number(options.compare_at_price) : undefined;
            return {
              id: String(item.id),
              name: item.name || '',
              price: item.price || 0,
              compare_at_price: Number.isFinite(compareAt) ? compareAt : undefined,
              size: item.size,
              color: options.color || undefined,
              qty: item.quantity || item.qty || 1,
              image: item.image,
              stock_quantity: item.stock_quantity,
              pid: item.product_id || item.pid,
              options,
              shop_id: item.shop_id || item.shop_owner_id || item.product?.shop_owner_id || 'general',
              shop_owner_id: item.shop_owner_id || item.shop_id || item.product?.shop_owner_id || 'general',
              shop_name: item.shop_name || item.product?.shop_owner?.business_name || (item.shop_owner_id || item.shop_id || item.product?.shop_owner_id ? `Shop ${item.shop_owner_id || item.shop_id || item.product?.shop_owner_id}` : 'Unknown Shop'),
            };
          });
          setItems(parsed);
          setSelectedItems(new Set(parsed.map((item: CartItem) => item.id)));
        }
      } catch (error) {
        console.error('Failed to load cart:', error);
        // Fallback to localStorage
        try {
          const raw = localStorage.getItem('ss_cart');
          const cart = raw ? JSON.parse(raw) : [];
          const parsed = (cart || []).map((c: any) => {
            const price = (typeof c.price === 'number') ? c.price : (parseFloat(String(c.price).replace(/[^0-9.-]+/g, '')) || 0);
            const options = parseOptions(c.options);
            const size = c.size || options.size || undefined;
            const color = c.color || options.color || undefined;
            const compareAt = options.compare_at_price !== undefined ? Number(options.compare_at_price) : undefined;
            return {
              id: String(c.id),
              name: c.name || '',
              price,
              compare_at_price: Number.isFinite(compareAt) ? compareAt : undefined,
              size,
              color,
              qty: Number(c.qty || 1),
              image: c.image || undefined,
              stock_quantity: c.stock_quantity || undefined,
              pid: c.pid || String(c.id),
              options,
              shop_id: c.shop_id || c.shop_owner_id || c.shop?.id || c.shopOwner?.id || 'general',
              shop_owner_id: c.shop_owner_id || c.shop_id || c.shop?.id || c.shopOwner?.id || 'general',
              shop_name: c.shop_name || c.business_name || c.shop?.business_name || c.shopOwner?.business_name || (c.shop_owner_id || c.shop_id || c.shop?.id || c.shopOwner?.id ? `Shop ${c.shop_owner_id || c.shop_id || c.shop?.id || c.shopOwner?.id}` : 'Unknown Shop'),
            };
          });
          setItems(parsed);
          setSelectedItems(new Set(parsed.map((item: CartItem) => item.id)));
        } catch (e) {
          setItems([]);
        }
      }
    } finally {
      setIsCartLoading(false);
    }
  };

  // Load cart from database on mount
  useEffect(() => {
    loadCart();
    
    // Pre-fill user information if logged in
    if (user) {
      setCustomerEmail(user.email || '');
      loadAddresses();
    }
    
    // Check if returning from successful payment and remove only purchased items
    const urlParams = new URLSearchParams(window.location.search);
    const paymentSuccess = urlParams.get('payment_success');
    const orderId = urlParams.get('order_id');
    const paymongoFailed = urlParams.get('paymongo_failed') === '1';
    const paymongoExpired = urlParams.get('paymongo_expired') === '1' || urlParams.get('expired') === '1';

    if (paymongoFailed || paymongoExpired) {
      const pendingOrderId = Number(sessionStorage.getItem('pendingOrderId') || '0');
      if (Number.isFinite(pendingOrderId) && pendingOrderId > 0) {
        setRecoveryOrderId(pendingOrderId);
        setRecoveryReason(paymongoExpired ? 'expired' : 'failed');
      }

      urlParams.delete('paymongo_failed');
      urlParams.delete('paymongo_expired');
      urlParams.delete('expired');
      const queryString = urlParams.toString();
      window.history.replaceState({}, '', `${window.location.pathname}${queryString ? `?${queryString}` : ''}`);
    }
    
    if (paymentSuccess === 'true' && orderId) {
      // Get the items that were purchased from sessionStorage
      const checkoutDataStr = sessionStorage.getItem('checkoutData');
      if (checkoutDataStr) {
        try {
          const checkoutData = JSON.parse(checkoutDataStr);
          const purchasedItemIds = checkoutData.selected_item_ids || checkoutData.items.map((item: any) => item.id);
          
          // Remove only the purchased items from cart
          if (user) {
            // Remove from database
            Promise.all(
              purchasedItemIds.map((itemId: string) =>
                fetch('/api/cart/remove', {
                  method: 'POST',
                  credentials: 'include',
                  headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                  },
                  body: JSON.stringify({ id: itemId }),
                })
              )
            ).then(() => {
              // Clear sessionStorage checkout data
              sessionStorage.removeItem('checkoutData');
              // Reload cart to show remaining items
              loadCart();
              // Remove query params from URL
              window.history.replaceState({}, '', window.location.pathname);
            });
          } else {
            // Remove from localStorage
            const raw = localStorage.getItem('ss_cart');
            if (raw) {
              const cart = JSON.parse(raw);
              const remainingCart = cart.filter((item: any) => !purchasedItemIds.includes(String(item.id)));
              localStorage.setItem('ss_cart', JSON.stringify(remainingCart));
              // Clear sessionStorage checkout data
              sessionStorage.removeItem('checkoutData');
              // Reload cart to show remaining items
              loadCart();
              // Remove query params from URL
              window.history.replaceState({}, '', window.location.pathname);
            }
          }
        } catch (e) {
          console.error('Error processing post-payment cart cleanup:', e);
        }
      }
    }
  }, [user]);

  useEffect(() => {
    const envLink = (import.meta as any)?.env?.VITE_PAYMONGO_PAYMENT_LINK;
    const storedLink = typeof window !== 'undefined' ? localStorage.getItem('ss_paymongo_link') : '';
    setPayLink(envLink || storedLink || '');
  }, []);

  const handleCreateRecoverySession = async () => {
    if (!recoveryOrderId || isCreatingRecoverySession) {
      return;
    }

    setIsCreatingRecoverySession(true);
    setPayError(null);

    try {
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
      const response = await fetch(`/api/orders/${recoveryOrderId}/retry-payment-session`, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrfToken,
        },
      });

      const data = await response.json().catch(() => ({}));
      if (!response.ok) {
        throw new Error(data?.message || data?.error || 'Failed to create a new payment session.');
      }

      if (!data?.checkout_url) {
        throw new Error('Incomplete payment data received from PayMongo');
      }

      sessionStorage.setItem('pendingOrderId', String(recoveryOrderId));
      window.location.href = data.checkout_url;
    } catch (error: any) {
      const message = error?.message || 'Unable to create a new payment session.';
      setPayError(message);
      Swal.fire({
        icon: 'error',
        title: 'Unable to Create Session',
        text: message,
      });
    } finally {
      setIsCreatingRecoverySession(false);
    }
  };

  const increment = async (id: string) => {
    const item = items.find(i => i.id === id);
    if (!item) return;

    if (qtyUpdatingRef.current[id]) return;

    if (item.stock_quantity !== undefined && item.qty >= item.stock_quantity) {
      Swal.fire({
        icon: 'warning',
        title: 'Stock limit reached',
        text: `Cannot add more. Only ${item.stock_quantity} items in stock.`,
      });
      return;
    }

    qtyUpdatingRef.current[id] = true;
    setQtyUpdating(prev => ({ ...prev, [id]: true }));

    if (user) {
      // Update via API
      try {
        const response = await fetch('/api/cart/update', {
          method: 'POST',
          credentials: 'include',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
          },
          body: JSON.stringify({
            id: id,
            quantity: item.qty + 1,
          }),
        });
        
        if (!response.ok) {
          const errorData = await response.json();
          throw new Error(errorData.error || 'Failed to update cart');
        }
        
        const updatedItems = items.map(i => i.id === id ? { ...i, qty: i.qty + 1 } : i);
        setItems(updatedItems);
        const total = updatedItems.reduce((sum, item) => sum + item.qty, 0);
        dispatchCartAddedEvent({ total });
      } catch (error: any) {
        const errorMsg = error.message || 'Failed to update cart';
        Swal.fire({
          icon: 'error',
          title: 'Unable to update cart',
          text: errorMsg,
        });
        console.error('Failed to update cart:', error);
        // Refresh cart to get correct quantities
        loadCart();
      } finally {
        qtyUpdatingRef.current[id] = false;
        setQtyUpdating(prev => ({ ...prev, [id]: false }));
      }
    } else {
      // Update localStorage
      try {
        const updatedItems = items.map(i => i.id === id ? { ...i, qty: i.qty + 1 } : i);
        setItems(updatedItems);
        try {
          const raw = localStorage.getItem('ss_cart');
          const cart = raw ? JSON.parse(raw) : [];
          const cartItem = cart.find((c: any) => String(c.id) === id);
          if (cartItem) {
            cartItem.qty = (cartItem.qty || 0) + 1;
          }
          localStorage.setItem('ss_cart', JSON.stringify(cart));
          const total = updatedItems.reduce((sum, item) => sum + item.qty, 0);
          dispatchCartAddedEvent({ total });
        } catch (e) {}
      } finally {
        qtyUpdatingRef.current[id] = false;
        setQtyUpdating(prev => ({ ...prev, [id]: false }));
      }
    }
  };

  const decrement = async (id: string) => {
    const item = items.find(i => i.id === id);
    if (!item || item.qty <= 1) return;

    if (user) {
      // Update via API
      try {
        const response = await fetch('/api/cart/update', {
          method: 'POST',
          credentials: 'include',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
          },
          body: JSON.stringify({
            id: id,
            quantity: item.qty - 1,
          }),
        });
        
        if (!response.ok) {
          const errorData = await response.json();
          throw new Error(errorData.error || 'Failed to update cart');
        }
        
        const updatedItems = items.map(i => i.id === id ? { ...i, qty: Math.max(1, i.qty - 1) } : i);
        setItems(updatedItems);
        const total = updatedItems.reduce((sum, item) => sum + item.qty, 0);
        dispatchCartAddedEvent({ total });
      } catch (error: any) {
        const errorMsg = error.message || 'Failed to update cart';
        Swal.fire({
          icon: 'error',
          title: 'Unable to update cart',
          text: errorMsg,
        });
        console.error('Failed to update  :', error);
        // Refresh cart to get correct quantities
        loadCart();
      }
    } else {
      // Update localStorage
      const updatedItems = items.map(i => i.id === id ? { ...i, qty: Math.max(1, i.qty - 1) } : i);
      setItems(updatedItems);
      try {
        const raw = localStorage.getItem('ss_cart');
        const cart = raw ? JSON.parse(raw) : [];
        const cartItem = cart.find((c: any) => String(c.id) === id);
        if (cartItem) {
          cartItem.qty = Math.max(1, (cartItem.qty || 1) - 1);
        }
        localStorage.setItem('ss_cart', JSON.stringify(cart));
        const total = updatedItems.reduce((sum, item) => sum + item.qty, 0);
        dispatchCartAddedEvent({ total });
      } catch (e) {}
    }
  };

  const removeItem = (id: string) => setItems((prev) => prev.filter(i => i.id !== id));
  
  // persist remove to storage or database
  const removeItemPersist = async (id: string) => {
    if (user) {
      // Remove via API
      try {
        const response = await fetch('/api/cart/remove', {
          method: 'POST',
          credentials: 'include',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
          },
          body: JSON.stringify({ id }),
        });
        
        if (!response.ok) {
          throw new Error('Failed to remove cart item');
        }
        
        const updatedItems = items.filter(i => i.id !== id);
        setItems(updatedItems);
        const total = updatedItems.reduce((sum, item) => sum + item.qty, 0);
        dispatchCartAddedEvent({ total });
      } catch (error: any) {
        const errorMsg = error.message || 'Failed to remove cart item';
        Swal.fire({
          icon: 'error',
          title: 'Unable to remove item',
          text: errorMsg,
        });
        console.error('Failed to remove cart item:', error);
        // Refresh cart to get correct data
        loadCart();
      }
    } else {
      // Remove from localStorage
      const updatedItems = items.filter(i => i.id !== id);
      setItems(updatedItems);
      try {
        const raw = localStorage.getItem('ss_cart');
        const cart = raw ? JSON.parse(raw) : [];
        const nextCart = cart.filter((c: any) => String(c.id) !== id);
        localStorage.setItem('ss_cart', JSON.stringify(nextCart));
        const total = updatedItems.reduce((sum, item) => sum + item.qty, 0);
        dispatchCartAddedEvent({ total });
      } catch (e) {}
    }
  };

  // Load addresses from backend
  const loadAddresses = async () => {
    if (!user) return;
    
    setAddressesLoading(true);
    try {
      const response = await fetch('/api/user/addresses', {
        method: 'GET',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        },
      });
      
      if (!response.ok) {
        throw new Error('Failed to load addresses');
      }
      
      const data = await response.json();
      if (data.success && data.addresses) {
        // Format addresses for display
        const formattedAddresses = data.addresses.map((addr: any) => ({
          ...addr,
          address: addr.full_address || `${addr.address_line}, ${addr.barangay}, ${addr.city}, ${addr.province}, ${addr.region}${addr.postal_code ? ', ' + addr.postal_code : ''}`,
        }));
        setAddresses(formattedAddresses);
        
        // Auto-select default address
        const defaultAddress = formattedAddresses.find((a: any) => a.is_default);
        if (defaultAddress) {
          handleSelectAddress(defaultAddress);
        }
      }
    } catch (error) {
      console.error('Failed to load addresses:', error);
      Swal.fire({
        icon: 'error',
        title: 'Failed to load addresses',
        text: 'Unable to load your saved addresses. Please try again.',
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3000,
      });
    } finally {
      setAddressesLoading(false);
    }
  };

  const handleSelectAddress = (address: any) => {
    setSelectedAddressId(address.id);
    setCustomerName(address.name || '');
    setCustomerPhone(address.phone || '');
    // Use full_address if available, otherwise construct from parts
    const fullAddress = address.full_address || address.address || 
      `${address.address_line || ''}, ${address.barangay || ''}, ${address.city || ''}, ${address.province || ''}, ${address.region || ''}${address.postal_code ? ', ' + address.postal_code : ''}`;
    setShippingAddress(fullAddress);
    setCustomerEmail(user?.email || '');
  };

  const handleEditAddress = (address: any, focusMap = false) => {
    setEditingAddressId(address.id);
    setEditingAddressData({
      ...address,
      latitude: address.latitude ?? null,
      longitude: address.longitude ?? null,
    });
    setShowAddressSelector(false);
    if (focusMap) {
      requestAnimationFrame(() => {
        addressMapRef.current?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        addressMapRef.current?.querySelector<HTMLInputElement>('input')?.focus();
      });
    }
  };

  const handleSaveEditAddress = async () => {
    if (!user || !editingAddressId) return;
    
    // Comprehensive validation
    if (!editingAddressData.name?.trim()) {
      Swal.fire({
        icon: 'warning',
        title: 'Required Field',
        text: 'Please enter recipient name',
      });
      return;
    }
    
    if (editingAddressData.name.trim().length < 2) {
      Swal.fire({
        icon: 'warning',
        title: 'Invalid Name',
        text: 'Name must be at least 2 characters',
      });
      return;
    }
    
    const phoneValidation = validatePhoneNumber(editingAddressData.phone || '');
    if (!phoneValidation.isValid) {
      Swal.fire({
        icon: 'warning',
        title: 'Invalid Phone Number',
        text: phoneValidation.message,
      });
      return;
    }
    
    if (!editingAddressData.region) {
      Swal.fire({ icon: 'warning', title: 'Required Field', text: 'Please select region' });
      return;
    }
    if (!editingAddressData.province) {
      Swal.fire({ icon: 'warning', title: 'Required Field', text: 'Please select province' });
      return;
    }
    if (!editingAddressData.city) {
      Swal.fire({ icon: 'warning', title: 'Required Field', text: 'Please select city' });
      return;
    }
    if (!editingAddressData.barangay) {
      Swal.fire({ icon: 'warning', title: 'Required Field', text: 'Please select barangay' });
      return;
    }
    if (!editingAddressData.address_line?.trim()) {
      Swal.fire({ icon: 'warning', title: 'Required Field', text: 'Please enter street address' });
      return;
    }
    
    if (editingAddressData.address_line.trim().length < 5) {
      Swal.fire({ icon: 'warning', title: 'Invalid Address', text: 'Street address must be at least 5 characters' });
      return;
    }
    
    if (editingAddressData.postal_code?.trim()) {
      const postalRegex = /^\d{4}$/;
      if (!postalRegex.test(editingAddressData.postal_code.trim())) {
        Swal.fire({ icon: 'warning', title: 'Invalid Postal Code', text: 'Postal code must be 4 digits (e.g., 4100)' });
        return;
      }
    }
    
    setAddressSaving(true);
    try {
      const response = await fetch(`/api/user/addresses/${editingAddressId}`, {
        method: 'PUT',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        },
        body: JSON.stringify({
          name: editingAddressData.name.trim(),
          phone: editingAddressData.phone.trim(),
          region: editingAddressData.region,
          province: editingAddressData.province,
          city: editingAddressData.city,
          barangay: editingAddressData.barangay,
          postal_code: editingAddressData.postal_code?.trim() || '',
          address_line: editingAddressData.address_line.trim(),
          latitude: editingAddressData.latitude,
          longitude: editingAddressData.longitude,
          is_default: editingAddressData.is_default || false,
        }),
      });
      
      if (!response.ok) {
        const errorData = await response.json();
        throw new Error(errorData.message || 'Failed to update address');
      }
      
      const data = await response.json();
      
      Swal.fire({
        icon: 'success',
        title: 'Address Updated',
        text: data.message || 'Your address has been updated successfully.',
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3000,
      });
      
      // Reload addresses from backend
      await loadAddresses();
      
      setEditingAddressId(null);
      setEditingAddressData(null);
    } catch (error: any) {
      console.error('Failed to update address:', error);
      Swal.fire({
        icon: 'error',
        title: 'Update Failed',
        text: error.message || 'Failed to update address. Please try again.',
      });
    } finally {
      setAddressSaving(false);
    }
  };

  const handleDeleteAddress = async (addressId: number) => {
    if (!user) return;
    
    const result = await Swal.fire({
      title: 'Delete Address?',
      text: 'Are you sure you want to delete this address?',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#000000',
      cancelButtonColor: '#6b7280',
      confirmButtonText: 'Yes, delete it',
      cancelButtonText: 'Cancel',
    });
    
    if (!result.isConfirmed) return;
    
    try {
      const response = await fetch(`/api/user/addresses/${addressId}`, {
        method: 'DELETE',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        },
      });
      
      if (!response.ok) {
        throw new Error('Failed to delete address');
      }
      
      Swal.fire({
        icon: 'success',
        title: 'Deleted!',
        text: 'Address has been deleted.',
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3000,
      });
      
      // Reload addresses from backend
      await loadAddresses();
      
      // Clear selection if deleted address was selected
      if (selectedAddressId === addressId) {
        setSelectedAddressId(null);
        setCustomerName('');
        setCustomerPhone('');
        setShippingAddress('');
      }
    } catch (error: any) {
      console.error('Failed to delete address:', error);
      Swal.fire({
        icon: 'error',
        title: 'Delete Failed',
        text: error.message || 'Failed to delete address. Please try again.',
      });
    }
  };

  // Phone validation helper
  const validatePhoneNumber = (phone: string): { isValid: boolean; message: string } => {
    const cleaned = phone.replace(/\s+/g, '').replace(/[-()+]/g, '');
    
    // Philippine mobile formats:
    // 09XX XXX XXXX (11 digits)
    // +639XX XXX XXXX (13 chars with +)
    // 639XX XXX XXXX (12 digits)
    const mobileRegex = /^(\+63|0)?9\d{9}$/;
    
    if (!cleaned) {
      return { isValid: false, message: 'Phone number is required' };
    }
    
    if (!mobileRegex.test(cleaned)) {
      return { isValid: false, message: 'Invalid Philippine mobile number. Use format: 09XX XXX XXXX or +639XX XXX XXXX' };
    }
    
    return { isValid: true, message: '' };
  };

  const openAddAddressModal = () => {
    setNewAddressData({ 
      name: user?.name || user?.first_name || '', 
      phone: user?.phone || '', 
      region: '', 
      province: '', 
      city: '', 
      barangay: '', 
      postal_code: '', 
      address: '', 
      latitude: null,
      longitude: null,
      is_default: addresses.length === 0 // First address is default
    });
    
    setShowAddAddressModal(true);
    setShowAddressSelector(false);
  };

  const handleSaveNewAddress = async () => {
    // Comprehensive validation
    if (!newAddressData.name.trim()) {
      Swal.fire({
        icon: 'warning',
        title: 'Required Field',
        text: 'Please enter recipient name',
      });
      return;
    }
    
    // Validate name length
    if (newAddressData.name.trim().length < 2) {
      Swal.fire({
        icon: 'warning',
        title: 'Invalid Name',
        text: 'Name must be at least 2 characters',
      });
      return;
    }
    
    // Phone validation
    const phoneValidation = validatePhoneNumber(newAddressData.phone);
    if (!phoneValidation.isValid) {
      Swal.fire({
        icon: 'warning',
        title: 'Invalid Phone Number',
        text: phoneValidation.message,
      });
      return;
    }
    
    if (!newAddressData.region) {
      Swal.fire({
        icon: 'warning',
        title: 'Required Field',
        text: 'Please select region',
      });
      return;
    }
    if (!newAddressData.province) {
      Swal.fire({
        icon: 'warning',
        title: 'Required Field',
        text: 'Please select province',
      });
      return;
    }
    if (!newAddressData.city) {
      Swal.fire({
        icon: 'warning',
        title: 'Required Field',
        text: 'Please select city',
      });
      return;
    }
    if (!newAddressData.barangay) {
      Swal.fire({
        icon: 'warning',
        title: 'Required Field',
        text: 'Please select barangay',
      });
      return;
    }
    if (!newAddressData.address.trim()) {
      Swal.fire({
        icon: 'warning',
        title: 'Required Field',
        text: 'Please enter street address (house number, street name)',
      });
      return;
    }
    
    // Validate address length
    if (newAddressData.address.trim().length < 5) {
      Swal.fire({
        icon: 'warning',
        title: 'Invalid Address',
        text: 'Street address must be at least 5 characters',
      });
      return;
    }
    
    // Validate postal code format if provided
    if (newAddressData.postal_code.trim()) {
      const postalRegex = /^\d{4}$/;
      if (!postalRegex.test(newAddressData.postal_code.trim())) {
        Swal.fire({
          icon: 'warning',
          title: 'Invalid Postal Code',
          text: 'Postal code must be 4 digits (e.g., 4100)',
        });
        return;
      }
    }
    
    if (!user) {
      // Guest user - save locally only
      const nextId = addresses.length > 0 ? Math.max(...addresses.map(a => a.id)) + 1 : 1;
      const newAddress = {
        id: nextId,
        name: newAddressData.name.trim(),
        phone: newAddressData.phone.trim(),
        region: newAddressData.region,
        province: newAddressData.province,
        city: newAddressData.city,
        barangay: newAddressData.barangay,
        postal_code: newAddressData.postal_code.trim(),
        address: `${newAddressData.address.trim()}, ${newAddressData.barangay}, ${newAddressData.city}, ${newAddressData.province}, ${newAddressData.region}`,
        address_line: newAddressData.address.trim(),
        latitude: newAddressData.latitude,
        longitude: newAddressData.longitude,
        is_default: newAddressData.is_default,
      };
      setAddresses(prev => [...prev, newAddress]);
      handleSelectAddress(newAddress);
      setShowAddAddressModal(false);
      return;
    }
    
    // Authenticated user - save to backend
    setAddressSaving(true);
    try {
      const response = await fetch('/api/user/addresses', {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        },
        body: JSON.stringify({
          name: newAddressData.name.trim(),
          phone: newAddressData.phone.trim(),
          region: newAddressData.region,
          province: newAddressData.province,
          city: newAddressData.city,
          barangay: newAddressData.barangay,
          postal_code: newAddressData.postal_code.trim(),
          address_line: newAddressData.address.trim(),
          latitude: newAddressData.latitude,
          longitude: newAddressData.longitude,
          is_default: newAddressData.is_default,
        }),
      });
      
      if (!response.ok) {
        const errorData = await response.json();
        throw new Error(errorData.message || 'Failed to save address');
      }
      
      const data = await response.json();
      
      Swal.fire({
        icon: 'success',
        title: 'Address Saved',
        text: data.message || 'Your address has been saved successfully.',
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3000,
      });
      
      // Reload addresses from backend
      await loadAddresses();
      
      // Select the newly created address
      if (data.address) {
        const formattedAddress = {
          ...data.address,
          address: `${data.address.address_line}, ${data.address.barangay}, ${data.address.city}, ${data.address.province}, ${data.address.region}`,
        };
        handleSelectAddress(formattedAddress);
      }
      
      setShowAddAddressModal(false);
    } catch (error: any) {
      console.error('Failed to save address:', error);
      Swal.fire({
        icon: 'error',
        title: 'Save Failed',
        text: error.message || 'Failed to save address. Please try again.',
      });
    } finally {
      setAddressSaving(false);
    }
  };

  const toggleSelectItem = (id: string) => {
    const targetItem = items.find((item) => item.id === id);
    if (!targetItem) return;

    const targetShopKey = getItemShopKey(targetItem);
    const isCurrentlySelected = selectedItems.has(id);

    if (!isCurrentlySelected && selectedShopKey && selectedShopKey !== targetShopKey) {
      Swal.fire({
        icon: 'info',
        title: 'Single Shop Selection',
        text: `You can only select items from ${selectedShopInfo?.name || 'one shop'} at a time. Deselect current items first to choose another shop.`,
      });
      return;
    }

    setSelectedItems(prev => {
      const newSet = new Set(prev);
      if (newSet.has(id)) {
        newSet.delete(id);
      } else {
        newSet.add(id);
      }
      return newSet;
    });
  };

  const handleCheckout = async () => {
    // Get selected cart items
    const selectedCartItems = items.filter(item => selectedItems.has(item.id));
    
    if (selectedCartItems.length === 0) {
      Swal.fire('No Items Selected', 'Please select at least one item to checkout', 'warning');
      return;
    }

    const selectedShopIds = Array.from(new Set(
      selectedCartItems
        .map((item) => String(item.shop_owner_id || item.shop_id || '').trim())
        .filter((shopId) => shopId !== '' && shopId !== 'general')
    ));

    if (selectedShopIds.length > 1) {
      Swal.fire({
        icon: 'warning',
        title: 'Single Shop Checkout Only',
        text: 'You can only place an order for products from one shop at a time. Please select items from a single shop.',
      });
      return;
    }
    
    // Validate that all items have a product ID
    const itemsWithoutPid = selectedCartItems.filter(item => !item.pid);
    if (itemsWithoutPid.length > 0) {
      console.error('Cart items missing product ID:', itemsWithoutPid);
      Swal.fire({
        icon: 'error',
        title: 'Cart Error',
        text: 'Some items in your cart are missing product information. Please refresh the page and try again.',
      });
      return;
    }
    
    // Get the selected address details if using structured address
    const selectedAddress = addresses.find(addr => addr.id === selectedAddressId);
    
    // Store the IDs of selected items for later removal after successful payment
    const selectedItemIds = selectedCartItems.map(item => item.id);
    
    // Prepare checkout data - ensure pid is properly included
    const checkoutData = {
      items: selectedCartItems.map(item => ({
        id: item.id, // Cart item ID (for tracking)
        pid: parseInt(item.pid as string), // Product ID (must be integer)
        name: item.name,
        price: item.price,
        qty: item.qty,
        size: item.size,
        color: item.color,
        image: item.image,
        options: item.options,
      })),
      total_amount: subtotal,
      customer_name: customerName,
      customer_email: customerEmail,
      customer_phone: customerPhone,
      shipping_address: shippingAddress,
      // Include structured address data if available
      address_id: selectedAddressId,
      shipping_region: selectedAddress?.region || null,
      shipping_province: selectedAddress?.province || null,
      shipping_city: selectedAddress?.city || null,
      shipping_barangay: selectedAddress?.barangay || null,
      shipping_postal_code: selectedAddress?.postal_code || null,
      shipping_address_line: selectedAddress?.address || null,
      order_note: orderNote,
      payment_method: 'paymongo',
      // Store selected item IDs so we know which items to remove after successful payment
      selected_item_ids: selectedItemIds,
    };
    


    sessionStorage.setItem('checkoutData', JSON.stringify(checkoutData));
    
    // Redirect to payment page
    router.visit('/payment');
  };

  return (
    <div className="min-h-screen flex flex-col bg-white">
      <style>{`
        select {
          position: relative;
        }
        /* Force Chrome/Safari select dropdown to open downward */
        select:focus {
          box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.5);
        }
        /* Allow select options to overflow container */
        .form-content select {
          z-index: 50;
        }
      `}</style>
      <Head title="Cart" />

      <Navigation />

      <main className="flex-1 pt-16 xl:pt-28">
        <div className="max-w-7xl mx-auto pt-0 pb-6 xl:py-12 px-6 text-black">
        {isCartLoading ? (
          <div className="min-h-[72vh] flex flex-col items-center justify-center">
            <div className="relative mb-5">
              <div className="h-20 w-20 rounded-full border-4 border-slate-200 border-t-[#16233b] animate-spin" />
              <div className="absolute inset-0 flex items-center justify-center">
                <svg xmlns="http://www.w3.org/2000/svg" className="w-8 h-8 text-[#16233b]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M3 3h2l1 5h13l1-4H7" />
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M16 16a2 2 0 11-4 0 2 2 0 014 0zm-8 0a2 2 0 11-4 0 2 2 0 014 0z" />
                </svg>
              </div>
            </div>
            <p className="text-xl font-semibold text-slate-900 tracking-wide">Loading...</p>
          </div>
        ) : (
          <>
        {recoveryOrderId && recoveryReason && (
          <div className="mb-6 rounded-lg border border-amber-300 bg-amber-50 p-4">
            <p className="text-sm font-medium text-amber-900 mb-2">
              {recoveryReason === 'expired'
                ? 'Your payment session expired. Create a new payment session to continue checkout.'
                : 'Your previous payment was not completed. Create a new payment session to continue checkout.'}
            </p>
            <button
              type="button"
              onClick={handleCreateRecoverySession}
              disabled={isCreatingRecoverySession}
              className={`inline-flex items-center rounded-md px-4 py-2 text-sm font-semibold text-white ${isCreatingRecoverySession ? 'bg-gray-400 cursor-not-allowed' : 'bg-gray-900 hover:bg-black'}`}
            >
              {isCreatingRecoverySession ? 'Creating...' : 'Create New Payment'}
            </button>
          </div>
        )}

        <div className="xl:hidden">
          {items.length === 0 ? (
            <div className="min-h-[72vh] flex flex-col items-center justify-center text-center text-black px-6">
              <div className="relative rounded-full bg-linear-to-br from-slate-100 to-slate-200 p-6 shadow-inner">
                <svg xmlns="http://www.w3.org/2000/svg" className="w-16 h-16 text-slate-700" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M3 3h2l1 5h13l1-4H7" />
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M16 16a2 2 0 11-4 0 2 2 0 014 0zm-8 0a2 2 0 11-4 0 2 2 0 014 0z" />
                </svg>
                <span className="absolute -top-1 -right-1 bg-[#16233b] text-white rounded-full w-7 h-7 flex items-center justify-center text-xs font-bold">0</span>
              </div>

              <h2 className="mt-6 text-2xl font-bold text-slate-900">Your cart is empty</h2>
              <p className="mt-2 text-sm text-slate-600 max-w-xs">Discover your next pair and add products here. Your checkout will update instantly.</p>

              <Link href="/products" className="mt-6 inline-flex items-center justify-center rounded-lg bg-[#16233b] px-6 py-3 text-sm font-semibold text-white shadow-md transition hover:bg-[#1b2c48]">Continue shopping</Link>
            </div>
          ) : (
            <>
              <div className="pb-32 xl:pb-0 pt-0">
                {shopGroups.map((shopGroup) => {
                  return (
                    <div key={shopGroup.shopKey} className="border-b border-gray-100 py-4">
                      <div className="w-full mb-3 px-4 py-2">
                        <p className="truncate text-sm font-bold text-black">{shopGroup.shopName}</p>
                      </div>

                      <div>
                        {shopGroup.items.map((item) => (
                          (() => {
                            const isDisabledByShop = !!selectedShopKey && selectedShopKey !== getItemShopKey(item) && !selectedItems.has(item.id);

                            return (
                          <div
                            key={item.id}
                            className="border-b border-gray-100 py-3 px-4 flex flex-col"
                          >
                            <div className="flex items-start gap-3 mb-3">
                              <button
                                type="button"
                                aria-label={`Select item ${item.name}`}
                                title={`Select item ${item.name}`}
                                onClick={() => toggleSelectItem(item.id)}
                                disabled={isDisabledByShop}
                                className={`mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full transition-all ${
                                  selectedItems.has(item.id)
                                    ? 'bg-[#16233b] text-white shadow-md'
                                    : isDisabledByShop
                                      ? 'border-2 border-gray-200 bg-gray-100 text-transparent cursor-not-allowed opacity-60'
                                      : 'border-2 border-gray-300 bg-white text-transparent'
                                }`}
                              >
                                <svg className="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                  <path fillRule="evenodd" d="M16.704 5.29a1 1 0 010 1.414l-8.1 8.1a1 1 0 01-1.414 0L3.296 10.91a1 1 0 111.414-1.414l3.188 3.188 7.393-7.393a1 1 0 011.414 0z" clipRule="evenodd" />
                                </svg>
                              </button>

                              <div className="h-20 w-20 shrink-0 overflow-hidden rounded-lg bg-gray-100">
                                {item.image ? <img src={item.image} alt={item.name} className="h-full w-full object-cover" /> : null}
                              </div>

                              <div className="min-w-0 flex-1">
                                <p className="line-clamp-2 text-sm font-medium text-black leading-tight">{item.name}</p>

                                <div className="mt-0.5 flex flex-wrap items-center gap-1 text-xs text-gray-600">
                                  {item.size && <span>{item.size}</span>}
                                  {item.color && item.size && <span>•</span>}
                                  {item.color && <span>{item.color}</span>}
                                </div>

                                <div className="mt-1.5 flex items-center">
                                  <span className="text-base font-bold text-black">₱{item.price.toLocaleString()}</span>
                                </div>
                              </div>
                            </div>

                            <div className="flex items-center justify-between gap-2 pl-9">
                              <button onClick={() => removeItemPersist(item.id)} className="text-xs font-medium text-gray-600 hover:text-gray-900">
                                Remove
                              </button>

                              <div className="inline-flex items-center border border-gray-300 rounded">
                                <button 
                                  onClick={() => decrement(item.id)} 
                                  className="p-1.5 text-gray-600 hover:bg-gray-50 transition-colors"
                                  title="Decrease quantity"
                                >
                                  <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M20 12H4" />
                                  </svg>
                                </button>
                                
                                <div className="border-l border-r border-gray-300 px-3 py-1.5 text-xs font-semibold text-black min-w-7 text-center">
                                  {item.qty}
                                </div>
                                
                                <button
                                  onClick={() => increment(item.id)}
                                  disabled={qtyUpdating[item.id] || (item.stock_quantity !== undefined && item.qty >= item.stock_quantity)}
                                  className={`p-1.5 transition-colors ${(qtyUpdating[item.id] || (item.stock_quantity !== undefined && item.qty >= item.stock_quantity)) ? 'text-gray-400 cursor-not-allowed' : 'text-gray-600 hover:bg-gray-50'}`}
                                  title="Increase quantity"
                                >
                                  <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                                  </svg>
                                </button>
                              </div>
                            </div>
                          </div>
                            );
                          })()
                        ))}
                      </div>
                    </div>
                  );
                })}
              </div>


            </>
          )}
        </div>

        {items.length > 0 && (
          <div className="xl:hidden fixed inset-x-0 bottom-0 z-40 border-t border-gray-200 bg-white px-4 py-3 shadow-[0_-2px_8px_rgba(0,0,0,0.1)]">
            <div className="flex items-center gap-3">
              <button
                type="button"
                onClick={handleCheckout}
                disabled={selectedCount === 0 || isPaying}
                className={`flex-1 rounded-lg px-4 py-3 text-sm font-bold text-white transition-all ${
                  selectedCount === 0 || isPaying ? 'bg-gray-400 cursor-not-allowed' : 'bg-[#16233b] hover:bg-[#1a2942] shadow-md'
                }`}
              >
                {isPaying ? 'Placing...' : `Place Order (${selectedCount})`}
              </button>
            </div>
          </div>
        )}

        <div className="hidden xl:grid grid-cols-1 md:grid-cols-3 gap-8 items-start">
          {/* Left: cart items (span 2 on md) */}
          <div className={items.length === 0 ? 'md:col-span-3' : 'md:col-span-2'}>
            <div className={items.length === 0 ? 'rounded bg-white' : 'rounded-2xl border border-gray-200 bg-white shadow-sm'}>
              {items.length > 0 && (
                <div className="hidden md:grid grid-cols-12 gap-4 p-4 border-b text-sm font-medium text-black">
                  <div className="col-span-1" />
                  <div className="col-span-5">Product</div>
                  <div className="col-span-3 text-center">Quantity</div>
                  <div className="col-span-3 text-right">Total</div>
                </div>
              )}

              <div>
                {items.length === 0 ? (
                  <div className="min-h-[62vh] flex flex-col items-center justify-center text-center text-black rounded-2xl border border-slate-200 bg-linear-to-b from-white to-slate-50 px-8">
                    <div className="relative rounded-full bg-linear-to-br from-slate-100 to-slate-200 p-6 shadow-inner">
                      <svg xmlns="http://www.w3.org/2000/svg" className="w-16 h-16 text-slate-700" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M3 3h2l1 5h13l1-4H7" />
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M16 16a2 2 0 11-4 0 2 2 0 014 0zm-8 0a2 2 0 11-4 0 2 2 0 014 0z" />
                      </svg>
                      <span className="absolute -top-1 -right-1 bg-[#16233b] text-white rounded-full w-7 h-7 flex items-center justify-center text-xs font-bold">0</span>
                    </div>

                    <h2 className="mt-6 text-2xl font-bold text-slate-900">Your cart is empty</h2>
                    <p className="mt-2 max-w-md text-sm text-slate-600">Start shopping to see your selected products here. Once you add items, you can review quantity, shop grouping, and proceed to checkout.</p>

                    <Link href="/products" className="mt-6 inline-flex items-center justify-center rounded-lg bg-[#16233b] px-6 py-3 text-sm font-semibold text-white shadow-md transition hover:bg-[#1b2c48]">Continue shopping</Link>
                  </div>
                ) : (
                  shopGroups.map((shopGroup) => {
                    return (
                      <div key={shopGroup.shopKey} className="border-b last:border-b-0">
                        <div className="grid grid-cols-12 gap-4 items-center px-6 py-4 bg-gray-50/80 border-b border-gray-200">
                          <div className="col-span-12">
                            <p className="text-sm font-semibold text-black">{shopGroup.shopName}</p>
                          </div>
                        </div>

                        {shopGroup.items.map((item) => (
                          (() => {
                            const isDisabledByShop = !!selectedShopKey && selectedShopKey !== getItemShopKey(item) && !selectedItems.has(item.id);

                            return (
                          <div key={item.id} className="grid grid-cols-1 md:grid-cols-12 gap-4 items-center p-6 border-b last:border-b-0">
                            <div className="md:col-span-1 flex items-center justify-center">
                              <input
                                type="checkbox"
                                checked={selectedItems.has(item.id)}
                                onChange={() => toggleSelectItem(item.id)}
                                disabled={isDisabledByShop}
                                aria-label={`Select item ${item.name}`}
                                title={`Select item ${item.name}`}
                                className={`w-4 h-4 rounded border-gray-300 text-black focus:ring-black ${isDisabledByShop ? 'cursor-not-allowed opacity-50' : 'cursor-pointer'}`}
                              />
                            </div>
                            <div className="md:col-span-5 flex items-center space-x-6">
                              <div className="w-24 h-24 bg-gray-50 rounded overflow-hidden flex items-center justify-center border">
                                {item.image ? <img src={item.image} alt={item.name} className="w-full h-full object-cover" /> : null}
                              </div>
                              <div>
                                <div className="font-semibold text-sm text-black">{item.name}</div>
                                <div className="text-sm text-black/70 mt-1">₱{item.price.toLocaleString()}</div>
                                <div className="flex gap-2 mt-1">
                                  {item.size && <div className="text-sm text-black/70">Size: {item.size}</div>}
                                  {item.color && <div className="text-sm text-black/70">Color: {item.color}</div>}
                                </div>
                              </div>
                            </div>

                            <div className="md:col-span-3 flex flex-col items-center">
                              <div className="inline-flex items-center border rounded-md overflow-hidden">
                                <button onClick={() => decrement(item.id)} className="px-3 py-2 text-sm text-black">-</button>
                                <div className="px-5 py-2 text-sm text-black">{item.qty}</div>
                                <button
                                  onClick={() => increment(item.id)}
                                  disabled={qtyUpdating[item.id] || (item.stock_quantity !== undefined && item.qty >= item.stock_quantity)}
                                  className={`px-3 py-2 text-sm ${(qtyUpdating[item.id] || (item.stock_quantity !== undefined && item.qty >= item.stock_quantity)) ? 'text-gray-400 cursor-not-allowed' : 'text-black'}`}
                                >+</button>
                              </div>
                              {item.stock_quantity !== undefined && item.qty >= item.stock_quantity && (
                                <div className="text-xs text-orange-600 mt-1">Max stock reached</div>
                              )}
                              <button onClick={() => removeItemPersist(item.id)} className="mt-2 text-xs text-black underline">Remove</button>
                            </div>

                            <div className="md:col-span-3 text-right font-semibold">₱{(item.price * item.qty).toLocaleString()}</div>
                          </div>
                            );
                          })()
                        ))}
                      </div>
                    );
                  })
                )}
              </div>
            </div>
          </div>

          {/* Right: summary (only when items exist) */}
          {items.length > 0 && !isCartLoading && (
            <aside>

            {/* Address Selection Modal */}
            {showAddressSelector && (
              <>
                {/* Backdrop */}
                <div className="fixed inset-0 bg-black/60 backdrop-blur-sm z-[100000] pointer-events-auto" onClick={() => setShowAddressSelector(false)} />
                
                {/* Modal */}
                <div className="fixed inset-0 z-[100001] flex items-center justify-center p-4">
                  <div className="bg-white dark:bg-gray-900 rounded-lg shadow-xl max-w-lg w-full max-h-[90vh] overflow-hidden border border-gray-200 dark:border-gray-800 flex flex-col">
                    
                    {/* Header */}
                    <div className="border-b border-gray-200 dark:border-gray-800 px-6 py-4 flex items-center justify-between flex-shrink-0">
                      <h3 className="text-2xl font-bold text-gray-900 dark:text-white">Your Addresses</h3>
                      <button
                        onClick={() => setShowAddressSelector(false)}
                        aria-label="Close address selector"
                        title="Close address selector"
                        className="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors"
                      >
                        <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                        </svg>
                      </button>
                    </div>

                    {/* Content - scrollable */}
                    <div className="overflow-y-auto flex-1 p-6">
                      <div className="space-y-3">
                        {addresses.map((address: any) => (
                        <button
                          key={address.id}
                          onClick={() => {
                            handleSelectAddress(address);
                            setShowAddressSelector(false);
                          }}
                          className={`w-full p-4 border rounded-lg text-left transition-all ${
                            selectedAddressId === address.id
                              ? 'border-blue-600 bg-blue-50 dark:border-blue-500 dark:bg-blue-500/10'
                              : 'border-gray-300 dark:border-gray-700 hover:border-gray-400 dark:hover:border-gray-600'
                          }`}
                        >
                          <div className="flex items-start gap-3">
                            <div className="flex-1">
                              <p className="font-semibold text-gray-900 dark:text-white">{address.name}</p>
                              {address.phone && <p className="text-sm text-gray-600 dark:text-gray-400">{address.phone}</p>}
                              <p className="text-sm text-gray-600 dark:text-gray-400 mt-1">{address.address}</p>
                            </div>
                            <div className="flex items-center gap-2 flex-shrink-0">
                              <button
                                type="button"
                                onClick={(e) => {
                                  e.stopPropagation();
                                  handleEditAddress(address);
                                }}
                                className="p-2 text-blue-600 dark:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-500/10 rounded transition-colors"
                                title="Edit address"
                              >
                                <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                </svg>
                              </button>
                              {(address.latitude == null || address.longitude == null) && (
                                <button
                                  type="button"
                                  onClick={(e) => {
                                    e.stopPropagation();
                                    handleEditAddress(address, true);
                                  }}
                                  className="px-2 py-1 text-sm font-semibold text-blue-700 hover:underline dark:text-blue-300"
                                >
                                  Repin address
                                </button>
                              )}
                              <button
                                type="button"
                                onClick={(e) => {
                                  e.stopPropagation();
                                  handleDeleteAddress(address.id);
                                }}
                                className="p-2 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10 rounded transition-colors"
                                title="Delete address"
                              >
                                <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                </svg>
                              </button>
                              <div className={`mt-1 flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2 ${selectedAddressId === address.id ? 'border-blue-600' : 'border-gray-300'} bg-white dark:bg-gray-800`}>
                                {selectedAddressId === address.id && (
                                  <div className="h-3 w-3 rounded-full bg-blue-600" />
                                )}
                              </div>
                            </div>
                          </div>
                        </button>
                        ))}
                      </div>
                    </div>

                    {/* Footer - buttons */}
                    <div className="border-t border-gray-200 dark:border-gray-800 p-6 flex flex-col gap-3 flex-shrink-0">
                      <button 
                        onClick={openAddAddressModal}
                        className="w-full py-3 border border-black text-black rounded-lg hover:bg-gray-50 transition-colors font-medium"
                      >
                        + Add New Address
                      </button>

                      <button
                        onClick={() => setShowAddressSelector(false)}
                        className="w-full py-3 border border-black text-black rounded-lg hover:bg-gray-50 transition-colors font-medium"
                      >
                        Confirm
                      </button>
                    </div>
                  </div>
                </div>
              </>
            )}

            {/* Add Address Modal */}
            {showAddAddressModal && (
              <>
                <div className="fixed inset-0 bg-black/60 backdrop-blur-sm z-[100000] pointer-events-auto" onClick={() => setShowAddAddressModal(false)} />
                <div className="fixed inset-0 z-[100001] flex items-center justify-center p-4">
                  <div className="bg-white dark:bg-gray-900 rounded-lg shadow-xl max-w-5xl w-full max-h-[90vh] border border-gray-200 dark:border-gray-800 flex flex-col">
                    <div className="border-b border-gray-200 dark:border-gray-800 px-4 py-3 flex items-center justify-between flex-shrink-0 sticky top-0 bg-white dark:bg-gray-900 z-10">
                      <h3 className="text-xl font-bold text-gray-900 dark:text-white">Add New Address</h3>
                      <button
                        onClick={() => setShowAddAddressModal(false)}
                        aria-label="Close add address modal"
                        title="Close add address modal"
                        className="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors"
                      >
                        <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                        </svg>
                      </button>
                    </div>

                    <div className="overflow-y-auto flex-1 p-4">
                      
                      <div className="grid grid-cols-1 md:grid-cols-2 gap-3 overflow-visible form-content">
                      <div>
                        <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                          Full Name <span className="text-red-500">*</span>
                        </label>
                        <input
                          type="text"
                          value={newAddressData.name}
                          onChange={(e) => setNewAddressData(prev => ({ ...prev, name: e.target.value }))}
                          className="w-full px-3 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none"
                          placeholder="Full Name"
                          required
                        />
                      </div>
                      <div>
                        <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                          Phone Number <span className="text-red-500">*</span>
                        </label>
                        <input
                          type="tel"
                          value={newAddressData.phone}
                          onChange={(e) => setNewAddressData(prev => ({ ...prev, phone: e.target.value }))}
                          className={`w-full px-3 py-1.5 text-sm border rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 outline-none ${
                            newAddressData.phone && !validatePhoneNumber(newAddressData.phone).isValid
                              ? 'border-red-500 focus:ring-red-500'
                              : 'border-gray-300 dark:border-gray-600 focus:ring-blue-500'
                          }`}
                          placeholder="09XX XXX XXXX or +639XX XXX XXXX"
                          required
                        />
                        {newAddressData.phone && !validatePhoneNumber(newAddressData.phone).isValid && (
                          <p className="mt-1 text-xs text-red-500">
                            {validatePhoneNumber(newAddressData.phone).message}
                          </p>
                        )}
                      </div>

                      {/* Region */}
                      <div>
                        <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                          Region <span className="text-red-500">*</span>
                        </label>
                        <input
                          type="text"
                          value={newAddressData.region}
                          onChange={(e) => setNewAddressData(prev => ({ ...prev, region: e.target.value }))}
                          className="w-full px-3 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none"
                          placeholder="Region"
                        />
                      </div>

                      {/* Province */}
                      <div>
                        <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                          Province <span className="text-red-500">*</span>
                        </label>
                        <input
                          type="text"
                          value={newAddressData.province}
                          onChange={(e) => setNewAddressData(prev => ({ ...prev, province: e.target.value }))}
                          className="w-full px-3 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none"
                          placeholder="Province"
                        />
                      </div>

                      {/* City */}
                      <div>
                        <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                          City <span className="text-red-500">*</span>
                        </label>
                        <input
                          type="text"
                          value={newAddressData.city}
                          onChange={(e) => setNewAddressData(prev => ({ ...prev, city: e.target.value }))}
                          className="w-full px-3 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none"
                          placeholder="City"
                        />
                      </div>

                      {/* Barangay */}
                      <div>
                        <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                          Barangay <span className="text-red-500">*</span>
                        </label>
                        <input
                          type="text"
                          value={newAddressData.barangay}
                          onChange={(e) => setNewAddressData(prev => ({ ...prev, barangay: e.target.value }))}
                          className="w-full px-3 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none"
                          placeholder="Barangay"
                        />
                      </div>

                      {/* Postal Code */}
                      <div>
                        <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                          Postal Code <span className="text-gray-400">(Optional)</span>
                        </label>
                        <input
                          type="text"
                          maxLength={4}
                          value={newAddressData.postal_code}
                          onChange={(e) => {
                            const value = e.target.value.replace(/\D/g, ''); // Only digits
                            setNewAddressData(prev => ({ ...prev, postal_code: value }));
                          }}
                          className={`w-full px-3 py-1.5 text-sm border rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 outline-none ${
                            newAddressData.postal_code && !/^\d{4}$/.test(newAddressData.postal_code)
                              ? 'border-yellow-500 focus:ring-yellow-500'
                              : 'border-gray-300 dark:border-gray-600 focus:ring-blue-500'
                          }`}
                          placeholder="e.g., 4100"
                        />
                        {newAddressData.postal_code && !/^\d{4}$/.test(newAddressData.postal_code) && (
                          <p className="mt-1 text-xs text-yellow-600 dark:text-yellow-500">
                            Postal code should be 4 digits
                          </p>
                        )}
                      </div>
                      </div>

                      {/* Address - Full Width */}
                      <div>
                        <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                          Street Address <span className="text-red-500">*</span>
                          <span className="text-xs text-gray-500 ml-2">({newAddressData.address.length} characters)</span>
                        </label>
                        <textarea
                          value={newAddressData.address}
                          onChange={(e) => setNewAddressData(prev => ({ ...prev, address: e.target.value }))}
                          className={`w-full px-3 py-1.5 text-sm border rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 outline-none h-16 resize-none ${
                            newAddressData.address && newAddressData.address.trim().length < 5
                              ? 'border-red-500 focus:ring-red-500'
                              : 'border-gray-300 dark:border-gray-600 focus:ring-blue-500'
                          }`}
                          placeholder="House/Unit number, Street name, Subdivision/Building (min. 5 characters)"
                          required
                        />
                        {newAddressData.address && newAddressData.address.trim().length < 5 && (
                          <p className="mt-1 text-xs text-red-500">
                            Street address must be at least 5 characters
                          </p>
                        )}
                      </div>

                      <div ref={addressMapRef} className="mt-4">
                        <p className="mb-2 text-sm text-gray-600 dark:text-gray-300">
                          Pin the exact delivery entrance, then check the address details above.
                        </p>
                        <CustomerAddressMapPicker
                          value={newAddressData.latitude !== null && newAddressData.longitude !== null
                            ? { latitude: newAddressData.latitude, longitude: newAddressData.longitude }
                            : null}
                          onChange={(location) => setNewAddressData((prev) => ({
                            ...prev,
                            latitude: location.latitude,
                            longitude: location.longitude,
                            region: location.region,
                            province: location.province,
                            city: location.city,
                            barangay: location.barangay,
                            postal_code: location.postalCode,
                          }))}
                        />
                      </div>

                      <div className="flex items-center justify-between pt-2 mt-2">
                        <label className="text-sm font-medium text-gray-700 dark:text-gray-300">Set as default address</label>
                        <button
                          onClick={() => setNewAddressData(prev => ({ ...prev, is_default: !prev.is_default }))}
                          aria-label="Toggle default address"
                          title="Toggle default address"
                          className={`relative inline-flex h-6 w-11 items-center rounded-full transition-colors ${
                            newAddressData.is_default ? 'bg-blue-500' : 'bg-gray-300 dark:bg-gray-600'
                          }`}
                        >
                          <span
                            className={`inline-block h-4 w-4 transform rounded-full bg-white transition-transform ${
                              newAddressData.is_default ? 'translate-x-6' : 'translate-x-1'
                            }`}
                          />
                        </button>
                      </div>
                    </div>

                    <div className="border-t border-gray-200 dark:border-gray-800 p-4 flex gap-3 sticky bottom-0 bg-white dark:bg-gray-900">
                      <button
                        onClick={() => setShowAddAddressModal(false)}
                        className="flex-1 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors font-medium"
                      >
                        Cancel
                      </button>
                      <button
                        onClick={handleSaveNewAddress}
                        className="flex-1 py-2 border border-black text-black rounded-lg hover:bg-gray-50 transition-colors font-medium"
                      >
                        Save Address
                      </button>
                    </div>
                  </div>
                </div>
              </>
            )}

            {/* Edit Address Modal */}
            {editingAddressId !== null && editingAddressData && (
              <>
                <div className="fixed inset-0 bg-black/60 backdrop-blur-sm z-[100000] pointer-events-auto" onClick={() => setEditingAddressId(null)} />
                <div className="fixed inset-0 z-[100001] flex items-center justify-center p-4">
                  <div className="bg-white dark:bg-gray-900 rounded-lg shadow-xl max-w-2xl w-full border border-gray-200 dark:border-gray-800 max-h-[90vh] flex flex-col">
                    
                    {/* Header */}
                    <div className="border-b border-gray-200 dark:border-gray-800 px-6 py-4 flex items-center justify-between flex-shrink-0">
                      <h3 className="text-2xl font-bold text-gray-900 dark:text-white">Edit Address</h3>
                      <button
                        onClick={() => setEditingAddressId(null)}
                        aria-label="Close edit address modal"
                        title="Close edit address modal"
                        className="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors"
                      >
                        <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                        </svg>
                      </button>
                    </div>

                    {/* Content - Scrollable */}
                    <div className="p-6 overflow-y-auto flex-1">
                      
                      <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                        {/* Full Name */}
                        <div>
                          <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                            Full Name <span className="text-red-500">*</span>
                          </label>
                          <input
                            type="text"
                            value={editingAddressData.name || ''}
                            onChange={(e) => setEditingAddressData({ ...editingAddressData, name: e.target.value })}
                            className="w-full px-3 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none"
                            placeholder="Full Name"
                            required
                          />
                        </div>
                        
                        {/* Phone Number */}
                        <div>
                          <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                            Phone Number <span className="text-red-500">*</span>
                          </label>
                          <input
                            type="tel"
                            value={editingAddressData.phone || ''}
                            onChange={(e) => setEditingAddressData({ ...editingAddressData, phone: e.target.value })}
                            className={`w-full px-3 py-1.5 text-sm border rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 outline-none ${
                              editingAddressData.phone && !validatePhoneNumber(editingAddressData.phone).isValid
                                ? 'border-red-500 focus:ring-red-500'
                                : 'border-gray-300 dark:border-gray-600 focus:ring-blue-500'
                            }`}
                            placeholder="09XX XXX XXXX or +639XX XXX XXXX"
                            required
                          />
                          {editingAddressData.phone && !validatePhoneNumber(editingAddressData.phone).isValid && (
                            <p className="mt-1 text-xs text-red-500">
                              {validatePhoneNumber(editingAddressData.phone).message}
                            </p>
                          )}
                        </div>

                        {/* Region */}
                        <div>
                          <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                            Region <span className="text-red-500">*</span>
                          </label>
                          <input
                            type="text"
                            value={editingAddressData.region || ''}
                            onChange={(e) => setEditingAddressData((prev: any) => ({ ...prev, region: e.target.value }))}
                            className="w-full px-3 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none"
                            placeholder="Region"
                          />
                        </div>

                        {/* Province */}
                        <div>
                          <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                            Province <span className="text-red-500">*</span>
                          </label>
                          <input
                            type="text"
                            value={editingAddressData.province || ''}
                            onChange={(e) => setEditingAddressData((prev: any) => ({ ...prev, province: e.target.value }))}
                            className="w-full px-3 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none"
                            placeholder="Province"
                          />
                        </div>

                        {/* City */}
                        <div>
                          <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                            City <span className="text-red-500">*</span>
                          </label>
                          <input
                            type="text"
                            value={editingAddressData.city || ''}
                            onChange={(e) => setEditingAddressData((prev: any) => ({ ...prev, city: e.target.value }))}
                            className="w-full px-3 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none"
                            placeholder="City"
                          />
                        </div>

                        {/* Barangay */}
                        <div>
                          <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                            Barangay <span className="text-red-500">*</span>
                          </label>
                          <input
                            type="text"
                            value={editingAddressData.barangay || ''}
                            onChange={(e) => setEditingAddressData((prev: any) => ({ ...prev, barangay: e.target.value }))}
                            className="w-full px-3 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none"
                            placeholder="Barangay"
                          />
                        </div>

                        {/* Postal Code */}
                        <div>
                          <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                            Postal Code <span className="text-gray-400">(Optional)</span>
                          </label>
                          <input
                            type="text"
                            maxLength={4}
                            value={editingAddressData.postal_code || ''}
                            onChange={(e) => {
                              const value = e.target.value.replace(/\D/g, '');
                              setEditingAddressData((prev: any) => ({ ...prev, postal_code: value }));
                            }}
                            className={`w-full px-3 py-1.5 text-sm border rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 outline-none ${
                              editingAddressData.postal_code && !/^\d{4}$/.test(editingAddressData.postal_code)
                                ? 'border-yellow-500 focus:ring-yellow-500'
                                : 'border-gray-300 dark:border-gray-600 focus:ring-blue-500'
                            }`}
                            placeholder="e.g., 4100"
                          />
                          {editingAddressData.postal_code && !/^\d{4}$/.test(editingAddressData.postal_code) && (
                            <p className="mt-1 text-xs text-yellow-600 dark:text-yellow-500">
                              Postal code should be 4 digits
                            </p>
                          )}
                        </div>
                      </div>

                      {/* Street Address - Full Width */}
                      <div className="mt-3">
                        <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                          Street Address <span className="text-red-500">*</span>
                          <span className="text-xs text-gray-500 ml-2">({(editingAddressData.address_line || '').length} characters)</span>
                        </label>
                        <textarea
                          value={editingAddressData.address_line || ''}
                          onChange={(e) => setEditingAddressData((prev: any) => ({ ...prev, address_line: e.target.value }))}
                          className={`w-full px-3 py-1.5 text-sm border rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 outline-none h-16 resize-none ${
                            editingAddressData.address_line && editingAddressData.address_line.trim().length < 5
                              ? 'border-red-500 focus:ring-red-500'
                              : 'border-gray-300 dark:border-gray-600 focus:ring-blue-500'
                          }`}
                          placeholder="House/Unit number, Street name, Subdivision/Building (min. 5 characters)"
                          required
                        />
                        {editingAddressData.address_line && editingAddressData.address_line.trim().length < 5 && (
                          <p className="mt-1 text-xs text-red-500">
                            Street address must be at least 5 characters
                          </p>
                        )}
                      </div>

                      <div ref={addressMapRef} className="mt-4">
                        <p className="mb-2 text-sm text-gray-600 dark:text-gray-300">
                          Pin the exact delivery entrance, then check the address details above.
                        </p>
                        <CustomerAddressMapPicker
                          value={editingAddressData.latitude != null && editingAddressData.longitude != null
                            ? { latitude: editingAddressData.latitude, longitude: editingAddressData.longitude }
                            : null}
                          onChange={(location) => setEditingAddressData((prev: any) => ({
                            ...prev,
                            latitude: location.latitude,
                            longitude: location.longitude,
                            region: location.region,
                            province: location.province,
                            city: location.city,
                            barangay: location.barangay,
                            postal_code: location.postalCode,
                          }))}
                        />
                      </div>
                      
                      {/* Set as Default */}
                      <div className="flex items-center justify-between pt-2 mt-2 border-t border-gray-200 dark:border-gray-700">
                        <label className="text-sm font-medium text-gray-700 dark:text-gray-300">Set as default address</label>
                        <button
                          type="button"
                          onClick={() => setEditingAddressData((prev: any) => ({ ...prev, is_default: !prev.is_default }))}
                          aria-label="Toggle default address"
                          title="Toggle default address"
                          className={`relative inline-flex h-6 w-11 items-center rounded-full transition-colors ${
                            editingAddressData.is_default ? 'bg-blue-600' : 'bg-gray-300 dark:bg-gray-600'
                          }`}
                        >
                          <span
                            className={`inline-block h-4 w-4 transform rounded-full bg-white transition-transform ${
                              editingAddressData.is_default ? 'translate-x-6' : 'translate-x-1'
                            }`}
                          />
                        </button>
                      </div>
                    </div>

                    {/* Footer */}
                    <div className="border-t border-gray-200 dark:border-gray-800 p-6 flex gap-3 flex-shrink-0">
                      <button
                        onClick={() => setEditingAddressId(null)}
                        disabled={addressSaving}
                        className="flex-1 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors font-medium disabled:opacity-50 disabled:cursor-not-allowed"
                      >
                        Cancel
                      </button>
                      <button
                        onClick={handleSaveEditAddress}
                        disabled={addressSaving}
                        className="flex-1 py-2 bg-blue-600 dark:bg-blue-700 text-white rounded-lg hover:bg-blue-700 dark:hover:bg-blue-800 transition-colors font-medium disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2"
                      >
                        {addressSaving ? (
                          <>
                            <svg className="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                              <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                              <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            Saving...
                          </>
                        ) : 'Save Changes'}
                      </button>
                    </div>
                  </div>
                </div>
              </>
            )}
            
            <div className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm sticky top-32">
              <div className="flex items-baseline justify-between mb-4">
                <div className="text-lg font-semibold text-black">Total</div>
                <div className="text-2xl font-extrabold text-black">₱{subtotal.toLocaleString()} PHP</div>
              </div>

              <p className="text-xs text-black/60 mb-4 leading-relaxed">Shipping fees are not included and are handled by the buyer. The shipping cost depends on the delivery location and will be paid directly by the buyer.</p>

              <textarea
                placeholder="Order note for carrier pickup branch"
                value={orderNote}
                onChange={(e) => setOrderNote(e.target.value)}
                className="w-full border rounded p-3 mb-4 text-sm h-24 resize-none text-black"
              />

              {payError && (
                <div className="text-xs text-red-600 mb-3">{payError}</div>
              )}

              <button
                onClick={handleCheckout}
                disabled={selectedCount === 0 || isPaying}
                className={`w-full flex items-center justify-center gap-3 py-3 rounded-md ${selectedCount === 0 || isPaying ? 'bg-gray-300 text-gray-600' : 'bg-gray-900 text-white'}`}
              >
                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 11V17M9 14h6"/></svg>
                {isPaying ? 'Placing Order…' : `Place Order (${selectedCount} ${selectedCount === 1 ? 'item' : 'items'})`}
              </button>
            </div>
          </aside>
          )}
        </div>
        </>
        )}
        </div>
      </main>

      <div className="hidden xl:block">
        <CheckoutFooter />
      </div>
    </div>
  );
};

export default Checkout;

// Footer: replicated SoleSpace footer used across the site
// If a shared footer component exists later, replace this markup with that component.
export const CheckoutFooter: React.FC = () => {
  return (
    <footer className="mt-48 bg-gray-100 text-slate-900">
      <div className="max-w-7xl mx-auto px-6 py-16">
        <div className="grid grid-cols-1 md:grid-cols-3 gap-8">
          <div>
            <div className="text-2xl font-bold mb-4">SoleSpace</div>
            <p className="text-sm text-slate-700 max-w-sm">Your premier destination for premium footwear and expert repair services. Experience the perfect blend of style, comfort, and craftsmanship.</p>

            <div className="flex gap-3 mt-6">
              <button className="w-10 h-10 border border-slate-300 rounded flex items-center justify-center text-slate-700">f</button>
              <button className="w-10 h-10 border border-slate-300 rounded flex items-center justify-center text-slate-700">t</button>
              <button className="w-10 h-10 border border-slate-300 rounded flex items-center justify-center text-slate-700">ig</button>
            </div>
          </div>

          <div className="flex flex-col">
            <h3 className="text-sm uppercase text-slate-700 font-semibold mb-4">Quick Links</h3>
            <nav className="flex flex-col gap-3 text-sm text-slate-700">
              <a href="/products">Products</a>
              <a href="/repair-services">Repair Services</a>
              <a href="/services">Services</a>
              <a href="/contact">Contact</a>
            </nav>
          </div>

          <div className="flex flex-col">
            <h3 className="text-sm uppercase text-slate-700 font-semibold mb-4">Services</h3>
            <nav className="flex flex-col gap-3 text-sm text-slate-700">
              <a href="#">Shoe Repair</a>
              <a href="#">Custom Fitting</a>
              <a href="#">Maintenance</a>
              <a href="#">Consultation</a>
            </nav>
          </div>
        </div>

        <div className="border-t border-slate-300 mt-10 pt-6 text-sm text-slate-700 flex items-center justify-between">
          <div>© 2024 SoleSpace. All rights reserved.</div>
          <div className="flex gap-6">
            <a href="#" className="hover:underline">Privacy</a>
            <a href="#" className="hover:underline">Terms</a>
            <a href="#" className="hover:underline">Cookies</a>
          </div>
        </div>
      </div>
    </footer>
  );
};
