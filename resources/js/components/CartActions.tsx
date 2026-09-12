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
  stockQuantity?: number;
};

export const AddToCartButton: React.FC<AddToCartButtonProps> = ({ productId, product, label = 'Add to cart', onAdded, className, disabled, buyNow = false, stockQuantity }) => {
  const { auth } = usePage().props as any;
  const [isLoading, setIsLoading] = React.useState(false);
  const isProcessingRef = React.useRef(false); // Use ref for immediate synchronous check
  const modalTheme = {
    customClass: {
      popup:
        '!w-[34rem] !max-w-[92vw] !rounded-3xl !px-6 !py-6 !shadow-[0_30px_80px_-40px_rgba(15,23,42,0.55)] !border !border-slate-200',
      title: '!text-3xl !font-black !text-slate-900 !leading-[1.2] !tracking-[-0.015em] !mb-2',
      htmlContainer: '!mx-0 !mb-0 !mt-2 !p-0 !text-base !text-slate-700',
      actions: '!mt-6 !w-full !justify-center',
      confirmButton:
        '!m-0 !h-11 !min-w-[180px] !rounded-xl !px-6 !text-sm !font-semibold !tracking-[0.01em] !bg-slate-950 hover:!bg-black focus:!ring-2 focus:!ring-slate-400',
    },
    showClass: {
      popup: 'swal2-show !animate-[slideInUp_0.22s]',
      backdrop: 'swal2-backdrop-show !animate-[fadeIn_0.22s]',
    },
    hideClass: {
      popup: 'swal2-hide !animate-[slideOutDown_0.16s]',
      backdrop: 'swal2-backdrop-hide !animate-[fadeOut_0.16s]',
    },
  } as const;
  
  // Check if user is authenticated and is a regular customer (not ERP staff)
  // A user is a customer if they DON'T have a shop_owner_id (staff have shop_owner_id set)
  const user = auth?.user;
  const isAuthenticated = Boolean(user && !user?.shop_owner_id);

  const parsePrice = (value: unknown): number => {
    if (typeof value === 'number' && Number.isFinite(value)) return value;
    if (typeof value === 'string') {
      const cleaned = value.replace(/[^0-9.-]+/g, '');
      const parsed = Number.parseFloat(cleaned);
      return Number.isFinite(parsed) ? parsed : 0;
    }
    return 0;
  };

  const handleClick = async (e: React.MouseEvent) => {
    e.preventDefault();
    e.stopPropagation();

    // CRITICAL: Check ref FIRST before any state updates - this is synchronous and immediate
    if (disabled || isLoading || isProcessingRef.current) {
      console.log('[CartActions] Click blocked - already processing');
      return;
    }

    if (typeof stockQuantity === 'number' && stockQuantity <= 0) {
      await Swal.fire({
        icon: 'warning',
        title: 'Out of stock',
        text: 'This product is currently out of stock. Please check back later or choose another item.',
        confirmButtonText: 'OK',
        showConfirmButton: true,
        ...modalTheme,
      });
      return;
    }
    
    // Set BOTH ref and state immediately
    isProcessingRef.current = true;
    setIsLoading(true);

    if (!isAuthenticated) {
      try {
        dispatchCartGuestAddAttemptEvent({ productId });
      } catch (err) {}
      isProcessingRef.current = false;
      setIsLoading(false);
      return;
    }

    const pid = Number(productId ?? (product && product.id) ?? 0);
    const addQty = (typeof product?.qty === 'number') ? product.qty : 1;
    const size = product?.size ?? null;
    const color = product?.color ?? null;
    const selectedImage = product?.selectedImage ?? product?.primary ?? (product?.images && product.images[0]) ?? null;

    if (buyNow) {
      const itemPrice = parsePrice(product?.price);
      const itemQty = Number(addQty ?? 1) || 1;
      const checkoutData = {
        items: [
          {
            id: `buy-now-${pid}-${Date.now()}`,
            pid,
            name: product?.name || '',
            price: itemPrice,
            size: size ?? undefined,
            color: color ?? undefined,
            qty: itemQty,
            image: selectedImage ?? undefined,
          },
        ],
        total_amount: itemPrice * itemQty,
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
        selected_item_ids: [],
      };

      sessionStorage.setItem('checkoutData', JSON.stringify(checkoutData));
      router.visit('/payment');
      return;
    }

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
        dispatchCartAddedEvent({
          added: addQty,
          total,
          openDrawer: true,
          item: {
            name: product?.name || null,
            price: parsePrice(product?.price),
            image: selectedImage,
            size,
            color,
            quantity: addQty,
          },
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
        confirmButtonText: 'OK',
        showConfirmButton: true,
        ...modalTheme,
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
      {isLoading ? (
        <span className="inline-flex items-center gap-2" aria-live="polite">
          <svg className="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <circle cx="12" cy="12" r="9" stroke="currentColor" strokeOpacity="0.25" strokeWidth="2.5" />
            <path d="M21 12a9 9 0 00-9-9" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" />
          </svg>
          <span>Adding...</span>
        </span>
      ) : label}
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
