import React, { useState, useEffect } from 'react';
import { Head, Link, usePage, router } from '@inertiajs/react';
import { route } from 'ziggy-js';
import Swal from '@/Pages/UserSide/Shared/UserModal';
import Navigation from '../Shared/Navigation';
import AddToCartButton from '../../../Components/CartActions';
import Virtual3DShowroom from '../../../components/Virtual3DShowroom';
import { CartGuestAddAttemptEvent, addCartGuestAddAttemptListener, removeCartGuestAddAttemptListener } from '../../../types/cart-events';
import { useCart } from '../../../contexts/CartContext';

type ColorVariantImage = {
  id: number;
  image_path: string;
  alt_text: string | null;
  is_thumbnail: boolean;
  sort_order: number;
};

type ColorVariant = {
  id: number;
  color_name: string;
  color_code: string;
  images: ColorVariantImage[];
  sizes?: Array<{
    id?: number;
    size: string;
    size_system?: 'US' | 'UK' | 'EU' | 'AU' | 'CN';
    quantity: number;
  }>;
};

type StaticVoucherCampaign = {
  id: string;
  name: string;
  code: string;
  value: string;
  minSpend: string;
  schedule: string;
};

const staticVoucherCampaigns: StaticVoucherCampaign[] = [
  {
    id: 'voucher-weekend10',
    name: 'Weekend Drop',
    code: 'WEEKEND10',
    value: '10% off',
    minSpend: 'PHP 2,000 min spend',
    schedule: 'Valid Mar 18 - Mar 31, 2026',
  },
  {
    id: 'voucher-fastship',
    name: 'Fast Checkout Perk',
    code: 'FASTSHIP',
    value: '8% off',
    minSpend: 'PHP 1,500 min spend',
    schedule: 'Valid Mar 18 - Apr 10, 2026',
  },
  {
    id: 'voucher-loyal15',
    name: 'Loyal Buyer Bonus',
    code: 'LOYAL15',
    value: '15% off',
    minSpend: 'PHP 3,000 min spend',
    schedule: 'Valid Mar 18 - Apr 15, 2026',
  },
];

const ProductShow: React.FC = () => {
  const { product, auth, cartIconCount: cartCountProp } = usePage().props as any;
  const { cartCount, isLoading: cartLoading } = useCart();
  const cartBadgeCount = Number(cartCountProp ?? (cartLoading ? 0 : cartCount) ?? 0);
  const [mobileSearchQuery, setMobileSearchQuery] = useState('');
  const [mobileUserDropdownOpen, setMobileUserDropdownOpen] = useState(false);
  
  // Check if user is authenticated and is a regular customer (not ERP staff)
  // A user is a customer if they DON'T have a shop_owner_id (staff have shop_owner_id set)
  const user = auth?.user;
  const isAuthenticated = Boolean(user && !user?.shop_owner_id);

  const handleLogout = () => {
    router.post('/user/logout', {}, { preserveState: false, preserveScroll: false });
  };
  
  // Check if product has color variants (new Adidas-style system)
  const hasColorVariants = product.colorVariants && Array.isArray(product.colorVariants) && product.colorVariants.length > 0;

  type SizeSystem = 'US' | 'UK' | 'EU' | 'AU' | 'CN';
  type SizeOption = {
    key: string;
    value: string;
    label: string;
    token: string;
  };
  const legacySizeMap: Record<string, string> = { XS: '6', S: '7', M: '8', L: '9', XL: '10', XXL: '11' };
  const sizeSystems: SizeSystem[] = ['US', 'UK', 'EU', 'AU', 'CN'];

  const normalizeSizeSystem = (rawSystem?: string | null): SizeSystem => {
    const normalized = String(rawSystem || '').trim().toUpperCase();
    return sizeSystems.includes(normalized as SizeSystem) ? (normalized as SizeSystem) : 'US';
  };

  const parseSizeDetails = (rawSize: unknown): { system: SizeSystem; value: string } => {
    const normalizedRaw = String(rawSize ?? '').trim();
    const prefixed = normalizedRaw.match(/^(US|UK|EU|AU|CN)\s*[:\-]?\s*(.+)$/i);

    if (prefixed) {
      const rawValue = String(prefixed[2] || '').trim();
      const mapped = legacySizeMap[rawValue.toUpperCase()] ?? rawValue;

      return {
        system: normalizeSizeSystem(prefixed[1]),
        value: String(mapped).trim(),
      };
    }

    const mapped = legacySizeMap[normalizedRaw.toUpperCase()] ?? normalizedRaw;

    return {
      system: 'US',
      value: String(mapped).trim(),
    };
  };

  const toSelectableSizeValue = (rawSize: unknown): string => {
    const parsed = parseSizeDetails(rawSize);
    if (!parsed.value) return '';
    return parsed.system === 'US' ? parsed.value : `${parsed.system} ${parsed.value}`;
  };

  const toSizeLabel = (rawSize: unknown): string => {
    const parsed = parseSizeDetails(rawSize);
    if (!parsed.value) return '-';
    return `${parsed.system} ${parsed.value}`;
  };

  const toSizeToken = (rawSize: unknown): string => {
    const parsed = parseSizeDetails(rawSize);
    if (!parsed.value) return '';
    return `${parsed.system}::${parsed.value.toLowerCase()}`;
  };

  const isSameSize = (left: unknown, right: unknown): boolean => {
    const leftToken = toSizeToken(left);
    const rightToken = toSizeToken(right);

    if (!leftToken || !rightToken) return false;
    return leftToken === rightToken;
  };
  
  // Initialize with first color variant or legacy system
  const initialColor = hasColorVariants 
    ? product.colorVariants[0].color_name 
    : (product.colors_available?.[0] || product.colors?.[0] || null);
  
  const [selectedColorVariant, setSelectedColorVariant] = useState<ColorVariant | null>(
    hasColorVariants ? product.colorVariants[0] : null
  );
  
  // Get images from color variant or fallback to legacy
  const getProductImages = (): string[] => {
    if (hasColorVariants && selectedColorVariant) {
      return selectedColorVariant.images
        .sort((a, b) => a.sort_order - b.sort_order)
        .map(img => img.image_path);
    }
    // Fallback to legacy images
    return (product.images && Array.isArray(product.images) && product.images.length > 0) 
      ? product.images 
      : (product.primary ? [product.primary] : []);
  };
  
  const images: string[] = getProductImages();
  
  const [selectedImage, setSelectedImage] = useState(images[0] || product.primary);
  const [slideTransition, setSlideTransition] = useState<{ from: string; to: string; direction: 'next' | 'prev' } | null>(null);
  const [slidePhase, setSlidePhase] = useState<'prep' | 'run'>('prep');
  const [isSlideRunning, setIsSlideRunning] = useState(false);
  const [selectedColor, setSelectedColor] = useState<string | null>(initialColor);

  const buildSizeOptions = (rawSizes: unknown[]): SizeOption[] => {
    const seenTokens = new Set<string>();

    return rawSizes
      .map((rawSize: unknown) => {
        const value = toSelectableSizeValue(rawSize);
        const label = toSizeLabel(rawSize);
        const token = toSizeToken(rawSize);

        return {
          key: token || value || String(rawSize),
          value,
          label,
          token,
        };
      })
      .filter((option: SizeOption) => !!option.value)
      .filter((option: SizeOption) => {
        if (!option.token) return true;
        if (seenTokens.has(option.token)) return false;
        seenTokens.add(option.token);
        return true;
      });
  };

  const getRawSizesForColor = (color: string | null): unknown[] => {
    if (!color) return [];

    if (hasColorVariants) {
      const colorVariant = (product.colorVariants || []).find(
        (cv: ColorVariant) => String(cv.color_name ?? '').trim().toLowerCase() === String(color).trim().toLowerCase(),
      );

      const scopedSizes = (colorVariant?.sizes || [])
        .filter((size) => Number(size.quantity ?? 0) > 0)
        .map((size) => `${(size.size_system || 'US').toUpperCase()} ${String(size.size).trim()}`)
        .filter((size) => size.trim() !== '');

      if (scopedSizes.length > 0) {
        return scopedSizes;
      }
    }

    if (!Array.isArray(product.variants)) return [];

    return product.variants
      .filter((variant: any) =>
        String(variant.color ?? '').trim().toLowerCase() === String(color).trim().toLowerCase() &&
        Number(variant.quantity ?? 0) > 0
      )
      .map((variant: any) => variant.size)
      .filter((size: unknown) => size !== null && size !== undefined && String(size).trim() !== '');
  };

  const getScopedQuantity = (size: string | null, color: string | null): number => {
    if (!size || !color) return 0;

    if (hasColorVariants) {
      const colorVariant = (product.colorVariants || []).find(
        (cv: ColorVariant) => String(cv.color_name ?? '').trim().toLowerCase() === String(color).trim().toLowerCase(),
      );

      const scopedSizes = colorVariant?.sizes || [];
      if (scopedSizes.length > 0) {
        const matchedSize = scopedSizes.find((entry) =>
          isSameSize(`${(entry.size_system || 'US').toUpperCase()} ${String(entry.size).trim()}`, size),
        );

        return matchedSize ? Number(matchedSize.quantity || 0) : 0;
      }
    }

    if (product.variants && Array.isArray(product.variants)) {
      const variant = product.variants.find((v: any) =>
        isSameSize(v.size, size) &&
        String(v.color).trim().toLowerCase() === String(color).trim().toLowerCase()
      );
      return variant ? Number(variant.quantity || 0) : 0;
    }

    return Number(product.stock_quantity || 0);
  };

  const [selectedSize, setSelectedSize] = useState<string | null>(() => {
    const colorScopedSizes = getRawSizesForColor(initialColor);
    const fallbackSizes = Array.isArray(product.sizes) ? product.sizes : [];
    const options = buildSizeOptions(colorScopedSizes.length > 0 ? colorScopedSizes : fallbackSizes);
    return options[0]?.value ?? null;
  });

  const sizeOptions = React.useMemo<SizeOption[]>(() => {
    const colorScopedSizes = getRawSizesForColor(selectedColor);
    const fallbackSizes = Array.isArray(product.sizes) ? product.sizes : [];

    return buildSizeOptions(colorScopedSizes.length > 0 ? colorScopedSizes : fallbackSizes);
  }, [selectedColor, product.variants, product.sizes]);
  
  // Update images when color variant changes
  useEffect(() => {
    if (hasColorVariants && selectedColor) {
      const colorVariant = product.colorVariants.find(
        (cv: ColorVariant) => cv.color_name.toLowerCase() === selectedColor.toLowerCase()
      );
      if (colorVariant) {
        setSelectedColorVariant(colorVariant);
        const newImages = colorVariant.images
          .sort((a, b) => a.sort_order - b.sort_order)
          .map(img => img.image_path);
        if (newImages.length > 0) {
          setSelectedImage(newImages[0]);
        }
      }
    }
  }, [selectedColor, hasColorVariants]);

  useEffect(() => {
    if (sizeOptions.length === 0) {
      setSelectedSize(null);
      return;
    }

    const stillValid = selectedSize
      ? sizeOptions.some((option) => isSameSize(option.value, selectedSize))
      : false;

    if (!stillValid) {
      setSelectedSize(sizeOptions[0].value);
    }
  }, [sizeOptions]);

  const [showSizeChart, setShowSizeChart] = useState(false);
  const [qty, setQty] = useState(1);
  const [showAddedModal, setShowAddedModal] = useState(false);
  const [showAddToCartModal, setShowAddToCartModal] = useState(false);
  const [modalSelectedSize, setModalSelectedSize] = useState<string | null>(selectedSize);
  const [modalSelectedColor, setModalSelectedColor] = useState<string | null>(selectedColor);
  const [modalQty, setModalQty] = useState(1);
  const [modalSelectedImage, setModalSelectedImage] = useState(images[0]);
  const [newComment, setNewComment] = useState('');
  const [userRating, setUserRating] = useState(0);
  const [hoverRating, setHoverRating] = useState(0);
  const [enlargedImage, setEnlargedImage] = useState<string | null>(null);
  const [imageUploadGroups, setImageUploadGroups] = useState<Array<{id: string; file: File | null; preview: string}>>([{id: '0', file: null, preview: ''}]);
  
  // Review system state
  const [reviews, setReviews] = useState<any[]>([]);
  const [reviewStats, setReviewStats] = useState({ average_rating: 0, total_reviews: 0, rating_distribution: {} });
  const [canReview, setCanReview] = useState(false);
  const [reviewEligibility, setReviewEligibility] = useState<any>(null);
  const [userExistingReview, setUserExistingReview] = useState<any>(null);
  const [isSubmittingReview, setIsSubmittingReview] = useState(false);
  const [showMyReview, setShowMyReview] = useState(false);
  const [claimedPromoIds, setClaimedPromoIds] = useState<string[]>([]);

  const [show3DShowroom, setShow3DShowroom] = useState(false);

  const modalSizeOptions = React.useMemo<SizeOption[]>(() => {
    const colorScopedSizes = getRawSizesForColor(modalSelectedColor);
    if (colorScopedSizes.length > 0) {
      return buildSizeOptions(colorScopedSizes);
    }

    const fallbackSizes = Array.isArray(product.sizes) ? product.sizes : [];
    return buildSizeOptions(fallbackSizes);
  }, [modalSelectedColor, product.variants, product.sizes]);

  useEffect(() => {
    if (modalSizeOptions.length === 0) {
      setModalSelectedSize(null);
      return;
    }

    const stillValid = modalSelectedSize
      ? modalSizeOptions.some((option) => isSameSize(option.value, modalSelectedSize))
      : false;

    if (!stillValid) {
      setModalSelectedSize(modalSizeOptions[0].value);
    }
  }, [modalSizeOptions]);

  const showroomFrameUrls: string[] = Array.isArray(product?.showroom_360_frames)
    ? product.showroom_360_frames
        .filter((frame: unknown): frame is string => typeof frame === 'string' && frame.trim().length > 0)
        .map((frame: string) => {
          if (/^https?:\/\//i.test(frame) || frame.startsWith('/')) {
            return frame;
          }
          return `/${frame}`;
        })
    : [];
  const hasShowroomFrames = showroomFrameUrls.length > 0;

  // Filter images based on selected size and color in modal
  const getFilteredImages = () => {
    // If we have variants with images, filter by selected size and color
    if (product.variants && Array.isArray(product.variants) && product.variants.length > 0) {
      const filtered = product.variants.filter((v: any) => {
        const sizeMatch = !modalSelectedSize || isSameSize(v.size, modalSelectedSize);
        const colorMatch = !modalSelectedColor || String(v.color).toLowerCase() === String(modalSelectedColor).toLowerCase();
        return sizeMatch && colorMatch && v.image;
      }).map((v: any) => v.image);
      
      if (filtered.length > 0) return filtered;
    }
    // Fallback to all images
    return images;
  };

  const filteredImages = getFilteredImages();

  // Get variant-specific quantity for modal
  const getVariantQuantity = () => {
    return getScopedQuantity(modalSelectedSize, modalSelectedColor);
  };

  // Get variant-specific quantity for main page
  const getMainPageVariantQuantity = () => {
    return getScopedQuantity(selectedSize, selectedColor);
  };

  const variantQuantity = getVariantQuantity();
  const mainPageVariantQuantity = getMainPageVariantQuantity();

  const buttonBaseClass =
    'group inline-flex items-center justify-center gap-3 rounded-full px-8 py-3.5 text-xs font-semibold uppercase tracking-[0.18em] transition-all duration-300 focus-visible:outline-none focus-visible:ring-2 sm:px-10 sm:py-4 sm:text-sm disabled:cursor-not-allowed disabled:opacity-50';
  const buttonLightClass =
    'border border-black/15 bg-white/90 text-slate-900 backdrop-blur-md shadow-[0_18px_35px_-18px_rgba(0,0,0,0.35)] hover:-translate-y-0.5 hover:border-[#16233b] hover:bg-[#16233b] hover:text-white hover:shadow-[0_24px_38px_-18px_rgba(0,0,0,0.45)] focus-visible:ring-[#16233b] focus-visible:ring-offset-2 focus-visible:ring-offset-white';
  const buttonDarkClass =
    'border border-black/65 bg-black/85 text-white backdrop-blur-sm shadow-[0_14px_28px_-18px_rgba(0,0,0,0.95)] hover:-translate-y-0.5 hover:border-[#16233b] hover:bg-[#16233b] hover:text-white hover:shadow-[0_22px_40px_-18px_rgba(0,0,0,0.95)] focus-visible:ring-[#16233b] focus-visible:ring-offset-2 focus-visible:ring-offset-white';
  const qtyStepperButtonClass =
    'h-11 w-11 inline-flex items-center justify-center rounded-full border border-black/15 bg-white/90 text-slate-900 backdrop-blur-md shadow-[0_12px_24px_-18px_rgba(0,0,0,0.45)] transition-all duration-300 hover:-translate-y-0.5 hover:border-[#16233b] hover:bg-[#16233b] hover:text-white hover:shadow-[0_18px_30px_-18px_rgba(0,0,0,0.55)] disabled:cursor-not-allowed disabled:opacity-40';
  const qtyInputClass =
    'h-11 w-16 rounded-full border border-black/15 bg-white/90 text-center text-sm font-semibold text-slate-900 shadow-[0_12px_24px_-18px_rgba(0,0,0,0.45)] transition-all duration-300 focus:border-[#16233b] focus:outline-none focus:ring-2 focus:ring-[#16233b]/20';

  // Auto-adjust quantity when variant changes on main page
  useEffect(() => {
    const maxQty = mainPageVariantQuantity > 0 ? mainPageVariantQuantity : 1;
    if (qty > maxQty) {
      setQty(maxQty);
    }
  }, [selectedSize, selectedColor]);

  // Auto-update selected image when size/color changes
  useEffect(() => {
    if (filteredImages.length > 0 && !filteredImages.includes(modalSelectedImage)) {
      setModalSelectedImage(filteredImages[0]);
    }
    // Reset quantity to 1 or max available when variant changes
    const maxQty = variantQuantity > 0 ? variantQuantity : 1;
    if (modalQty > maxQty) {
      setModalQty(Math.min(modalQty, maxQty));
    }
  }, [modalSelectedSize, modalSelectedColor]);

  useEffect(() => {
    const handler = (e: CartGuestAddAttemptEvent) => {
      setShowAddedModal(true);
    };

    addCartGuestAddAttemptListener(handler);
    return () => removeCartGuestAddAttemptListener(handler);
  }, []);

  const handleImageUpload = (id: string, e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (file) {
      const reader = new FileReader();
      reader.onloadend = () => {
        setImageUploadGroups(prev => 
          prev.map(group => 
            group.id === id ? {id, file, preview: reader.result as string} : group
          )
        );
      };
      reader.readAsDataURL(file);
    }
  };

  const handleClaimPromo = async (campaign: StaticVoucherCampaign) => {
    if (!isAuthenticated) {
      await Swal.fire({
        icon: 'info',
        title: 'Login required',
        text: 'Please log in to claim vouchers and coupons.',
        confirmButtonText: 'OK',
      });
      return;
    }

    if (claimedPromoIds.includes(campaign.id)) {
      await Swal.fire({
        icon: 'info',
        title: 'Already claimed',
        text: `${campaign.code} is already in your wallet.`,
        confirmButtonText: 'OK',
      });
      return;
    }

    setClaimedPromoIds((prev) => [...prev, campaign.id]);
    await Swal.fire({
      icon: 'success',
      title: 'Voucher claimed',
      text: `${campaign.code} has been added to your wallet.`,
      confirmButtonText: 'Nice',
    });
  };

  const addImageUploadBox = () => {
    if (imageUploadGroups.length < 5) {
      const newId = Math.random().toString(36).substr(2, 9);
      setImageUploadGroups(prev => [...prev, {id: newId, file: null, preview: ''}]);
    }
  };

  const removeImageBox = (id: string) => {
    setImageUploadGroups(prev => prev.filter(group => group.id !== id));
  };

  // Fetch reviews and check eligibility
  useEffect(() => {
    fetchReviews();
    if (isAuthenticated) {
      checkReviewEligibility();
    }
  }, [product.id, isAuthenticated]);

  const fetchReviews = async () => {
    try {
      const response = await fetch(`/api/products/${product.id}/reviews`);
      const data = await response.json();
      if (data.success) {
        setReviews(data.reviews || []);
        setReviewStats(data.statistics || { average_rating: 0, total_reviews: 0, rating_distribution: {} });
      }
    } catch (error) {
      console.error('Failed to fetch reviews:', error);
    }
  };

  const checkReviewEligibility = async () => {
    try {
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
      
      const response = await fetch(`/api/products/${product.id}/reviews/check-eligibility`, {
        credentials: 'include',
        headers: {
          'X-CSRF-TOKEN': csrfToken || '',
          'Accept': 'application/json',
        },
      });
      const data = await response.json();
      if (data.success) {
        setCanReview(data.can_review);
        setReviewEligibility(data);
        if (data.existing_review) {
          setUserExistingReview(data.existing_review);
        }
      }
    } catch (error) {
      console.error('Failed to check review eligibility:', error);
    }
  };

  const handleSubmitReview = async () => {
    if (!newComment.trim() || userRating === 0) {
      await Swal.fire({
        icon: 'warning',
        title: 'Incomplete review',
        text: 'Please provide both a rating and a comment',
        confirmButtonText: 'OK',
      });
      return;
    }

    if (!isAuthenticated) {
      await Swal.fire({
        icon: 'info',
        title: 'Login required',
        text: 'Please log in to write a review',
        confirmButtonText: 'OK',
      });
      return;
    }

    if (!canReview) {
      await Swal.fire({
        icon: 'warning',
        title: 'Not eligible',
        text: reviewEligibility?.message || 'You are not eligible to review this product',
        confirmButtonText: 'OK',
      });
      return;
    }

    setIsSubmittingReview(true);

    try {
      // Get CSRF token from meta tag
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

      const formData = new FormData();
      formData.append('rating', userRating.toString());
      formData.append('comment', newComment);

      // Add images if any - use 'images[]' for proper array handling in Laravel
      imageUploadGroups.forEach((group) => {
        if (group.file) {
          formData.append('images[]', group.file);
        }
      });

      const response = await fetch(`/api/products/${product.id}/reviews`, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'X-CSRF-TOKEN': csrfToken || '',
          'Accept': 'application/json',
        },
        body: formData,
      });

      const data = await response.json();

      if (data.success) {
        await Swal.fire({
          icon: 'success',
          title: 'Review submitted',
          text: 'Thank you for your review!',
          confirmButtonText: 'OK',
        });
        setNewComment('');
        setUserRating(0);
        setImageUploadGroups([{id: '0', file: null, preview: ''}]);
        // Refresh reviews and eligibility
        await fetchReviews();
        await checkReviewEligibility();
      } else {
        await Swal.fire({
          icon: 'error',
          title: 'Submission failed',
          text: data.message || 'Failed to submit review',
          confirmButtonText: 'OK',
        });
      }
    } catch (error) {
      console.error('Failed to submit review:', error);
      await Swal.fire({
        icon: 'error',
        title: 'Submission failed',
        text: 'Failed to submit review. Please try again.',
        confirmButtonText: 'OK',
      });
    } finally {
      setIsSubmittingReview(false);
    }
  };

  const renderStars = (rating: number, interactive: boolean = false, onRate?: (rating: number) => void) => {
    return (
      <div className="flex gap-1">
        {[1, 2, 3, 4, 5].map((star) => (
          <button
            key={star}
            type="button"
            onClick={() => interactive && onRate && onRate(star)}
            onMouseEnter={() => interactive && setHoverRating(star)}
            onMouseLeave={() => interactive && setHoverRating(0)}
            className={`${interactive ? 'cursor-pointer' : 'cursor-default'} transition-colors`}
            disabled={!interactive}
          >
            <svg
              className={`w-5 h-5 ${
                star <= (interactive ? (hoverRating || userRating) : rating)
                  ? 'text-yellow-400 fill-yellow-400'
                  : 'text-gray-300 fill-gray-300'
              }`}
              xmlns="http://www.w3.org/2000/svg"
              viewBox="0 0 24 24"
            >
              <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" />
            </svg>
          </button>
        ))}
      </div>
    );
  };

  const switchMainImage = (nextImage: string, preferredDirection?: 'next' | 'prev') => {
    if (!nextImage || nextImage === selectedImage || isSlideRunning) return;

    const currentIdx = images.indexOf(selectedImage);
    const nextIdx = images.indexOf(nextImage);
    const autoDirection: 'next' | 'prev' = nextIdx >= currentIdx ? 'next' : 'prev';
    const direction = preferredDirection || autoDirection;

    setSlideTransition({ from: selectedImage, to: nextImage, direction });
    setSlidePhase('prep');
    setIsSlideRunning(true);

    window.requestAnimationFrame(() => {
      window.requestAnimationFrame(() => {
        setSlidePhase('run');
      });
    });

    window.setTimeout(() => {
      setSelectedImage(nextImage);
      setSlideTransition(null);
      setIsSlideRunning(false);
    }, 320);
  };

  return (
    <>
      <Head title={product.name} />
      <div className="min-h-screen bg-white font-outfit antialiased">
        {/* Desktop Navigation */}
        <div className="hidden xl:block">
          <Navigation />
        </div>

        {/* Mobile / Tablet Top Bar */}
        <div className="fixed top-0 left-0 right-0 z-50 flex items-center gap-2 bg-white px-2 py-2 shadow-sm xl:hidden">
          {/* X / Back button */}
          <button
            type="button"
            onClick={() => router.visit(route('products'))}
            className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-gray-700 hover:bg-gray-100 transition-colors"
            aria-label="Back to products"
            title="Back to products"
          >
            <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>

          {/* Search field */}
          <form
            onSubmit={(e) => {
              e.preventDefault();
              if (mobileSearchQuery.trim()) router.visit(route('products', { search: mobileSearchQuery.trim() }));
            }}
            className="flex-1"
          >
            <div className="relative">
              <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-gray-400">
                <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-4.35-4.35m1.6-5.4a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
              </span>
              <input
                type="text"
                value={mobileSearchQuery}
                onChange={(e) => setMobileSearchQuery(e.target.value)}
                placeholder={product.name}
                className="w-full rounded-full border border-gray-300 bg-gray-50 py-2 pl-9 pr-3 text-sm text-gray-800 placeholder:text-gray-400 focus:border-[#16233b] focus:bg-white focus:outline-none focus:ring-1 focus:ring-[#16233b]/20"
                aria-label="Search products"
              />
            </div>
          </form>

          {/* Cart icon with badge */}
          <Link
            href="/checkout"
            className="relative flex h-9 w-9 shrink-0 items-center justify-center text-gray-700 hover:text-[#16233b] transition-colors"
            aria-label="Shopping cart"
          >
            <svg className="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 4h2l2.2 10.2a2 2 0 001.96 1.58h7.68a2 2 0 001.95-1.56L21 7H8" />
              <circle cx="10" cy="19" r="1.5" strokeWidth={2} />
              <circle cx="17" cy="19" r="1.5" strokeWidth={2} />
            </svg>
            {cartBadgeCount > 0 && (
              <span className="absolute -right-0.5 -top-0.5 flex h-4 min-w-[16px] items-center justify-center rounded-full bg-red-500 px-0.5 text-[10px] font-bold text-white">
                {cartBadgeCount > 99 ? '99+' : cartBadgeCount}
              </span>
            )}
          </Link>

          {/* User / Account icon with dropdown */}
          <div className="relative">
            <button
              type="button"
              onClick={() => setMobileUserDropdownOpen((o) => !o)}
              className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-gray-700 hover:bg-gray-100 transition-colors"
              aria-label="Account"
              title="Account"
            >
              <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
              </svg>
            </button>

            {mobileUserDropdownOpen && (
              <>
                {/* Backdrop */}
                <div
                  className="fixed inset-0 z-40"
                  onClick={() => setMobileUserDropdownOpen(false)}
                />
                {/* Dropdown */}
                <div className="absolute right-0 top-full z-50 mt-2 w-52 overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-[0_18px_35px_-20px_rgba(15,23,42,0.45)]">
                  {isAuthenticated ? (
                    <>
                      <Link
                        href="/my-orders"
                        onClick={() => setMobileUserDropdownOpen(false)}
                        className="flex items-center gap-3 px-4 py-3 text-sm font-medium text-black hover:bg-gray-50 border-b border-gray-100"
                      >
                        <svg className="h-4 w-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                        </svg>
                        Orders
                      </Link>
                      <Link
                        href="/my-repairs"
                        onClick={() => setMobileUserDropdownOpen(false)}
                        className="flex items-center gap-3 px-4 py-3 text-sm font-medium text-black hover:bg-gray-50 border-b border-gray-100"
                      >
                        <svg className="h-4 w-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                        Repair
                      </Link>
                      <Link
                        href="/customer-profile"
                        onClick={() => setMobileUserDropdownOpen(false)}
                        className="flex items-center gap-3 px-4 py-3 text-sm font-medium text-black hover:bg-gray-50 border-b border-gray-100"
                      >
                        <svg className="h-4 w-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                        </svg>
                        Edit Profile
                      </Link>
                      <button
                        type="button"
                        onClick={() => { setMobileUserDropdownOpen(false); handleLogout(); }}
                        className="flex w-full items-center gap-3 px-4 py-3 text-sm font-medium text-red-600 hover:bg-red-50"
                      >
                        <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                        </svg>
                        Log Out
                      </button>
                    </>
                  ) : (
                    <Link
                      href="/user/login"
                      onClick={() => setMobileUserDropdownOpen(false)}
                      className="flex items-center gap-3 px-4 py-3 text-sm font-medium text-black hover:bg-gray-50"
                    >
                      <svg className="h-4 w-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1" />
                      </svg>
                      Login
                    </Link>
                  )}
                </div>
              </>
            )}
          </div>
        </div>

        <div className="max-w-[1200px] mx-auto px-0 xl:px-12 pt-14 xl:pt-28 pb-28 xl:pb-20">
          <div className="flex flex-col xl:flex-row gap-0 xl:gap-8">
            <div className="flex-1">
              {/* Main Image Display - Adidas Style */}
              <div className="bg-white rounded-lg overflow-hidden">
                <div className="relative bg-gray-50 aspect-square flex items-center justify-center group">
                  {!slideTransition && (
                    <img
                      src={selectedImage}
                      alt={product.name}
                      onClick={() => setEnlargedImage(selectedImage)}
                      className="absolute inset-0 w-full h-full object-contain cursor-zoom-in transition-transform duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] group-hover:scale-105"
                    />
                  )}

                  {slideTransition && (
                    <>
                      <img
                        src={slideTransition.from}
                        alt={product.name}
                        onClick={() => setEnlargedImage(slideTransition.from)}
                        className={`absolute inset-0 w-full h-full object-contain cursor-zoom-in transition-transform duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] ${
                          slidePhase === 'run'
                            ? slideTransition.direction === 'next'
                              ? 'translate-x-[-100%]'
                              : 'translate-x-[100%]'
                            : 'translate-x-0'
                        }`}
                      />
                      <img
                        src={slideTransition.to}
                        alt={product.name}
                        onClick={() => setEnlargedImage(slideTransition.to)}
                        className={`absolute inset-0 w-full h-full object-contain cursor-zoom-in transition-transform duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] ${
                          slidePhase === 'run'
                            ? 'translate-x-0'
                            : slideTransition.direction === 'next'
                              ? 'translate-x-[100%]'
                              : 'translate-x-[-100%]'
                        }`}
                      />
                    </>
                  )}
                  
                  {/* 3D & 360 Virtual Showroom Buttons */}
                  <div className="absolute top-4 right-4 flex flex-col gap-2 transition-opacity xl:opacity-0 xl:group-hover:opacity-100">
                    {/* 360 Viewer Button */}
                    {hasShowroomFrames && (
                      <button
                        onClick={() => setShow3DShowroom(true)}
                        className="h-11 w-11 inline-flex items-center justify-center rounded-full border border-white/35 bg-black/80 text-white backdrop-blur-sm shadow-[0_14px_28px_-18px_rgba(0,0,0,0.95)] transition-all duration-300 hover:-translate-y-0.5 hover:border-[#16233b] hover:bg-[#16233b] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white"
                        title="View 360 Interactive"
                        aria-label="View 360 interactive"
                      >
                        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                          <circle cx="12" cy="12" r="3" strokeWidth={1.8} />
                        </svg>
                      </button>
                    )}

                  </div>
                  
                  {/* Navigation Arrow Buttons - Overlaid on Image */}
                  {images.length > 1 && (
                    <>
                      {/* Left Arrow */}
                      <button
                        onClick={() => {
                          const currentIdx = images.indexOf(selectedImage);
                          if (currentIdx > 0) {
                            switchMainImage(images[currentIdx - 1], 'prev');
                          }
                        }}
                        disabled={images.indexOf(selectedImage) === 0 || isSlideRunning}
                        className="absolute left-4 top-1/2 -translate-y-1/2 disabled:opacity-30 disabled:cursor-not-allowed transition-all xl:opacity-0 xl:group-hover:opacity-100"
                        aria-label="Previous image"
                      >
                        <svg className="w-6 h-6 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
                        </svg>
                      </button>

                      {/* Right Arrow */}
                      <button
                        onClick={() => {
                          const currentIdx = images.indexOf(selectedImage);
                          if (currentIdx < images.length - 1) {
                            switchMainImage(images[currentIdx + 1], 'next');
                          }
                        }}
                        disabled={images.indexOf(selectedImage) === images.length - 1 || isSlideRunning}
                        className="absolute right-4 top-1/2 -translate-y-1/2 disabled:opacity-30 disabled:cursor-not-allowed transition-all xl:opacity-0 xl:group-hover:opacity-100"
                        aria-label="Next image"
                      >
                        <svg className="w-6 h-6 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                        </svg>
                      </button>
                    </>
                  )}
                </div>

                {/* Thumbnail Navigation Strip + Counter */}
                {images.length > 1 && (
                  <div className="mt-4 px-2">
                    {/* Thumbnail Navigation Strip - Horizontal Scroll (Adidas Style) */}
                    <div className="flex gap-2 overflow-x-auto scrollbar-thin scrollbar-thumb-gray-300 scrollbar-track-gray-100 pb-2">
                      {images.map((img: string, idx: number) => {
                        const isSelected = selectedImage === img;
                        return (
                          <button 
                            key={`${img}-${idx}`} 
                            onClick={() => switchMainImage(img)} 
                            disabled={isSlideRunning}
                            className={`
                              relative w-16 h-16 rounded-md overflow-hidden shrink-0 border-2 transition-all duration-200 disabled:cursor-not-allowed disabled:opacity-70
                              ${isSelected 
                                ? 'border-black' 
                                : 'border-gray-200 hover:border-gray-400'
                              }
                            `}
                            aria-label={`View image ${idx + 1}`}
                          >
                            <img 
                              src={img} 
                              alt={`Thumbnail ${idx + 1}`} 
                              className="w-full h-full object-cover" 
                            />
                          </button>
                        );
                      })}
                    </div>
                  </div>
                )}
              </div>
            </div>

            <div className="w-full xl:w-[420px] px-4 sm:px-6 xl:px-0 pt-4 xl:pt-0">
              <h1 className="text-lg sm:text-xl xl:text-2xl font-bold mt-0 mb-2 text-black">{product.name}</h1>
              
              {product.brand && (
                <div className="text-sm text-gray-600 mb-2">Brand: {product.brand}</div>
              )}
              
              <div className="flex items-center gap-4 mb-3">
                <div className="flex items-center gap-2">
                  {product.compare_at_price && (
                    <div className="text-sm text-gray-400 line-through">{product.compare_at_price}</div>
                  )}
                  <div className="text-2xl xl:text-xl font-bold text-black">{product.price}</div>
                </div>
                <div className="text-sm text-gray-600">
                  {product.views_count || 0} views · {product.sales_count || 0} sold
                </div>
              </div>

              {product.stock_quantity !== undefined && (
                <div className={`text-sm mb-4 ${product.stock_quantity > 0 ? 'text-green-600' : 'text-red-600'}`}>
                  {product.stock_quantity > 0 ? `${product.stock_quantity} in stock` : 'Out of stock'}
                </div>
              )}

              {product.description && (
                <div className="mb-6">
                  <div className="text-sm font-medium text-black mb-2">Description</div>
                  <p className="text-sm text-gray-700 leading-relaxed">{product.description}</p>
                </div>
              )}

              {product.category && (
                <div className="text-xs text-gray-500 mb-4">Category: {product.category}</div>
              )}

              {/* Color Selection - Adidas Style */}
              {hasColorVariants ? (
                <div className="mb-6">
                  <div className="text-sm font-semibold text-gray-900 mb-3">
                    Color: <span className="font-normal">{selectedColor}</span>
                  </div>
                  <div className="flex flex-wrap gap-3">
                    {product.colorVariants.map((colorVariant: ColorVariant) => {
                      const thumbnail = colorVariant.images.find(img => img.is_thumbnail) || colorVariant.images[0];
                      const isSelected = colorVariant.color_name.toLowerCase() === selectedColor?.toLowerCase();

                      return (
                        <button
                          key={colorVariant.id}
                          onClick={() => setSelectedColor(colorVariant.color_name)}
                          className={`relative group transition-all ${
                            isSelected
                              ? 'ring-2 ring-black ring-offset-2'
                              : 'ring-1 ring-gray-300 hover:ring-gray-400'
                          } rounded-lg overflow-hidden`}
                          title={colorVariant.color_name}
                        >
                          {thumbnail && (
                            <img
                              src={thumbnail.image_path}
                              alt={colorVariant.color_name}
                              className="w-20 h-20 object-cover"
                            />
                          )}
                          {!thumbnail && (
                            <div
                              className="w-20 h-20"
                              style={{ backgroundColor: colorVariant.color_code }}
                            />
                          )}
                          <div className="absolute inset-x-0 bottom-0 bg-black/70 text-white text-[10px] py-1 px-1 text-center font-medium">
                            {colorVariant.color_name}
                          </div>
                          {isSelected && (
                            <div className="absolute top-1 right-1 bg-black text-white rounded-full p-0.5">
                              <svg className="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                              </svg>
                            </div>
                          )}
                        </button>
                      );
                    })}
                  </div>
                  <div className="text-xs text-gray-500 mt-2">
                    {selectedColorVariant?.images.length || 0} images available for this color
                  </div>
                </div>
              ) : (
                /* Legacy Color Selection */
                ((product.colors_available && Array.isArray(product.colors_available) && product.colors_available.length > 0) || (product.colors && Array.isArray(product.colors) && product.colors.length > 0)) && (
                  <div className="mb-6">
                    <div className="text-sm font-semibold text-gray-900 mb-3">Color: {selectedColor}</div>
                    <div className="flex flex-wrap gap-2">
                      {(product.colors_available || product.colors).map((color: string) => {
                        const colorVariants = product.variants?.filter((v: any) => 
                          String(v.color).toLowerCase() === String(color).toLowerCase() && v.image
                        );
                        const colorImage = colorVariants?.[0]?.image || product.primary || images[0];

                        return (
                          <button
                            key={color}
                            onClick={() => {
                              setSelectedColor(color);
                              if (colorImage) switchMainImage(colorImage);
                            }}
                            className={`relative group ${
                              String(selectedColor).toLowerCase() === String(color).toLowerCase()
                                ? 'ring-2 ring-black ring-offset-2'
                                : 'ring-1 ring-gray-300 hover:ring-gray-400'
                            } rounded overflow-hidden transition-all`}
                            title={color}
                          >
                            <img
                              src={colorImage}
                              alt={color}
                              className="w-16 h-16 object-cover"
                            />
                            <div className="absolute inset-x-0 bottom-0 bg-black/70 text-white text-[10px] py-0.5 px-1 text-center">
                              {color}
                            </div>
                          </button>
                        );
                      })}
                    </div>
                  </div>
                )
              )}

              <div className="mt-6">
                  <div className="flex items-center justify-between mb-2">
                  <div className="text-sm text-black">Size</div>
                  <button onClick={() => setShowSizeChart(true)} className="text-sm text-black underline" type="button">Size Chart</button>
                </div>
                <div className="flex gap-2">
                  {sizeOptions.map((option: SizeOption) => (
                    <button
                      key={option.key}
                      onClick={() => setSelectedSize(option.value)}
                      className={`px-3 py-2 border rounded ${isSameSize(selectedSize, option.value) ? 'bg-black text-white' : 'bg-white text-black'}`}
                    >
                      {option.label}
                    </button>
                  ))}
                </div>
              </div>

              {showSizeChart && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-3 sm:p-4" onClick={() => setShowSizeChart(false)}>
                  <div
                    className="w-[94vw] max-h-[86vh] overflow-hidden rounded-2xl bg-white text-black shadow-2xl sm:max-h-[90vh] sm:w-[90%] sm:max-w-2xl"
                    onClick={(e) => e.stopPropagation()}
                  >
                    <div className="sticky top-0 z-10 border-b border-gray-100 bg-white/95 px-4 py-3 backdrop-blur sm:px-6 sm:py-4">
                      <div className="flex items-start justify-between gap-3">
                        <div>
                          <h3 className="text-base font-semibold text-black sm:text-lg">Size Chart</h3>
                          <p className="mt-0.5 text-[11px] text-gray-500 sm:text-xs">Choose the closest foot length for a better fit.</p>
                        </div>
                        <button
                          onClick={() => setShowSizeChart(false)}
                          className="inline-flex h-8 w-8 items-center justify-center rounded-full text-black transition-colors hover:bg-gray-100 hover:text-gray-600"
                          title="Close"
                          aria-label="Close"
                        >
                          ×
                        </button>
                      </div>
                    </div>

                    {/* Shoe size chart (approximate conversions). Replace with product-specific chart if available. */}
                    <div className="max-h-[calc(86vh-86px)] overflow-auto px-2 pb-4 sm:max-h-[calc(90vh-88px)] sm:px-4 sm:pb-5">
                      <table className="w-full min-w-[520px] border-collapse text-[12px] text-black sm:text-sm">
                        <thead className="sticky top-0 z-[1] bg-white">
                          <tr className="text-left">
                            <th className="whitespace-nowrap px-2 py-2 font-semibold sm:px-3">US</th>
                            <th className="whitespace-nowrap px-2 py-2 font-semibold sm:px-3">UK</th>
                            <th className="whitespace-nowrap px-2 py-2 font-semibold sm:px-3">AU</th>
                            <th className="whitespace-nowrap px-2 py-2 font-semibold sm:px-3">EU</th>
                            <th className="whitespace-nowrap px-2 py-2 font-semibold sm:px-3">CN</th>
                            <th className="whitespace-nowrap px-2 py-2 font-semibold sm:px-3">Foot Length (cm)</th>
                          </tr>
                        </thead>
                        <tbody>
                          <tr className="border-t border-gray-100">
                            <td className="px-2 py-2 text-black sm:px-3">5</td>
                            <td className="px-2 py-2 text-black sm:px-3">4.5</td>
                            <td className="px-2 py-2 text-black sm:px-3">4.5</td>
                            <td className="px-2 py-2 text-black sm:px-3">37</td>
                            <td className="px-2 py-2 text-black sm:px-3">235</td>
                            <td className="px-2 py-2 text-black sm:px-3">23.1</td>
                          </tr>
                          <tr className="border-t border-gray-100">
                            <td className="px-2 py-2 sm:px-3">6</td>
                            <td className="px-2 py-2 sm:px-3">5.5</td>
                            <td className="px-2 py-2 sm:px-3">5.5</td>
                            <td className="px-2 py-2 sm:px-3">38</td>
                            <td className="px-2 py-2 sm:px-3">240</td>
                            <td className="px-2 py-2 sm:px-3">24.1</td>
                          </tr>
                          <tr className="border-t border-gray-100">
                            <td className="px-2 py-2 sm:px-3">7</td>
                            <td className="px-2 py-2 sm:px-3">6.5</td>
                            <td className="px-2 py-2 sm:px-3">6.5</td>
                            <td className="px-2 py-2 sm:px-3">40</td>
                            <td className="px-2 py-2 sm:px-3">255</td>
                            <td className="px-2 py-2 sm:px-3">25.4</td>
                          </tr>
                          <tr className="border-t border-gray-100">
                            <td className="px-2 py-2 sm:px-3">8</td>
                            <td className="px-2 py-2 sm:px-3">7.5</td>
                            <td className="px-2 py-2 sm:px-3">7.5</td>
                            <td className="px-2 py-2 sm:px-3">41</td>
                            <td className="px-2 py-2 sm:px-3">260</td>
                            <td className="px-2 py-2 sm:px-3">26.0</td>
                          </tr>
                          <tr className="border-t border-gray-100">
                            <td className="px-2 py-2 sm:px-3">9</td>
                            <td className="px-2 py-2 sm:px-3">8.5</td>
                            <td className="px-2 py-2 sm:px-3">8.5</td>
                            <td className="px-2 py-2 sm:px-3">42</td>
                            <td className="px-2 py-2 sm:px-3">270</td>
                            <td className="px-2 py-2 sm:px-3">27.0</td>
                          </tr>
                          <tr className="border-t border-gray-100">
                            <td className="px-2 py-2 sm:px-3">10</td>
                            <td className="px-2 py-2 sm:px-3">9.5</td>
                            <td className="px-2 py-2 sm:px-3">9.5</td>
                            <td className="px-2 py-2 sm:px-3">44</td>
                            <td className="px-2 py-2 sm:px-3">280</td>
                            <td className="px-2 py-2 sm:px-3">28.0</td>
                          </tr>
                          <tr className="border-t border-gray-100">
                            <td className="px-2 py-2 sm:px-3">11</td>
                            <td className="px-2 py-2 sm:px-3">10.5</td>
                            <td className="px-2 py-2 sm:px-3">10.5</td>
                            <td className="px-2 py-2 sm:px-3">45</td>
                            <td className="px-2 py-2 sm:px-3">285</td>
                            <td className="px-2 py-2 sm:px-3">28.7</td>
                          </tr>
                          <tr className="border-t border-gray-100">
                            <td className="px-2 py-2 sm:px-3">12</td>
                            <td className="px-2 py-2 sm:px-3">11.5</td>
                            <td className="px-2 py-2 sm:px-3">11.5</td>
                            <td className="px-2 py-2 sm:px-3">46</td>
                            <td className="px-2 py-2 sm:px-3">294</td>
                            <td className="px-2 py-2 sm:px-3">29.4</td>
                          </tr>
                          <tr className="border-t border-gray-100">
                            <td className="px-2 py-2 sm:px-3">13</td>
                            <td className="px-2 py-2 sm:px-3">12.5</td>
                            <td className="px-2 py-2 sm:px-3">12.5</td>
                            <td className="px-2 py-2 sm:px-3">47</td>
                            <td className="px-2 py-2 sm:px-3">302</td>
                            <td className="px-2 py-2 sm:px-3">30.2</td>
                          </tr>
                        </tbody>
                      </table>
                    </div>
                  </div>
                </div>
              )}

              <div className="mt-6">
                <div className="text-sm text-black mb-2">Quantity</div>
                <div className="flex items-center gap-3">
                  <button 
                    onClick={() => setQty(Math.max(1, qty - 1))} 
                    className={qtyStepperButtonClass}
                    aria-label="Decrease quantity"
                    title="Decrease quantity"
                  >
                    <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M20 12H4" />
                    </svg>
                  </button>
                  <input
                    type="text"
                    inputMode="numeric"
                    pattern="[0-9]*"
                    value={qty}
                    onChange={(e) => {
                      const value = parseInt(e.target.value) || 1;
                      const maxQty = mainPageVariantQuantity > 0 ? mainPageVariantQuantity : 1;
                      setQty(Math.max(1, Math.min(value, maxQty)));
                    }}
                    className={qtyInputClass}
                    min="1"
                    max={mainPageVariantQuantity > 0 ? mainPageVariantQuantity : 1}
                    aria-label="Quantity"
                    title="Quantity"
                  />
                  <button 
                    onClick={() => {
                      const maxQty = mainPageVariantQuantity > 0 ? mainPageVariantQuantity : 1;
                      setQty(Math.min(qty + 1, maxQty));
                    }}
                    disabled={qty >= mainPageVariantQuantity}
                    className={qtyStepperButtonClass}
                    aria-label="Increase quantity"
                    title="Increase quantity"
                  >
                    <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                    </svg>
                  </button>
                  {selectedSize && selectedColor && (
                    <span className={`ml-auto px-3 py-1.5 rounded text-xs font-semibold ${
                      mainPageVariantQuantity > 0 ? 'bg-gray-100 text-gray-800' : 'bg-red-50 text-red-600'
                    }`}>
                      {mainPageVariantQuantity > 0 ? 'IN STOCK' : 'OUT OF STOCK'}
                    </span>
                  )}
                </div>
                {selectedSize && selectedColor && mainPageVariantQuantity > 0 && (
                  <p className="text-xs text-gray-500 mt-2">
                    <span className={mainPageVariantQuantity <= 10 ? 'text-orange-600 font-semibold' : 'text-gray-600'}>
                      {mainPageVariantQuantity} {mainPageVariantQuantity === 1 ? 'piece' : 'pieces'} available
                    </span>
                  </p>
                )}
              </div>

              {/* Desktop CTA buttons */}
              <div className="mt-6 hidden xl:flex gap-3">
                <AddToCartButton
                  productId={product.id}
                  product={{ 
                    ...product, 
                    size: selectedSize, 
                    color: selectedColor,
                    qty: qty,
                    selectedImage: selectedImage
                  }}
                  className={`flex-1 ${buttonBaseClass} ${buttonLightClass}`}
                  label="Add to Cart"
                  disabled={!selectedSize || !selectedColor || mainPageVariantQuantity === 0}
                />
                <AddToCartButton
                  productId={product.id}
                  product={{ 
                    ...product, 
                    size: selectedSize, 
                    color: selectedColor,
                    qty: qty,
                    selectedImage: selectedImage
                  }}
                  className={`flex-1 ${buttonBaseClass} ${buttonDarkClass}`}
                  label="Buy Now"
                  buyNow={true}
                  disabled={!selectedSize || !selectedColor || mainPageVariantQuantity === 0}
                />
              </div>

              {/* Mobile/Tablet sticky bottom CTA bar - Shopee style */}
              <div className="fixed bottom-0 left-0 right-0 z-40 flex items-stretch border-t border-gray-200 bg-white shadow-[0_-4px_20px_-4px_rgba(0,0,0,0.12)] xl:hidden">
                {/* Shop icon */}
                <Link
                  href={product.shop?.id ? `/shop-profile/${product.shop.id}` : route('products')}
                  className="flex w-[3.75rem] shrink-0 flex-col items-center justify-center gap-0.5 py-2.5 text-gray-600 hover:text-[#16233b] transition-colors"
                  aria-label="Visit shop"
                >
                  <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M9 22V12h6v10" />
                  </svg>
                  <span className="text-[10px] font-medium">Shop</span>
                </Link>

                {/* Chat icon */}
                <Link
                  href={isAuthenticated ? '/messages' : '/user/login'}
                  className="flex w-[3.75rem] shrink-0 flex-col items-center justify-center gap-0.5 border-l border-gray-200 py-2.5 text-gray-600 hover:text-[#16233b] transition-colors"
                  aria-label="Chat"
                >
                  <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M7 8h10M7 12h6m-8 7l3.5-2H19a3 3 0 003-3V7a3 3 0 00-3-3H5a3 3 0 00-3 3v7a3 3 0 003 3h1l1 2z" />
                  </svg>
                  <span className="text-[10px] font-medium">Chat</span>
                </Link>

                {/* Add to Cart + Buy Now */}
                <div className="flex flex-1 items-stretch border-l border-gray-200">
                  <AddToCartButton
                    productId={product.id}
                    product={{ 
                      ...product, 
                      size: selectedSize, 
                      color: selectedColor,
                      qty: qty,
                      selectedImage: selectedImage
                    }}
                    className="flex-1 rounded-none border-0 border-r border-gray-200 py-3.5 text-[11px] font-bold uppercase tracking-wider text-[#16233b] bg-white hover:bg-gray-50 transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                    label="Add to Cart"
                    disabled={!selectedSize || !selectedColor || mainPageVariantQuantity === 0}
                  />
                  <AddToCartButton
                    productId={product.id}
                    product={{ 
                      ...product, 
                      size: selectedSize, 
                      color: selectedColor,
                      qty: qty,
                      selectedImage: selectedImage
                    }}
                    className="flex-1 rounded-none border-0 py-3.5 text-[11px] font-bold uppercase tracking-wider text-white bg-[#16233b] hover:bg-black transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                    label="Buy Now"
                    buyNow={true}
                    disabled={!selectedSize || !selectedColor || mainPageVariantQuantity === 0}
                  />
                </div>
              </div>

              {/* Add to Cart Modal - Shopee Style */}
              {showAddToCartModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" onClick={() => setShowAddToCartModal(false)}>
                  <div className="bg-white rounded-lg max-w-4xl w-full max-h-[90vh] overflow-hidden shadow-2xl" onClick={(e) => e.stopPropagation()}>
                    {/* Close Button */}
                    <button
                      onClick={() => setShowAddToCartModal(false)}
                      className="absolute top-3 right-3 z-10 w-9 h-9 flex items-center justify-center rounded-full bg-white/90 hover:bg-white shadow-md text-gray-600 hover:text-gray-900 transition-all"
                    >
                      <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                      </svg>
                    </button>

                    <div className="grid md:grid-cols-2 gap-0 overflow-y-auto max-h-[90vh]">
                      {/* Left: Product Image Section */}
                      <div className="relative bg-gray-50 p-6 md:p-8">
                        <div className="sticky top-0">
                          <div className="relative w-full h-80 bg-white rounded-lg flex items-center justify-center mb-4">
                            <img 
                              src={modalSelectedImage} 
                              alt={product.name} 
                              className="max-w-full max-h-full object-contain p-4"
                            />
                          </div>
                          
                          {/* Thumbnail Gallery */}
                          <div className="flex gap-2 overflow-x-auto pb-2">
                            {filteredImages.map((img: string, idx: number) => (
                              <button
                                key={`modal-${img}-${idx}`}
                                onClick={() => setModalSelectedImage(img)}
                                className={`w-16 h-16 rounded border-2 flex-shrink-0 bg-white transition-all ${
                                  modalSelectedImage === img 
                                    ? 'border-black' 
                                    : 'border-gray-200 hover:border-gray-400'
                                }`}
                              >
                                <img src={img} alt={`View ${idx + 1}`} className="w-full h-full object-contain" />
                              </button>
                            ))}
                          </div>
                        </div>
                      </div>

                      {/* Right: Product Details & Options */}
                      <div className="flex flex-col">
                        <div className="flex-1 overflow-y-auto p-6">
                          {/* Product Title & Price */}
                          <div className="mb-4 pb-4 border-b">
                            <h2 className="text-xl font-bold text-gray-900 mb-3">{product.name}</h2>
                            <div className="flex items-baseline gap-3">
                              <span className="text-2xl font-bold text-red-600">{product.price}</span>
                              {product.compare_at_price && (
                                <span className="text-base text-gray-400 line-through">{product.compare_at_price}</span>
                              )}
                            </div>
                            <div className="text-sm text-gray-600 mt-2">
                              Stock: <span className="font-semibold text-gray-900">{variantQuantity > 0 ? variantQuantity : '0'}</span>
                            </div>
                          </div>

                          {/* Color Selection with Thumbnails */}
                          {(product.colors_available || product.colors) && (product.colors_available || product.colors).length > 0 && (
                            <div className="mb-6">
                              <label className="text-sm font-semibold text-gray-900 mb-3 block">Color</label>
                              <div className="flex flex-wrap gap-2">
                                {(product.colors_available || product.colors).map((color: string) => {
                                  // Get the variant image for this color
                                  const colorVariant = product.variants?.find((v: any) => 
                                    String(v.color).toLowerCase() === String(color).toLowerCase()
                                  );
                                  const colorImage = colorVariant?.image || modalSelectedImage;
                                  
                                  return (
                                    <button
                                      key={color}
                                      onClick={() => setModalSelectedColor(color)}
                                      className={`flex items-center gap-2 px-3 py-2 border-2 rounded-lg transition-all ${
                                        modalSelectedColor === color
                                          ? 'border-black bg-gray-50'
                                          : 'border-gray-200 hover:border-gray-300'
                                      }`}
                                    >
                                      <div className="w-10 h-10 rounded overflow-hidden border border-gray-200 bg-white flex-shrink-0">
                                        <img src={colorImage} alt={color} className="w-full h-full object-contain" />
                                      </div>
                                      <span className="text-sm font-medium text-gray-900">
                                        {color}
                                      </span>
                                    </button>
                                  );
                                })}
                              </div>
                            </div>
                          )}

                          {/* Size Selection Grid */}
                          {modalSizeOptions.length > 0 && (
                            <div className="mb-6">
                              <div className="flex items-center justify-between mb-3">
                                <label className="text-sm font-semibold text-gray-900">Size</label>
                                <button 
                                  onClick={() => setShowSizeChart(true)} 
                                  className="text-xs text-gray-600 hover:text-black underline"
                                  type="button"
                                >
                                  Size Guide
                                </button>
                              </div>
                              <div className="grid grid-cols-5 gap-2">
                                {modalSizeOptions.map((option: SizeOption) => {
                                  // Check if this size is available for the selected color
                                  const isAvailable = modalSelectedColor 
                                    ? product.variants?.some((v: any) => 
                                        isSameSize(v.size, option.value) && 
                                        String(v.color).toLowerCase() === String(modalSelectedColor).toLowerCase() &&
                                        v.quantity > 0
                                      )
                                    : true;

                                  return (
                                    <button
                                      key={option.key}
                                      onClick={() => setModalSelectedSize(option.value)}
                                      disabled={!isAvailable}
                                      className={`px-3 py-2.5 border-2 rounded-lg text-sm font-medium transition-all ${
                                        isSameSize(modalSelectedSize, option.value)
                                          ? 'border-black bg-black text-white'
                                          : isAvailable
                                          ? 'border-gray-200 bg-white text-gray-700 hover:border-gray-400'
                                          : 'border-gray-200 bg-gray-100 text-gray-400 cursor-not-allowed line-through'
                                      }`}
                                    >
                                      {option.label}
                                    </button>
                                  );
                                })}
                              </div>
                            </div>
                          )}


                          {/* Quantity Selection */}
                          <div className="mb-4">
                            <label className="text-sm font-semibold text-gray-900 mb-3 block">Quantity</label>
                            <div className="flex items-center gap-3">
                              <button
                                onClick={() => setModalQty(Math.max(1, modalQty - 1))}
                                className={qtyStepperButtonClass}
                                aria-label="Decrease quantity"
                                title="Decrease quantity"
                              >
                                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M20 12H4" />
                                </svg>
                              </button>
                              <input
                                type="text"
                                inputMode="numeric"
                                pattern="[0-9]*"
                                value={modalQty}
                                onChange={(e) => {
                                  const value = parseInt(e.target.value) || 1;
                                  const maxQty = variantQuantity > 0 ? variantQuantity : 1;
                                  setModalQty(Math.max(1, Math.min(value, maxQty)));
                                }}
                                className={qtyInputClass}
                                min="1"
                                max={variantQuantity > 0 ? variantQuantity : 1}
                                aria-label="Quantity"
                                title="Quantity"
                              />
                              <button
                                onClick={() => {
                                  const maxQty = variantQuantity > 0 ? variantQuantity : 1;
                                  setModalQty(Math.min(modalQty + 1, maxQty));
                                }}
                                disabled={modalQty >= variantQuantity}
                                className={qtyStepperButtonClass}
                                aria-label="Increase quantity"
                                title="Increase quantity"
                              >
                                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                                </svg>
                              </button>
                              {modalSelectedSize && modalSelectedColor && (
                                <span className={`ml-auto px-3 py-1.5 rounded text-xs font-semibold ${
                                  variantQuantity > 0 ? 'bg-gray-100 text-gray-800' : 'bg-red-50 text-red-600'
                                }`}>
                                  {variantQuantity > 0 ? 'IN STOCK' : 'OUT OF STOCK'}
                                </span>
                              )}
                            </div>
                            {modalSelectedSize && modalSelectedColor && variantQuantity > 0 && (
                              <p className="text-xs text-gray-500 mt-2">
                                <span className={variantQuantity <= 10 ? 'text-orange-600 font-semibold' : 'text-gray-600'}>
                                  {variantQuantity} {variantQuantity === 1 ? 'piece' : 'pieces'} available
                                </span>
                              </p>
                            )}
                          </div>
                        </div>

                        {/* Actions - Fixed at Bottom */}
                        <div className="border-t p-4 bg-white">
                          <AddToCartButton
                            productId={product.id}
                            product={{ 
                              ...product, 
                              size: modalSelectedSize, 
                              color: modalSelectedColor,
                              qty: modalQty,
                              selectedImage: modalSelectedImage
                            }}
                            className={`w-full ${buttonBaseClass} ${buttonDarkClass}`}
                            label="Add to Cart"
                            onAdded={() => {
                              setShowAddToCartModal(false);
                              if (!isAuthenticated) setShowAddedModal(true);
                            }}
                            disabled={!modalSelectedSize || !modalSelectedColor || variantQuantity === 0}
                          />
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              )}

              {showAddedModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50" onClick={() => setShowAddedModal(false)}>
                  <div className="bg-white rounded-xl w-[900px] max-w-[95%] p-6 grid grid-cols-2 gap-6 relative" onClick={(e) => e.stopPropagation()}>
                      <div className="flex items-center justify-center">
                        <img src={product.primary || (product.images && product.images[0])} alt={product.name} className="w-full h-[420px] object-contain rounded" />
                      </div>

                      <div className="p-4 flex flex-col justify-center">
                        <h2 className="text-3xl font-bold lowercase mb-2 text-black">welcome to solespace</h2>
                        <p className="text-sm text-black mb-6">We ship nationwide and offer shoe care, repairs, and exclusive drops. Be the first to know about restocks, repair offers, and everything Solespace.</p>

                      <div className="flex flex-col gap-3 mt-4">
                        <button type="button" onClick={() => router.visit('/register')} className="w-full py-3 border border-gray-200 rounded text-center text-black hover:bg-gray-50">Sign Up</button>
                        <button onClick={() => setShowAddedModal(false)} className="w-full py-3 border border-gray-200 rounded text-center text-black hover:bg-gray-50">No thanks</button>
                      </div>
                    </div>
                  </div>
                </div>
              )}

              <div className="mt-8 border-t pt-6">
                {product.shop?.id && product.shop?.name && (
                  <>
                    <div className="text-sm text-black mb-2">Sold by</div>
                    <div className="text-sm font-medium mb-4">
                      <a href={`/shop-profile/${product.shop.id}`} className="underline text-black hover:text-gray-700 transition-colors">
                        {product.shop.name}
                      </a>
                    </div>
                  </>
                )}
              </div>
            </div>
          </div>

          {/* Voucher claim strip (horizontal) */}
          <div className="mt-8 xl:mt-12 px-4 sm:px-6 xl:px-0">
            <div className="mx-auto max-w-260 overflow-hidden rounded-[26px] border border-gray-200 bg-white p-4 shadow-[0_16px_36px_-24px_rgba(15,23,42,0.35)] sm:p-5">
              <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200/80 pb-3">
                <div>
                  <p className="text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-500">Vouchers</p>
                  <h2 className="mt-1 text-sm font-bold text-slate-900 sm:text-base">Claim Available Vouchers</h2>
                  <p className="mt-1 text-[11px] text-slate-500 lg:hidden">Swipe left to view more offers.</p>
                </div>
              </div>

              <div className="-mx-1 mt-4 flex snap-x snap-mandatory gap-3 overflow-x-auto px-1 pb-2 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden lg:mx-0 lg:justify-center lg:overflow-visible lg:px-0">
                {staticVoucherCampaigns.map((campaign) => {
                  const isClaimed = claimedPromoIds.includes(campaign.id);

                  return (
                    <div
                      key={campaign.id}
                      className="w-[82vw] max-w-[320px] shrink-0 snap-start rounded-2xl border border-white/80 bg-white/90 p-3.5 shadow-[0_14px_28px_-22px_rgba(15,23,42,0.45)] backdrop-blur-sm sm:w-auto sm:min-w-80 sm:max-w-none sm:p-4"
                    >
                      <div className="flex items-center justify-between gap-2">
                        <span className="inline-flex rounded-full bg-blue-50 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wide text-blue-700 ring-1 ring-inset ring-blue-200">
                          Voucher
                        </span>
                        <span className="truncate text-[10px] font-semibold tracking-[0.12em] text-slate-500 sm:text-[11px]">{campaign.code}</span>
                      </div>

                      <p className="mt-2.5 text-sm font-semibold text-slate-900">{campaign.name}</p>
                      <p className="mt-1 text-xs font-medium text-slate-700">{campaign.value}</p>
                      <p className="mt-0.5 text-xs text-slate-500">{campaign.minSpend}</p>
                      <p className="mt-1 line-clamp-2 text-[11px] text-slate-400">{campaign.schedule}</p>

                      <button
                        type="button"
                        onClick={() => handleClaimPromo(campaign)}
                        disabled={isClaimed}
                        className={`mt-4 inline-flex w-full items-center justify-center rounded-xl px-3 py-2.5 text-[11px] font-semibold uppercase tracking-[0.14em] transition-all ${
                          isClaimed
                            ? 'cursor-not-allowed bg-slate-100 text-slate-400'
                            : 'bg-slate-900 text-white shadow-sm hover:-translate-y-0.5 hover:bg-slate-800'
                        }`}
                      >
                        {isClaimed ? 'Claimed' : 'Claim Voucher'}
                      </button>
                    </div>
                  );
                })}
              </div>
            </div>
          </div>

          {/* Reviews and Ratings Section */}
          <div className="mt-8 xl:mt-16 px-4 sm:px-6 xl:px-0" id="reviews">
            <div className="border-t pt-6 xl:pt-12">

              {/* ── Write a Review card ── */}
              <div className="mb-6 overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                <div className="border-b border-gray-100 bg-gray-50 px-4 py-3">
                  <h2 className="text-sm font-bold uppercase tracking-widest text-black">Customer Reviews</h2>
                </div>

                <div className="p-4">
                  {!isAuthenticated ? (
                    <div className="flex flex-col items-center py-6 text-center">
                      <svg className="mb-3 h-10 w-10 text-yellow-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                      </svg>
                      <p className="text-sm font-semibold text-gray-800">Only verified buyers can review this product.</p>
                      <p className="mt-1 text-xs text-gray-500">Purchase and receive your order to leave a review.</p>
                    </div>
                  ) : userExistingReview ? (
                    <div className="flex flex-col items-center py-6 text-center">
                      <svg className="mb-3 h-10 w-10 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                      </svg>
                      <p className="text-sm font-semibold text-gray-800">You've already reviewed this product.</p>
                      <button
                        onClick={() => setShowMyReview(!showMyReview)}
                        className="mt-3 rounded-full border border-[#16233b] px-4 py-1.5 text-xs font-semibold uppercase tracking-wider text-[#16233b] transition-colors hover:bg-[#16233b] hover:text-white"
                        type="button"
                      >
                        {showMyReview ? 'Hide My Review' : 'View My Review'}
                      </button>
                      {showMyReview && (
                        <div className="mt-4 w-full rounded-xl border border-gray-200 p-4 text-left">
                          <div className="mb-2 flex items-center gap-2">
                            {renderStars(userExistingReview.rating)}
                            <span className="text-xs text-gray-500">{new Date(userExistingReview.created_at).toLocaleDateString()}</span>
                          </div>
                          <p className="text-sm text-gray-700">{userExistingReview.comment}</p>
                        </div>
                      )}
                    </div>
                  ) : !canReview ? (
                    <div className="flex flex-col items-center py-6 text-center">
                      <svg className="mb-3 h-10 w-10 text-yellow-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                      </svg>
                      <p className="text-sm font-semibold text-gray-800">{reviewEligibility?.message}</p>
                      {reviewEligibility?.reason === 'pending_delivery' && (
                        <p className="mt-1 text-xs text-gray-500">Order status: <span className="font-medium capitalize">{reviewEligibility.order_status}</span></p>
                      )}
                    </div>
                  ) : (
                    <>
                      {/* Verified badge */}
                      <div className="mb-4 flex items-center gap-2">
                        <span className="inline-flex items-center gap-1 rounded-full bg-green-50 px-2.5 py-1 text-xs font-semibold text-green-700">
                          <svg className="h-3 w-3" fill="currentColor" viewBox="0 0 20 20">
                            <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
                          </svg>
                          Verified Purchase
                        </span>
                        <span className="text-sm font-semibold text-black">Write a Review</span>
                      </div>

                      {/* Star picker */}
                      <div className="mb-4">
                        <p className="mb-1.5 text-xs font-medium uppercase tracking-wider text-gray-500">Your Rating</p>
                        {renderStars(userRating, true, setUserRating)}
                      </div>

                      {/* Comment textarea */}
                      <div className="mb-4">
                        <p className="mb-1.5 text-xs font-medium uppercase tracking-wider text-gray-500">Your Review</p>
                        <textarea
                          value={newComment}
                          onChange={(e) => setNewComment(e.target.value)}
                          placeholder="Share your experience with this product..."
                          className="w-full resize-none rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-black placeholder:text-gray-400 focus:border-[#16233b] focus:bg-white focus:outline-none focus:ring-1 focus:ring-[#16233b]/20"
                          rows={3}
                          minLength={10}
                          maxLength={2000}
                        />
                        <p className="mt-1 text-right text-[11px] text-gray-400">{newComment.length}/2000</p>
                      </div>

                      {/* Photo upload */}
                      <div className="mb-5">
                        <p className="mb-2 text-xs font-medium uppercase tracking-wider text-gray-500">Photos <span className="normal-case font-normal">(optional, max 5)</span></p>
                        <div className="flex gap-2 overflow-x-auto pb-1">
                          {imageUploadGroups.map((group) => (
                            <div key={group.id} className="relative shrink-0">
                              {group.preview ? (
                                <div className="relative h-20 w-20">
                                  <img src={group.preview} alt="Review photo" className="h-20 w-20 rounded-xl object-cover border border-gray-200" />
                                  <button
                                    onClick={() => removeImageBox(group.id)}
                                    className="absolute -right-1.5 -top-1.5 flex h-5 w-5 items-center justify-center rounded-full bg-red-500 text-white shadow"
                                    type="button"
                                    title="Remove photo"
                                  >
                                    <svg className="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                  </button>
                                </div>
                              ) : (
                                <label className="flex h-20 w-20 shrink-0 cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed border-gray-300 bg-gray-50 transition-colors hover:border-[#16233b]">
                                  <input type="file" accept="image/jpeg,image/jpg,image/png" onChange={(e) => handleImageUpload(group.id, e)} className="hidden" aria-label="Upload review photo" />
                                  <svg className="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                                  </svg>
                                  <span className="mt-1 text-[10px] text-gray-400">Add</span>
                                </label>
                              )}
                            </div>
                          ))}
                          {imageUploadGroups.length < 5 && imageUploadGroups.some(g => g.preview) && (
                            <button
                              onClick={addImageUploadBox}
                              className="flex h-20 w-20 shrink-0 items-center justify-center rounded-xl border-2 border-dashed border-gray-300 bg-gray-50 text-gray-400 transition-colors hover:border-[#16233b] hover:text-[#16233b]"
                              type="button"
                              title="Add more photos"
                            >
                              <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                              </svg>
                            </button>
                          )}
                        </div>
                      </div>

                      <button
                        onClick={handleSubmitReview}
                        disabled={isSubmittingReview || !newComment.trim() || newComment.length < 10 || userRating === 0}
                        className="w-full rounded-full bg-[#16233b] py-3 text-sm font-bold uppercase tracking-widest text-white transition-colors hover:bg-black disabled:cursor-not-allowed disabled:opacity-40"
                        type="button"
                      >
                        {isSubmittingReview ? 'Submitting…' : 'Submit Review'}
                      </button>
                    </>
                  )}
                </div>
              </div>

              {/* ── Reviews List ── */}
              <div className="space-y-3 xl:space-y-4">
                {reviews.length > 0 ? (
                  reviews.map((review: any) => (
                    <div key={review.id} className="overflow-hidden rounded-2xl border border-gray-200 bg-white">
                      <div className="flex items-start gap-3 p-4">
                        {/* Avatar */}
                        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[#16233b] text-sm font-bold text-white">
                          {review.user_name?.charAt(0).toUpperCase() || 'U'}
                        </div>

                        {/* Content */}
                        <div className="min-w-0 flex-1">
                          <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                            <span className="text-sm font-semibold text-black">{review.user_name}</span>
                            {review.is_verified_purchase && (
                              <span className="inline-flex items-center gap-0.5 rounded-full bg-green-50 px-2 py-0.5 text-[10px] font-semibold text-green-700">
                                <svg className="h-2.5 w-2.5" fill="currentColor" viewBox="0 0 20 20">
                                  <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
                                </svg>
                                Verified
                              </span>
                            )}
                          </div>

                          <div className="mt-1 flex items-center gap-2">
                            {renderStars(review.rating)}
                            <span className="text-[11px] text-gray-400">{review.formatted_date}</span>
                          </div>

                          <p className="mt-2 text-sm leading-relaxed text-gray-700">{review.comment}</p>

                          {/* Review photos — horizontal scroll on mobile */}
                          {review.images && review.images.length > 0 && (
                            <div className="mt-3 flex gap-2 overflow-x-auto pb-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                              {review.images.map((img: string, idx: number) => (
                                <img
                                  key={idx}
                                  src={img}
                                  alt={`Review photo ${idx + 1}`}
                                  className="h-20 w-20 shrink-0 cursor-pointer rounded-xl border border-gray-200 object-cover transition-opacity hover:opacity-80"
                                  onClick={() => setEnlargedImage(img)}
                                />
                              ))}
                            </div>
                          )}
                        </div>
                      </div>
                    </div>
                  ))
                ) : (
                  <div className="flex flex-col items-center rounded-2xl bg-gray-50 py-14 text-center">
                    <svg className="mb-3 h-14 w-14 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z" />
                    </svg>
                    <p className="text-sm font-semibold text-gray-600">No Reviews Yet</p>
                    <p className="mt-1 text-xs text-gray-400">Be the first verified buyer to review this product!</p>
                  </div>
                )}
              </div>

            </div>
          </div>
        </div>
      </div>



      {/* Virtual 3D Showroom Modal */}
      {show3DShowroom && hasShowroomFrames && (
        <Virtual3DShowroom
          productName={product.name}
          frameUrls={showroomFrameUrls}
          onClose={() => setShow3DShowroom(false)}
        />
      )}

      {/* Image Lightbox Modal */}
      {enlargedImage && (
        <div
          className="fixed inset-0 z-50 bg-black/80 flex items-center justify-center p-4"
          onClick={() => setEnlargedImage(null)}
        >
          <div
            className="relative w-full max-w-6xl"
            onClick={(e) => e.stopPropagation()}
          >
            <img
              src={enlargedImage}
              alt="Enlarged review"
              className="w-full max-h-[85vh] object-contain rounded-lg"
            />
            <button
              onClick={() => setEnlargedImage(null)}
              className="absolute top-4 right-4 w-10 h-10 bg-white rounded-full flex items-center justify-center hover:bg-gray-200 transition-colors"
              type="button"
              title="Close"
            >
              <svg xmlns="http://www.w3.org/2000/svg" className="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <line x1="18" y1="6" x2="6" y2="18" />
                <line x1="6" y1="6" x2="18" y2="18" />
              </svg>
            </button>
          </div>
        </div>
      )}
    </>
  );
};

export default ProductShow;
