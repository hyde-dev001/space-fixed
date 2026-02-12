import React from 'react';
import { router, usePage } from '@inertiajs/react';
import Swal from 'sweetalert2';
import { dispatchCartAddedEvent, dispatchCartGuestAddAttemptEvent } from '../types/cart-events';

type AddToCartButtonProps = {
  productId?: number | string;
  product?: any;
  label?: string;
  onAdded?: () => void;
  className?: string;
  disabled?: boolean;
  buyNow?: boolean;
};

export const AddToCartButton: React.FC<AddToCartButtonProps> = ({ productId, product, label = 'Add to cart', onAdded, className, disabled, buyNow = false }) => {
  const { auth } = usePage().props as any;
  const [isLoading, setIsLoading] = React.useState(false);
  const isProcessingRef = React.useRef(false); // Use ref for immediate synchronous check
  
  // Check if user is authenticated and is a regular customer (not ERP staff)
  const user = auth?.user;
  const userRole = user?.role?.toUpperCase();
  const isERPStaff = userRole && ['HR', 'FINANCE_STAFF', 'FINANCE_MANAGER', 'FINANCE', 'CRM', 'MANAGER', 'STAFF'].includes(userRole);
  const isAuthenticated = Boolean(user && !isERPStaff);

  const handleClick = async (e: React.MouseEvent) => {
    e.preventDefault();
    e.stopPropagation();

    // CRITICAL: Check ref FIRST before any state updates - this is synchronous and immediate
    if (disabled || isLoading || isProcessingRef.current) {
      console.log('[CartActions] Click blocked - already processing');
      return;
    }
    
    // Set BOTH ref and state immediately
    isProcessingRef.current = true;
    setIsLoading(true);

    if (!isAuthenticated) {
      try {
        dispatchCartGuestAddAttemptEvent({ productId });
      } catch (err) {}
      setIsLoading(false);
      return;
    }

    const pid = Number(productId ?? (product && product.id) ?? 0);
    const addQty = (typeof product?.qty === 'number') ? product.qty : 1;
    const size = product?.size ?? null;
    const color = product?.color ?? null;
    const selectedImage = product?.selectedImage ?? product?.primary ?? (product?.images && product.images[0]) ?? null;

    try {
      // Add to database via API
      // Include the selected image and color in options to distinguish between color variants
      const response = await fetch('/api/cart/add', {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        },
        body: JSON.stringify({
          product_id: pid,
          quantity: addQty,
          size: size,
          options: { 
            image: selectedImage,
            color: color
          },
        }),
      });

      const data = await response.json();

      if (response.ok && data.success) {
        // Notify with the new total count from server
        const total = data.total_count;
        dispatchCartAddedEvent({ added: addQty, total });

        // If Buy Now, redirect to payment immediately
        if (buyNow) {
          router.visit('/payment');
          return;
        }

        // Show success message
        Swal.fire({
          icon: 'success',
          title: 'Added to Cart!',
          text: `${product?.name || 'Product'} has been added to your cart`,
          showConfirmButton: false,
          timer: 1500,
          timerProgressBar: true,
          toast: true,
          position: 'top-end',
        });

        // Scroll to the cart icon
        const el = document.getElementById('cart-icon');
        if (el) {
          el.classList.add('cart-pulse');
          el.scrollIntoView({ behavior: 'smooth', block: 'center' });
          window.setTimeout(() => el.classList.remove('cart-pulse'), 1200);
        }

        if (onAdded) onAdded();
      } else {
        // Handle non-successful response
        const errorMsg = data.error || data.message || 'Failed to add item to cart';
        throw new Error(errorMsg);
      }
    } catch (error: any) {
      const errorMsg = error.message || 'Failed to add item to cart';
      
      Swal.fire({
        icon: 'error',
        title: 'Error',
        text: errorMsg,
        showConfirmButton: true,
      });
    } finally {
      isProcessingRef.current = false; // Reset ref
      setIsLoading(false);
    }
  };

  return (
    <button 
      type="button" 
      onClick={handleClick} 
      onDoubleClick={(e) => { e.preventDefault(); e.stopPropagation(); }}
      className={className || 'btn btn-primary'}
      disabled={disabled || isLoading}
      style={{ 
        pointerEvents: isLoading ? 'none' : 'auto',
        opacity: isLoading ? 0.6 : 1,
        cursor: isLoading ? 'not-allowed' : 'pointer'
      }}
    >
      {isLoading ? '⏳ Adding...' : label}
    </button>
  );
};

type CartIconProps = {
  checkoutUrl?: string;
  className?: string;
};

export const CartIcon: React.FC<CartIconProps> = ({ checkoutUrl = '/checkout', className }) => {
  const navigateToCheckout = (e: React.MouseEvent) => {
    e.preventDefault();
    router.visit(checkoutUrl);
  };

  return (
    <div id="cart-icon" onClick={navigateToCheckout} role="button" className={className || 'cart-icon'} style={{cursor: 'pointer', display: 'inline-flex', alignItems: 'center'}}>
      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden>
        <path d="M6 6H21L20 11H9L6 6Z" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" />
        <path d="M7 18C7 18.5523 6.55228 19 6 19C5.44772 19 5 18.5523 5 18C5 17.4477 5.44772 17 6 17C6.55228 17 7 17.4477 7 18Z" fill="currentColor" />
        <path d="M20 18C20 18.5523 19.5523 19 19 19C18.4477 19 18 18.5523 18 18C18 17.4477 18.4477 17 19 17C19.5523 17 20 17.4477 20 18Z" fill="currentColor" />
      </svg>
    </div>
  );
};

export default AddToCartButton;
