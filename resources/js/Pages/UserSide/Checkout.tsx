import React, { useState, useEffect, useRef } from 'react';
import { Head, Link, usePage, router } from '@inertiajs/react';
import Navigation from './Navigation';
import Swal from 'sweetalert2';
import axios from 'axios';
import { dispatchCartAddedEvent } from '../../types/cart-events';

type CartItem = {
  id: string;
  name: string;
  price: number;
  size?: string;
  color?: string;
  qty: number;
  image?: string;
  stock_quantity?: number;
  pid?: string;
  options?: any;
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
  const [qtyUpdating, setQtyUpdating] = useState<Record<string, boolean>>({});
  const qtyUpdatingRef = useRef<Record<string, boolean>>({});
  
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
  const [newAddressData, setNewAddressData] = useState({ 
    name: '', 
    phone: '', 
    region: '', 
    province: '', 
    city: '', 
    barangay: '', 
    postal_code: '', 
    address: '', 
    is_default: false 
  });
  
  
  // Payment method state
  const [paymentMethod, setPaymentMethod] = useState<'COD' | 'Online'>('COD');

  const subtotal = items.filter(item => selectedItems.has(item.id)).reduce((s, it) => s + it.price * it.qty, 0);
  const FREE_SHIP_THRESHOLD = 4400;
  const freeShipRemaining = Math.max(0, Math.round(FREE_SHIP_THRESHOLD - subtotal));
  const progressPct = FREE_SHIP_THRESHOLD > 0 ? Math.min(100, Math.round((subtotal / FREE_SHIP_THRESHOLD) * 100)) : 0;

  // Load cart function (moved outside useEffect so it can be reused)
  const loadCart = async () => {
      if (!user) {
        // If not authenticated, load from localStorage
        try {
          const raw = localStorage.getItem('ss_cart');
          const cart = raw ? JSON.parse(raw) : [];
          const parsed = (cart || []).map((c: any) => {
            const price = (typeof c.price === 'number') ? c.price : (parseFloat(String(c.price).replace(/[^0-9.-]+/g, '')) || 0);
            const size = c.size || c.shoe_size || (c.options && c.options.size) || (c.meta && c.meta.size) || (c.attributes && c.attributes.size) || undefined;
            const color = c.color || (c.options && c.options.color) || undefined;
            return { 
              id: String(c.id), 
              name: c.name || '', 
              price, 
              size,
              color,
              qty: Number(c.qty || 1), 
              image: c.image || undefined,
              stock_quantity: c.stock_quantity || undefined,
              pid: c.pid || String(c.id)
            };
          });
          setItems(parsed);
          setSelectedItems(new Set(parsed.map(item => item.id)));
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
            const options = item.options ? (typeof item.options === 'string' ? JSON.parse(item.options) : item.options) : {};
            return {
              id: String(item.id),
              name: item.name || '',
              price: item.price || 0,
              size: item.size,
              color: options.color || undefined,
              qty: item.quantity || item.qty || 1,
              image: item.image,
              stock_quantity: item.stock_quantity,
              pid: item.product_id || item.pid,
              options: item.options, // Keep original options
            };
          });
          setItems(parsed);
          setSelectedItems(new Set(parsed.map(item => item.id)));
        }
      } catch (error) {
        console.error('Failed to load cart:', error);
        // Fallback to localStorage
        try {
          const raw = localStorage.getItem('ss_cart');
          const cart = raw ? JSON.parse(raw) : [];
          const parsed = (cart || []).map((c: any) => {
            const price = (typeof c.price === 'number') ? c.price : (parseFloat(String(c.price).replace(/[^0-9.-]+/g, '')) || 0);
            const size = c.size || undefined;
            const color = c.color || undefined;
            return { 
              id: String(c.id), 
              name: c.name || '', 
              price, 
              size,
              color,
              qty: Number(c.qty || 1), 
              image: c.image || undefined,
              stock_quantity: c.stock_quantity || undefined,
              pid: c.pid || String(c.id)
            };
          });
          setItems(parsed);
          setSelectedItems(new Set(parsed.map(item => item.id)));
        } catch (e) {
          setItems([]);
        }
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
  }, [user]);

  useEffect(() => {
    const envLink = (import.meta as any)?.env?.VITE_PAYMONGO_PAYMENT_LINK;
    const storedLink = typeof window !== 'undefined' ? localStorage.getItem('ss_paymongo_link') : '';
    setPayLink(envLink || storedLink || '');
  }, []);

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

  const handleEditAddress = (address: any) => {
    setEditingAddressId(address.id);
    setEditingAddressData({ ...address });
    setShowAddressSelector(false);
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

  const toggleSelectAll = () => {
    if (selectedItems.size === items.length) {
      setSelectedItems(new Set());
    } else {
      setSelectedItems(new Set(items.map(i => i.id)));
    }
  };

  const handleCheckout = async () => {
    // Get selected cart items
    const selectedCartItems = items.filter(item => selectedItems.has(item.id));
    
    if (selectedCartItems.length === 0) {
      Swal.fire('No Items Selected', 'Please select at least one item to checkout', 'warning');
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
      payment_method: 'paymongo',
    };
    
    // Store checkout data in sessionStorage for the payment page
    console.log('Storing checkout data:', checkoutData);
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

      <main className="flex-1">
        <div className="max-w-7xl mx-auto py-12 px-6 text-black">
        <div className="grid grid-cols-1 md:grid-cols-3 gap-8 items-start">
          {/* Left: cart items (span 2 on md) */}
          <div className={items.length === 0 ? 'md:col-span-3' : 'md:col-span-2'}>
            <div className={items.length === 0 ? 'rounded bg-white' : 'border border-gray-100 rounded'}>
              {items.length > 0 && (
                <div className="hidden md:grid grid-cols-12 gap-4 p-4 border-b text-sm font-medium text-black">
                  <div className="col-span-1 flex items-center justify-center">
                    <input
                      type="checkbox"
                      checked={selectedItems.size === items.length && items.length > 0}
                      onChange={toggleSelectAll}
                      className="w-4 h-4 rounded border-gray-300 text-black focus:ring-black cursor-pointer"
                    />
                  </div>
                  <div className="col-span-5">Product</div>
                  <div className="col-span-3 text-center">Quantity</div>
                  <div className="col-span-3 text-right">Total</div>
                </div>
              )}

              <div>
                {items.length === 0 ? (
                  <div className="py-20 flex flex-col items-center justify-center text-center text-black">
                    <div className="relative">
                      <svg xmlns="http://www.w3.org/2000/svg" className="w-20 h-20 text-black" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M3 3h2l1 5h13l1-4H7" />
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M16 16a2 2 0 11-4 0 2 2 0 014 0zm-8 0a2 2 0 11-4 0 2 2 0 014 0z" />
                      </svg>
                      <span className="absolute -top-2 -right-2 bg-black text-white rounded-full w-6 h-6 flex items-center justify-center text-xs">{items.length}</span>
                    </div>

                    <h2 className="mt-6 text-xl font-semibold text-black">Your cart is empty</h2>

                    <Link href="/products" className="mt-6 bg-black text-white px-6 py-3 rounded-md inline-block">Continue shopping</Link>
                  </div>
                ) : (
                  items.map(item => (
                    <div key={item.id} className="grid grid-cols-1 md:grid-cols-12 gap-4 items-center p-6 border-b last:border-b-0">
                      <div className="md:col-span-1 flex items-center justify-center">
                        <input
                          type="checkbox"
                          checked={selectedItems.has(item.id)}
                          onChange={() => toggleSelectItem(item.id)}
                          className="w-4 h-4 rounded border-gray-300 text-black focus:ring-black cursor-pointer"
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
                  ))
                )}
              </div>
            </div>
          </div>

          {/* Right: summary (only when items exist) */}
          {items.length > 0 && (
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
                              <button
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
                              <div className="flex items-center justify-center w-5 h-5 rounded-full border-2 bg-white dark:bg-gray-800 flex-shrink-0 mt-1" style={{
                                borderColor: selectedAddressId === address.id ? '#2563eb' : '#d1d5db'
                              }}>
                                {selectedAddressId === address.id && (
                                  <div className="w-3 h-3 rounded-full" style={{ backgroundColor: '#2563eb' }} />
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
                  <div className="bg-white dark:bg-gray-900 rounded-lg shadow-xl max-w-5xl w-full border border-gray-200 dark:border-gray-800 overflow-visible flex flex-col">
                    <div className="border-b border-gray-200 dark:border-gray-800 px-4 py-3 flex items-center justify-between flex-shrink-0 sticky top-0 bg-white dark:bg-gray-900 z-10">
                      <h3 className="text-xl font-bold text-gray-900 dark:text-white">Add New Address</h3>
                      <button
                        onClick={() => setShowAddAddressModal(false)}
                        className="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors"
                      >
                        <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                        </svg>
                      </button>
                    </div>

                    <div className="overflow-visible flex-1 p-4">
                      
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

                      <div className="flex items-center justify-between pt-2 mt-2">
                        <label className="text-sm font-medium text-gray-700 dark:text-gray-300">Set as default address</label>
                        <button
                          onClick={() => setNewAddressData(prev => ({ ...prev, is_default: !prev.is_default }))}
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
                            onChange={(e) => setEditingAddressData(prev => ({ ...prev, region: e.target.value }))}
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
                            onChange={(e) => setEditingAddressData(prev => ({ ...prev, province: e.target.value }))}
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
                            onChange={(e) => setEditingAddressData(prev => ({ ...prev, city: e.target.value }))}
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
                            onChange={(e) => setEditingAddressData(prev => ({ ...prev, barangay: e.target.value }))}
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
                              setEditingAddressData(prev => ({ ...prev, postal_code: value }));
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
                          onChange={(e) => setEditingAddressData(prev => ({ ...prev, address_line: e.target.value }))}
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
                      
                      {/* Set as Default */}
                      <div className="flex items-center justify-between pt-2 mt-2 border-t border-gray-200 dark:border-gray-700">
                        <label className="text-sm font-medium text-gray-700 dark:text-gray-300">Set as default address</label>
                        <button
                          type="button"
                          onClick={() => setEditingAddressData(prev => ({ ...prev, is_default: !prev.is_default }))}
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
            
            <div className="border border-gray-100 rounded p-6 bg-white">
              <div className="flex items-baseline justify-between mb-4">
                <div className="text-lg font-semibold text-black">Total</div>
                <div className="text-2xl font-extrabold text-black">₱{subtotal.toLocaleString()} PHP</div>
              </div>

              <p className="text-xs text-black/60 mb-4 leading-relaxed">Shipping fees are not included and are handled by the buyer. The shipping cost depends on the delivery location and will be paid directly by the buyer.</p>

              <textarea placeholder="Order note for carrier pickup branch" className="w-full border rounded p-3 mb-4 text-sm h-24 resize-none text-black" />

              {payError && (
                <div className="text-xs text-red-600 mb-3">{payError}</div>
              )}

              <button
                onClick={handleCheckout}
                disabled={selectedItems.size === 0 || isPaying}
                className={`w-full flex items-center justify-center gap-3 py-3 rounded-md ${selectedItems.size === 0 || isPaying ? 'bg-gray-300 text-gray-600' : 'bg-gray-900 text-white'}`}
              >
                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 11V17M9 14h6"/></svg>
                {isPaying ? 'Placing Order…' : `Place Order (${selectedItems.size} ${selectedItems.size === 1 ? 'item' : 'items'})`}
              </button>
            </div>
          </aside>
          )}
        </div>
        </div>
      </main>

      <CheckoutFooter />
    </div>
  );
};

export default Checkout;

// Footer: replicated SoleSpace footer used across the site
// If a shared footer component exists later, replace this markup with that component.
export const CheckoutFooter: React.FC = () => {
  return (
    <footer className="mt-32 bg-gray-100 text-slate-900">
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