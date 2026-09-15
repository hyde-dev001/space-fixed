import React, { createContext, useContext, useState, useEffect, ReactNode } from 'react';
import { CartAddedEvent, addCartAddedListener, removeCartAddedListener } from '../types/cart-events';

interface CartContextType {
  cartCount: number;
  isLoading: boolean;
  updateCartCount: (count: number) => void;
  refreshCart: () => Promise<void>;
  validateCart: () => Promise<void>;
  incrementCount: (amount?: number) => void;
  decrementCount: (amount?: number) => void;
  triggerPulse: () => void;
}

const CartContext = createContext<CartContextType | undefined>(undefined);

interface CartProviderProps {
  children: ReactNode;
  syncEnabled?: boolean;
}

export const CartProvider: React.FC<CartProviderProps> = ({ children, syncEnabled = true }) => {
  const [cartCount, setCartCount] = useState<number>(0);
  const [isLoading, setIsLoading] = useState<boolean>(false);

  // Load cart count from API
  const refreshCart = async () => {
    if (!syncEnabled) return;

    setIsLoading(true);
    try {
      const response = await fetch('/api/cart', {
        method: 'GET',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
        },
        credentials: 'include',
      });

      if (response.ok) {
        const data = await response.json();
        if (data.count !== undefined) {
          setCartCount(data.count);
        }
      } else {
        throw new Error('Failed to fetch cart');
      }
    } catch (error) {
      // Fallback to localStorage if API fails
      try {
        const raw = localStorage.getItem('ss_cart');
        const cart = raw ? JSON.parse(raw) : [];
        const total = cart.reduce((s: number, it: any) => s + (it.qty || 0), 0);
        setCartCount(total);
      } catch (e) {
        console.error('Failed to load cart:', e);
      }
    } finally {
      setIsLoading(false);
    }
  };

  // Validate cart - check for invalid items, stock issues, and sync with server
  const validateCart = async () => {
    if (!syncEnabled) return;

    try {
      // Get localStorage cart for comparison
      const raw = localStorage.getItem('ss_cart');
      const parsedLocalCart = raw ? JSON.parse(raw) : [];
      const localCart = Array.isArray(parsedLocalCart)
        ? parsedLocalCart.filter((item: any) => item && typeof item === 'object' && item.id != null)
        : [];
      
      // Fetch server cart data
      const response = await fetch('/api/cart', {
        method: 'GET',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
        },
        credentials: 'include',
      });

      if (response.ok) {
        const data = await response.json();
        const serverItems = data.items || [];
        
        // Check for discrepancies
        let hasChanges = false;
        const validatedCart: any[] = [];
        
        for (const localItem of localCart) {
          const localQty = Number(localItem.qty) || 0;

          if (localQty <= 0) {
            hasChanges = true;
            continue;
          }

          const serverItem = serverItems.find((si: any) => 
            String(si.product_id) === String(localItem.id)
          );
          
          if (!serverItem) {
            // Item no longer exists on server - remove from localStorage
            console.warn(`Cart validation: Item ${localItem.id} removed (not found on server)`);
            hasChanges = true;
            continue;
          }
          
          // Check if quantity needs adjustment due to stock
          let validatedQty = localQty;
          if (serverItem.stock_quantity < localQty) {
            console.warn(`Cart validation: Item ${localItem.id} quantity adjusted from ${localQty} to ${serverItem.stock_quantity} (stock limit)`);
            validatedQty = serverItem.stock_quantity;
            hasChanges = true;
          }

          if (validatedQty <= 0) {
            hasChanges = true;
            continue;
          }
          
          validatedCart.push({
            ...localItem,
            qty: validatedQty,
          });
        }
        
        // Update localStorage if changes were made
        if (hasChanges) {
          localStorage.setItem('ss_cart', JSON.stringify(validatedCart));
          const newTotal = validatedCart.reduce((s: number, it: any) => s + (it.qty || 0), 0);
          setCartCount(newTotal);
          console.log('Cart validation: Changes applied, cart updated');
        }
        
        // Sync count with server
        if (data.count !== undefined) {
          setCartCount(data.count);
        }
      }
    } catch (error) {
      console.error('Cart validation failed:', error);
    }
  };

  // Update cart count directly
  const updateCartCount = (count: number) => {
    setCartCount(Math.max(0, count));
  };

  // Increment cart count
  const incrementCount = (amount: number = 1) => {
    setCartCount(prev => prev + amount);
  };

  // Decrement cart count
  const decrementCount = (amount: number = 1) => {
    setCartCount(prev => Math.max(0, prev - amount));
  };

  // Trigger visual pulse animation
  const triggerPulse = () => {
    const cartIcon = document.getElementById('cart-icon');
    const cartBadge = document.getElementById('cart-badge');
    
    if (cartIcon) {
      cartIcon.classList.add('cart-pulse');
      setTimeout(() => cartIcon.classList.remove('cart-pulse'), 600);
    }
    
    if (cartBadge) {
      cartBadge.classList.add('cart-badge-pulse');
      setTimeout(() => cartBadge.classList.remove('cart-badge-pulse'), 400);
    }
  };

  // Initial load
  useEffect(() => {
    if (!syncEnabled) return;

    refreshCart();
    // Validate cart on initial load to catch any stale data
    validateCart();
  }, [syncEnabled]);

  // Listen to cart events
  useEffect(() => {
    if (!syncEnabled) return;

    const handler = (e: CartAddedEvent) => {
      // Trigger pulse animation
      triggerPulse();
      
      // Optimistic update: use the count from the event detail
      if (e.detail && e.detail.total !== undefined) {
        updateCartCount(e.detail.total);
      } else {
        // Fallback to API reload if event doesn't have total
        refreshCart();
      }
    };

    addCartAddedListener(handler);
    return () => removeCartAddedListener(handler);
  }, [syncEnabled]);

  // Sync cart when page becomes visible (user returns to tab)
  useEffect(() => {
    if (!syncEnabled) return;

    const handleVisibilityChange = () => {
      if (document.visibilityState === 'visible') {
        // Refresh cart when user returns to the tab
        // This handles multi-tab scenarios and cart updates from other devices
        refreshCart();
        // Also validate to ensure data integrity
        validateCart();
      }
    };

    document.addEventListener('visibilitychange', handleVisibilityChange);
    return () => document.removeEventListener('visibilitychange', handleVisibilityChange);
  }, [syncEnabled]);

  const value: CartContextType = {
    cartCount,
    isLoading,
    updateCartCount,
    refreshCart,
    validateCart,
    incrementCount,
    decrementCount,
    triggerPulse,
  };

  return <CartContext.Provider value={value}>{children}</CartContext.Provider>;
};

// Custom hook to use cart context
export const useCart = (): CartContextType => {
  const context = useContext(CartContext);
  if (context === undefined) {
    throw new Error('useCart must be used within a CartProvider');
  }
  return context;
};
