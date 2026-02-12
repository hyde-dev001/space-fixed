import React, { useState, useEffect } from 'react';
import { Head, Link, usePage, router } from '@inertiajs/react';
import Navigation from './Navigation';
import AddToCartButton from '../../Components/CartActions';
import { CartGuestAddAttemptEvent, addCartGuestAddAttemptListener, removeCartGuestAddAttemptListener } from '../../types/cart-events';

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
};

const ProductShow: React.FC = () => {
  const { product, auth } = usePage().props as any;
  
  // Check if user is authenticated and is a regular customer (not ERP staff)
  const user = auth?.user;
  const userRole = user?.role?.toUpperCase();
  const isERPStaff = userRole && ['HR', 'FINANCE_STAFF', 'FINANCE_MANAGER', 'FINANCE', 'CRM', 'MANAGER', 'STAFF'].includes(userRole);
  const isAuthenticated = Boolean(user && !isERPStaff);
  
  // Check if product has color variants (new Adidas-style system)
  const hasColorVariants = product.colorVariants && Array.isArray(product.colorVariants) && product.colorVariants.length > 0;
  
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
  const [selectedSize, setSelectedSize] = useState<string | null>(() => {
    const sizes = product.sizes || [];
    const map: Record<string, number> = { XS: 6, S: 7, M: 8, L: 9, XL: 10, XXL: 11 };
    if (!sizes.length) return null;
    const first = sizes[0];
    if (/^\d+(?:\.\d+)?$/.test(String(first))) return String(first);
    return map[first] ? String(map[first]) : String(first);
  });
  const [selectedColor, setSelectedColor] = useState<string | null>(initialColor);
  
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

  // Filter images based on selected size and color in modal
  const getFilteredImages = () => {
    // If we have variants with images, filter by selected size and color
    if (product.variants && Array.isArray(product.variants) && product.variants.length > 0) {
      const filtered = product.variants.filter((v: any) => {
        const sizeMatch = !modalSelectedSize || String(v.size) === String(modalSelectedSize);
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
    if (product.variants && Array.isArray(product.variants) && product.variants.length > 0) {
      const variant = product.variants.find((v: any) => 
        String(v.size) === String(modalSelectedSize) && 
        String(v.color).toLowerCase() === String(modalSelectedColor).toLowerCase()
      );
      return variant ? variant.quantity : 0;
    }
    return product.stock_quantity || 0;
  };

  // Get variant-specific quantity for main page
  const getMainPageVariantQuantity = () => {
    if (product.variants && Array.isArray(product.variants) && product.variants.length > 0 && selectedSize && selectedColor) {
      const variant = product.variants.find((v: any) => 
        String(v.size) === String(selectedSize) && 
        String(v.color).toLowerCase() === String(selectedColor).toLowerCase()
      );
      return variant ? variant.quantity : 0;
    }
    return product.stock_quantity || 0;
  };

  const variantQuantity = getVariantQuantity();
  const mainPageVariantQuantity = getMainPageVariantQuantity();

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
      alert('Please provide both a rating and a comment');
      return;
    }

    if (!isAuthenticated) {
      alert('Please log in to write a review');
      return;
    }

    if (!canReview) {
      alert(reviewEligibility?.message || 'You are not eligible to review this product');
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
        alert('Thank you for your review!');
        setNewComment('');
        setUserRating(0);
        setImageUploadGroups([{id: '0', file: null, preview: ''}]);
        // Refresh reviews and eligibility
        await fetchReviews();
        await checkReviewEligibility();
      } else {
        alert(data.message || 'Failed to submit review');
      }
    } catch (error) {
      console.error('Failed to submit review:', error);
      alert('Failed to submit review. Please try again.');
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

  return (
    <>
      <Head title={product.name} />
      <div className="min-h-screen bg-white font-outfit antialiased">
        <Navigation />

        <div className="max-w-[1200px] mx-auto px-6 lg:px-12 py-12 lg:py-20">
          <div className="flex gap-8">
            <div className="flex-1">
              {/* Main Image Display - Adidas Style */}
              <div className="bg-white rounded-lg overflow-hidden">
                <div className="relative bg-gray-50 aspect-square flex items-center justify-center group">
                  <img 
                    src={selectedImage} 
                    alt={product.name} 
                    onClick={() => setEnlargedImage(selectedImage)}
                    className="w-full h-full object-contain cursor-zoom-in hover:scale-105 transition-transform duration-300" 
                  />
                  
                  {/* Navigation Arrow Buttons - Overlaid on Image */}
                  {images.length > 1 && (
                    <>
                      {/* Left Arrow */}
                      <button
                        onClick={() => {
                          const currentIdx = images.indexOf(selectedImage);
                          if (currentIdx > 0) {
                            setSelectedImage(images[currentIdx - 1]);
                          }
                        }}
                        disabled={images.indexOf(selectedImage) === 0}
                        className="absolute left-4 top-1/2 -translate-y-1/2 disabled:opacity-30 disabled:cursor-not-allowed transition-all opacity-0 group-hover:opacity-100"
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
                            setSelectedImage(images[currentIdx + 1]);
                          }
                        }}
                        disabled={images.indexOf(selectedImage) === images.length - 1}
                        className="absolute right-4 top-1/2 -translate-y-1/2 disabled:opacity-30 disabled:cursor-not-allowed transition-all opacity-0 group-hover:opacity-100"
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
                    {/* Image Counter */}
                    <div className="text-xs text-gray-500 mb-3 text-center">
                      Image {images.indexOf(selectedImage) + 1} of {images.length}
                    </div>

                    {/* Thumbnail Navigation Strip - Horizontal Scroll (Adidas Style) */}
                    <div className="flex gap-2 overflow-x-auto scrollbar-thin scrollbar-thumb-gray-300 scrollbar-track-gray-100 pb-2">
                      {images.map((img: string, idx: number) => {
                        const isSelected = selectedImage === img;
                        return (
                          <button 
                            key={`${img}-${idx}`} 
                            onClick={() => setSelectedImage(img)} 
                            className={`
                              relative w-16 h-16 rounded-md overflow-hidden flex-shrink-0 transition-all duration-200
                              ${isSelected 
                                ? 'ring-2 ring-black ring-offset-2' 
                                : 'ring-1 ring-gray-200 hover:ring-gray-400'
                              }
                            `}
                            aria-label={`View image ${idx + 1}`}
                          >
                            <img 
                              src={img} 
                              alt={`Thumbnail ${idx + 1}`} 
                              className="w-full h-full object-cover" 
                            />
                            {isSelected && (
                              <div className="absolute inset-0 bg-black bg-opacity-10"></div>
                            )}
                          </button>
                        );
                      })}
                    </div>
                  </div>
                )}
              </div>
            </div>

            <div className="w-[420px]">
              <h1 className="text-2xl font-bold mt-0 mb-2 text-black">{product.name}</h1>
              
              {product.brand && (
                <div className="text-sm text-gray-600 mb-2">Brand: {product.brand}</div>
              )}
              
              <div className="flex items-center gap-4 mb-3">
                <div className="flex items-center gap-2">
                  {product.compare_at_price && (
                    <div className="text-sm text-gray-400 line-through">{product.compare_at_price}</div>
                  )}
                  <div className="text-xl font-semibold text-black">{product.price}</div>
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
                              if (colorImage) setSelectedImage(colorImage);
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
                  {product.sizes && product.sizes.map((s: string) => {
                    const map: Record<string, number> = { XS: 6, S: 7, M: 8, L: 9, XL: 10, XXL: 11 };
                    const numeric = /^\d+(?:\.\d+)?$/.test(String(s)) ? String(s) : (map[s] ? String(map[s]) : String(s));

                    return (
                      <button key={s} onClick={() => setSelectedSize(numeric)} className={`px-3 py-2 border rounded ${String(selectedSize) === String(numeric) ? 'bg-black text-white' : 'bg-white text-black'}`}>
                        {numeric}
                      </button>
                    );
                  })}
                </div>
              </div>

              {showSizeChart && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40" onClick={() => setShowSizeChart(false)}>
                  <div className="bg-white rounded-lg max-w-2xl w-[90%] p-6 text-black" onClick={(e) => e.stopPropagation()}>
                    <div className="flex items-start justify-between mb-4">
                      <h3 className="text-lg font-semibold text-black">Size Chart</h3>
                      <button onClick={() => setShowSizeChart(false)} className="text-black hover:text-gray-600 text-xl" title="Close" aria-label="Close">×</button>
                    </div>

                    {/* Shoe size chart (approximate conversions). Replace with product-specific chart if available. */}
                    <div className="overflow-x-auto">
                      <table className="w-full text-sm border-collapse text-black">
                        <thead>
                          <tr className="text-left">
                            <th className="pb-2">US</th>
                            <th className="pb-2">UK</th>
                            <th className="pb-2">EU</th>
                            <th className="pb-2">Foot Length (cm)</th>
                          </tr>
                        </thead>
                        <tbody>
                          <tr className="border-t">
                            <td className="py-2 text-black">5</td>
                            <td className="py-2 text-black">4.5</td>
                            <td className="py-2 text-black">37</td>
                            <td className="py-2 text-black">23.1</td>
                          </tr>
                          <tr className="border-t">
                            <td className="py-2">6</td>
                            <td className="py-2">5.5</td>
                            <td className="py-2">38</td>
                            <td className="py-2">24.1</td>
                          </tr>
                          <tr className="border-t">
                            <td className="py-2">7</td>
                            <td className="py-2">6.5</td>
                            <td className="py-2">40</td>
                            <td className="py-2">25.4</td>
                          </tr>
                          <tr className="border-t">
                            <td className="py-2">8</td>
                            <td className="py-2">7.5</td>
                            <td className="py-2">41</td>
                            <td className="py-2">26.0</td>
                          </tr>
                          <tr className="border-t">
                            <td className="py-2">9</td>
                            <td className="py-2">8.5</td>
                            <td className="py-2">42</td>
                            <td className="py-2">27.0</td>
                          </tr>
                          <tr className="border-t">
                            <td className="py-2">10</td>
                            <td className="py-2">9.5</td>
                            <td className="py-2">44</td>
                            <td className="py-2">28.0</td>
                          </tr>
                          <tr className="border-t">
                            <td className="py-2">11</td>
                            <td className="py-2">10.5</td>
                            <td className="py-2">45</td>
                            <td className="py-2">28.7</td>
                          </tr>
                          <tr className="border-t">
                            <td className="py-2">12</td>
                            <td className="py-2">11.5</td>
                            <td className="py-2">46</td>
                            <td className="py-2">29.4</td>
                          </tr>
                          <tr className="border-t">
                            <td className="py-2">13</td>
                            <td className="py-2">12.5</td>
                            <td className="py-2">47</td>
                            <td className="py-2">30.2</td>
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
                    className="w-10 h-10 border border-gray-300 rounded flex items-center justify-center hover:bg-gray-50 transition-colors text-gray-700"
                  >
                    <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M20 12H4" />
                    </svg>
                  </button>
                  <input
                    type="number"
                    value={qty}
                    onChange={(e) => {
                      const value = parseInt(e.target.value) || 1;
                      const maxQty = mainPageVariantQuantity > 0 ? mainPageVariantQuantity : 1;
                      setQty(Math.max(1, Math.min(value, maxQty)));
                    }}
                    className="w-16 h-10 border border-gray-300 rounded text-center font-medium text-gray-900 focus:outline-none focus:border-black"
                    min="1"
                    max={mainPageVariantQuantity > 0 ? mainPageVariantQuantity : 1}
                  />
                  <button 
                    onClick={() => {
                      const maxQty = mainPageVariantQuantity > 0 ? mainPageVariantQuantity : 1;
                      setQty(Math.min(qty + 1, maxQty));
                    }}
                    disabled={qty >= mainPageVariantQuantity}
                    className="w-10 h-10 border border-gray-300 rounded flex items-center justify-center hover:bg-gray-50 transition-colors text-gray-700 disabled:opacity-50 disabled:cursor-not-allowed"
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

              <div className="mt-6 flex gap-3">
                <AddToCartButton
                  productId={product.id}
                  product={{ 
                    ...product, 
                    size: selectedSize, 
                    color: selectedColor,
                    qty: qty,
                    selectedImage: selectedImage
                  }}
                  className="flex-1 bg-white border-2 border-black hover:bg-gray-50 text-black py-3 rounded-lg transition-all font-medium disabled:bg-gray-100 disabled:border-gray-300 disabled:text-gray-400 disabled:cursor-not-allowed"
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
                  className="flex-1 bg-black hover:bg-gray-900 text-white py-3 rounded-lg transition-all font-medium disabled:bg-gray-300 disabled:cursor-not-allowed"
                  label="Buy Now"
                  buyNow={true}
                  disabled={!selectedSize || !selectedColor || mainPageVariantQuantity === 0}
                />
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
                          {product.sizes && product.sizes.length > 0 && (
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
                                {product.sizes.map((s: string) => {
                                  const map: Record<string, number> = { XS: 6, S: 7, M: 8, L: 9, XL: 10, XXL: 11 };
                                  const numeric = /^\d+(?:\.\d+)?$/.test(String(s)) ? String(s) : (map[s] ? String(map[s]) : String(s));
                                  
                                  // Check if this size is available for the selected color
                                  const isAvailable = modalSelectedColor 
                                    ? product.variants?.some((v: any) => 
                                        String(v.size) === String(numeric) && 
                                        String(v.color).toLowerCase() === String(modalSelectedColor).toLowerCase() &&
                                        v.quantity > 0
                                      )
                                    : true;

                                  return (
                                    <button
                                      key={s}
                                      onClick={() => setModalSelectedSize(numeric)}
                                      disabled={!isAvailable}
                                      className={`px-3 py-2.5 border-2 rounded-lg text-sm font-medium transition-all ${
                                        String(modalSelectedSize) === String(numeric)
                                          ? 'border-black bg-black text-white'
                                          : isAvailable
                                          ? 'border-gray-200 bg-white text-gray-700 hover:border-gray-400'
                                          : 'border-gray-200 bg-gray-100 text-gray-400 cursor-not-allowed line-through'
                                      }`}
                                    >
                                      {numeric}
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
                                className="w-10 h-10 border border-gray-300 rounded flex items-center justify-center hover:bg-gray-50 transition-colors text-gray-700"
                              >
                                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M20 12H4" />
                                </svg>
                              </button>
                              <input
                                type="number"
                                value={modalQty}
                                onChange={(e) => {
                                  const value = parseInt(e.target.value) || 1;
                                  const maxQty = variantQuantity > 0 ? variantQuantity : 1;
                                  setModalQty(Math.max(1, Math.min(value, maxQty)));
                                }}
                                className="w-16 h-10 border border-gray-300 rounded text-center font-medium text-gray-900 focus:outline-none focus:border-black"
                                min="1"
                                max={variantQuantity > 0 ? variantQuantity : 1}
                              />
                              <button
                                onClick={() => {
                                  const maxQty = variantQuantity > 0 ? variantQuantity : 1;
                                  setModalQty(Math.min(modalQty + 1, maxQty));
                                }}
                                disabled={modalQty >= variantQuantity}
                                className="w-10 h-10 border border-gray-300 rounded flex items-center justify-center hover:bg-gray-50 transition-colors text-gray-700 disabled:opacity-50 disabled:cursor-not-allowed"
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
                            className="w-full bg-black hover:bg-gray-900 text-white py-3 rounded-lg transition-all font-medium disabled:bg-gray-300 disabled:cursor-not-allowed"
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

          {/* Reviews and Ratings Section - Verified Buyer System */}
          <div className="mt-16">
            <div className="border-t pt-12">
              <div className="flex items-center justify-between mb-8">
                <div>
                  <h2 className="text-2xl font-bold text-black mb-2">Customer Reviews</h2>
                  <div className="flex items-center gap-3">
                    <div className="flex items-center gap-2">
                      <span className="text-3xl font-bold text-black">{reviewStats.average_rating.toFixed(1)}</span>
                      <span className="text-yellow-400">⭐</span>
                    </div>
                    <span className="text-gray-600">({reviewStats.total_reviews} {reviewStats.total_reviews === 1 ? 'review' : 'reviews'})</span>
                  </div>
                </div>
                <div className="flex gap-1">
                  {renderStars(reviewStats.average_rating)}
                </div>
              </div>

              {/* Write a Review Section - Verified Buyer Only */}
              <div className="bg-gray-50 rounded-xl p-6 mb-8">
                {!isAuthenticated ? (
                  <div className="text-center py-8">
                    <svg className="mx-auto h-12 w-12 text-gray-400 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                    </svg>
                    <p className="text-gray-700 font-medium mb-2">Please log in to write a review</p>
                    <p className="text-sm text-gray-500">Only verified buyers can review this product</p>
                  </div>
                ) : userExistingReview ? (
                  <div className="text-center py-8">
                    <svg className="mx-auto h-12 w-12 text-green-500 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <p className="text-gray-700 font-medium mb-2">You have already reviewed this product</p>
                    <button
                      onClick={() => setShowMyReview(!showMyReview)}
                      className="text-sm text-black underline hover:text-gray-700 mt-2"
                    >
                      {showMyReview ? 'Hide My Review' : 'View My Review'}
                    </button>
                    {showMyReview && userExistingReview && (
                      <div className="mt-4 p-4 bg-white rounded-lg border border-gray-200 text-left">
                        <div className="flex items-center gap-2 mb-2">
                          {renderStars(userExistingReview.rating)}
                          <span className="text-sm text-gray-500">{new Date(userExistingReview.created_at).toLocaleDateString()}</span>
                        </div>
                        <p className="text-gray-700">{userExistingReview.comment}</p>
                      </div>
                    )}
                  </div>
                ) : !canReview ? (
                  <div className="text-center py-8">
                    <svg className="mx-auto h-12 w-12 text-yellow-500 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <p className="text-gray-700 font-medium mb-1">{reviewEligibility?.message}</p>
                    {reviewEligibility?.reason === 'pending_delivery' && (
                      <p className="text-sm text-gray-500">Order status: <span className="font-medium capitalize">{reviewEligibility.order_status}</span></p>
                    )}
                  </div>
                ) : (
                  <>
                    <div className="flex items-center gap-2 mb-4">
                      <h3 className="text-lg font-semibold text-black">Write a Review</h3>
                      <span className="inline-flex items-center gap-1 px-2 py-1 bg-green-100 text-green-700 text-xs font-medium rounded">
                        <svg className="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                          <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
                        </svg>
                        Verified Purchase
                      </span>
                    </div>
                    
                    <div className="mb-4">
                      <label className="block text-sm font-medium text-black mb-2">Your Rating</label>
                      {renderStars(userRating, true, setUserRating)}
                    </div>

                    <div className="mb-4">
                      <label className="block text-sm font-medium text-black mb-2">Your Review</label>
                      <textarea
                        value={newComment}
                        onChange={(e) => setNewComment(e.target.value)}
                        placeholder="Share your experience with this product..."
                        className="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-black text-black resize-none"
                        rows={4}
                        minLength={10}
                        maxLength={2000}
                      />
                      <p className="text-xs text-gray-500 mt-1">{newComment.length}/2000 characters (minimum 10)</p>
                    </div>

                    <div className="mb-6">
                      <label className="block text-sm font-medium text-black mb-2">Photos (Optional, max 5)</label>
                      <div className="grid grid-cols-4 gap-4">
                        {imageUploadGroups.map((group) => (
                          <div key={group.id} className="relative group">
                            {group.preview ? (
                              <div className="relative">
                                <img
                                  src={group.preview}
                                  alt="Review photo"
                                  className="w-full h-32 object-cover rounded-lg border border-gray-200"
                                />
                                <div className="absolute inset-0 bg-black/50 opacity-0 group-hover:opacity-100 transition-opacity rounded-lg flex items-center justify-center gap-2">
                                  {imageUploadGroups.length < 5 && (
                                    <button
                                      onClick={addImageUploadBox}
                                      className="w-10 h-10 bg-black hover:bg-gray-800 rounded-full flex items-center justify-center text-white transition-colors"
                                      type="button"
                                      title="Add more photos"
                                    >
                                      <span className="text-xl">+</span>
                                    </button>
                                  )}
                                  <button
                                    onClick={() => removeImageBox(group.id)}
                                    className="w-10 h-10 bg-red-600 hover:bg-red-700 rounded-full flex items-center justify-center text-white transition-colors"
                                    type="button"
                                    title="Remove photo"
                                  >
                                    <span className="text-xl">×</span>
                                  </button>
                                </div>
                              </div>
                            ) : (
                              <label className="w-full h-32 border-2 border-dashed border-gray-300 rounded-lg flex flex-col items-center justify-center cursor-pointer hover:border-gray-400 transition-colors bg-gray-50/50">
                                <input
                                  type="file"
                                  accept="image/jpeg,image/jpg,image/png"
                                  onChange={(e) => handleImageUpload(group.id, e)}
                                  className="hidden"
                                  aria-label="Upload review photo"
                                />
                                <svg xmlns="http://www.w3.org/2000/svg" className="w-6 h-6 text-gray-400 mb-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                  <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                                  <polyline points="17 8 12 3 7 8" />
                                  <line x1="12" y1="3" x2="12" y2="15" />
                                </svg>
                                <span className="text-xs text-gray-500 mt-1">Click to upload</span>
                              </label>
                            )}
                          </div>
                        ))}
                      </div>
                    </div>

                    <button
                      onClick={handleSubmitReview}
                      disabled={isSubmittingReview || !newComment.trim() || newComment.length < 10 || userRating === 0}
                      className="bg-black text-white px-6 py-3 rounded-lg hover:bg-gray-800 transition-colors disabled:bg-gray-400 disabled:cursor-not-allowed"
                      type="button"
                    >
                      {isSubmittingReview ? 'Submitting...' : 'Submit Review'}
                    </button>
                  </>
                )}
              </div>

              {/* Reviews List */}
              <div className="space-y-6">
                {reviews.length > 0 ? (
                  reviews.map((review: any) => (
                    <div key={review.id} className="bg-white border border-gray-200 rounded-xl p-6">
                      <div className="flex items-start gap-4">
                        <div className="w-12 h-12 bg-gradient-to-br from-gray-700 to-gray-900 rounded-full flex items-center justify-center text-white font-bold text-lg flex-shrink-0">
                          {review.user_name?.charAt(0).toUpperCase() || 'U'}
                        </div>
                        <div className="flex-1">
                          <div className="flex items-center gap-3 mb-2">
                            <h4 className="font-semibold text-black">{review.user_name}</h4>
                            {review.is_verified_purchase && (
                              <span className="inline-flex items-center gap-1 px-2 py-0.5 bg-green-100 text-green-700 text-xs font-medium rounded">
                                <svg className="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                                  <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
                                </svg>
                                Verified Purchase
                              </span>
                            )}
                          </div>
                          <div className="flex items-center gap-3 mb-3">
                            {renderStars(review.rating)}
                            <span className="text-sm text-gray-500">{review.formatted_date}</span>
                          </div>
                          <p className="text-gray-700 leading-relaxed mb-3">{review.comment}</p>
                          {review.images && review.images.length > 0 && (
                            <div className="flex gap-2 flex-wrap">
                              {review.images.map((img: string, idx: number) => (
                                <img
                                  key={idx}
                                  src={img}
                                  alt={`Review photo ${idx + 1}`}
                                  className="w-24 h-24 object-cover rounded-lg border border-gray-200 cursor-pointer hover:opacity-80 transition-opacity"
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
                  <div className="text-center py-16 bg-gray-50 rounded-xl">
                    <svg className="mx-auto h-16 w-16 text-gray-300 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z" />
                    </svg>
                    <h3 className="text-lg font-semibold text-gray-700 mb-2">No Reviews Yet</h3>
                    <p className="text-gray-500 text-sm">Be the first verified buyer to review this product!</p>
                  </div>
                )}
              </div>
            </div>
          </div>
        </div>
      </div>

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
