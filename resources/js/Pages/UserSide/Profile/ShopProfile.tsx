import React, { useEffect, useRef, useState } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import Navigation from '../Shared/Navigation';
import ReportShopModal from '../../../components/ReportShopModal';

interface Product {
  id: number;
  name: string;
  slug: string;
  price: number;
  compare_at_price?: number;
  brand?: string;
  category: string;
  stock_quantity: number;
  main_image: string;
  hover_image?: string | null;
  gallery_images?: string[];
  description?: string;
}

interface RepairService {
  id: number;
  title: string;
  price: string;
  description: string;
  category: string;
  duration: string;
}

interface RepairPackage {
  id: number;
  name: string;
  description?: string | null;
  package_price: number;
  service_count: number;
  services_total_price: number;
  savings_amount: number;
}

interface Shop {
  id: number;
  name: string;
  business_type?: string;
  has_active_premium?: boolean;
  cover_image: string;
  profile_photo?: string | null;
  description: string;
  address: string;
  phone: string;
  email: string;
  rating: number;
  total_reviews: number;
  established_year?: number | null;
  country?: string;
  postal_code?: string;
  tax_id?: string;
  monday_open?: string;
  monday_close?: string;
  tuesday_open?: string;
  tuesday_close?: string;
  wednesday_open?: string;
  wednesday_close?: string;
  thursday_open?: string;
  thursday_close?: string;
  friday_open?: string;
  friday_close?: string;
  saturday_open?: string;
  saturday_close?: string;
  sunday_open?: string;
  sunday_close?: string;
}

interface Props {
  shop: Shop;
  products: Product[];
  repairServices?: RepairService[];
  repairPackages?: RepairPackage[];
}

const ShopProfile: React.FC<Props> = ({ shop, products, repairServices = [], repairPackages = [] }) => {
  const { auth } = usePage().props as any;
  const isAuthenticated = !!auth?.user;
  const rawBusinessType = (shop.business_type || '').toLowerCase().trim();
  const hasRepairSignal = rawBusinessType.includes('repair') || rawBusinessType.includes('service');
  const hasRetailSignal = rawBusinessType.includes('retail') || rawBusinessType.includes('product') || rawBusinessType.includes('shoe');
  const inferredBusinessType = rawBusinessType === 'both' || (hasRepairSignal && hasRetailSignal)
    ? 'both'
    : hasRepairSignal
      ? 'repair'
      : (repairServices.length > 0 || repairPackages.length > 0)
        ? 'repair'
        : 'retail';
  const businessTypeBadge = inferredBusinessType === 'both'
    ? {
        label: 'Retail & Repair',
        className: 'bg-slate-900/5 text-slate-800 border-slate-300',
      }
    : inferredBusinessType === 'repair'
      ? {
          label: 'Repair',
          className: 'bg-slate-50 text-slate-700 border-slate-300',
        }
      : {
          label: 'Retail',
          className: 'bg-white text-slate-700 border-slate-300',
        };
  const heroBadgeClassName = 'border-white/85 bg-black/35 text-white';
  const isRepairOnlyShop = inferredBusinessType === 'repair';
  const isRetailAndRepairShop = inferredBusinessType === 'both';
  const profileSubtitle = inferredBusinessType === 'both'
    ? 'Premium footwear products and services'
    : inferredBusinessType === 'repair'
      ? 'Premium services'
      : 'Premium footwear products';
  const displaySubtitle = (shop.description || '').trim() || profileSubtitle;
  const totalReviews = Math.max(Number(shop.total_reviews) || 0, 0);
  const parsedRating = Number(shop.rating);
  const normalizedRating = Number.isFinite(parsedRating) ? Math.min(Math.max(parsedRating, 0), 5) : 0;
  const displayedRating = totalReviews > 0 ? normalizedRating : 0;
  const displayedRatingText = Number.isInteger(displayedRating)
    ? displayedRating.toString()
    : displayedRating.toFixed(1);
  const establishedYearText = shop.established_year ? `Est. ${shop.established_year}` : 'Est. --';
  const reviewLabel = `${totalReviews} review${totalReviews === 1 ? '' : 's'}`;
  const categories = isRepairOnlyShop
    ? ['Services']
    : isRetailAndRepairShop
      ? ['Shoes', 'Men', 'Women', 'Kids', 'Sports', 'Services']
      : ['Shoes', 'Men', 'Women', 'Kids', 'Sports'];
  const showVirtualShowroom = !isRepairOnlyShop && Boolean(shop.has_active_premium);
  const [isFollowing, setIsFollowing] = useState<boolean>(false);
  const [showReportModal, setShowReportModal] = useState(false);
  const [isActionMenuOpen, setIsActionMenuOpen] = useState(false);
  const [shopSearchQuery, setShopSearchQuery] = useState('');
  const [shopSearchFocused, setShopSearchFocused] = useState(false);
  const [shopSuggestionProducts, setShopSuggestionProducts] = useState<any[]>([]);
  const [shopSuggestionShops, setShopSuggestionShops] = useState<any[]>([]);
  const [isShopSearchingSuggestions, setIsShopSearchingSuggestions] = useState(false);
  const [selectedCategory, setSelectedCategory] = useState<string>(isRepairOnlyShop ? 'Services' : 'Shoes');
  const [activeImageIndexes, setActiveImageIndexes] = useState<Record<number, number>>({});
  const hoverTimersRef = useRef<Record<number, number>>({});
  const actionMenuRef = useRef<HTMLDivElement>(null);
  const shopSearchContainerRef = useRef<HTMLDivElement | null>(null);
  const shopSearchAbortRef = useRef<AbortController | null>(null);
  const isServicesTab = selectedCategory === 'Services';
  const retailCategoriesForSections = categories.filter((category) => category !== 'Shoes' && category !== 'Services');
  const defaultCoverColorClassName = 'bg-[#16233b]';

  const normalizeMediaPath = (value?: string | null) => (value || '').trim().toLowerCase();

  const hasCustomCoverImage = (value?: string | null) => {
    const normalized = normalizeMediaPath(value);
    if (!normalized || normalized === 'null' || normalized === 'undefined') {
      return false;
    }

    return !normalized.includes('/images/shop/default-cover.jpg') && !normalized.includes('default-cover');
  };

  const hasCustomProfilePhoto = (value?: string | null) => {
    const normalized = normalizeMediaPath(value);
    if (!normalized || normalized === 'null' || normalized === 'undefined') {
      return false;
    }

    return !normalized.includes('/images/shop/shop-icon.png') && !normalized.includes('shop-icon');
  };

  const [hasValidCoverImage, setHasValidCoverImage] = useState<boolean>(hasCustomCoverImage(shop.cover_image));
  const [hasValidProfilePhoto, setHasValidProfilePhoto] = useState<boolean>(hasCustomProfilePhoto(shop.profile_photo));

  const categoryKeywordMap: Record<string, string[]> = {
    men: ['men', "men's", 'male', 'man', 'mens'],
    women: ['women', "women's", 'female', 'lady', 'ladies', 'womens'],
    kids: ['kids', 'kid', 'children', 'child', 'youth'],
    sports: ['sports', 'sport', 'athletic', 'athletics'],
    services: ['services', 'service', 'repair'],
    shoes: ['shoes', 'shoe', 'footwear'],
  };

  const escapeRegex = (value: string) => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

  const getInitials = (value: string) => {
    const initials = (value || '')
      .trim()
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((part) => part.charAt(0).toUpperCase())
      .join('');

    return initials || 'S';
  };

  const shopInitials = getInitials(shop.name || 'Shop');

  const matchesCategory = (rawCategory: string, selected: string) => {
    const normalizedSelected = selected.toLowerCase();
    const normalizedCategory = (rawCategory || '').toLowerCase();
    const categoryParts = normalizedCategory
      .split(',')
      .map((item) => item.trim())
      .filter(Boolean);

    const keywords = categoryKeywordMap[normalizedSelected] || [normalizedSelected];

    return categoryParts.some((item) =>
      keywords.some((keyword) => new RegExp(`\\b${escapeRegex(keyword)}\\b`, 'i').test(item))
    );
  };

  useEffect(() => {
    if (!categories.includes(selectedCategory)) {
      setSelectedCategory(categories[0]);
    }
  }, [categories, selectedCategory]);

  useEffect(() => {
    setHasValidCoverImage(hasCustomCoverImage(shop.cover_image));
  }, [shop.cover_image]);

  useEffect(() => {
    setHasValidProfilePhoto(hasCustomProfilePhoto(shop.profile_photo));
  }, [shop.profile_photo]);

  const filteredProducts = products.filter((product) => {
    // First filter by category
    const matchesCategory_ = selectedCategory === 'Shoes' 
      ? true 
      : matchesCategory(product.category || '', selectedCategory);
    
    if (!matchesCategory_) return false;

    // Then filter by search query
    if (!shopSearchQuery.trim()) return true;

    const query = shopSearchQuery.trim().toLowerCase();
    const productName = (product.name || '').toLowerCase();
    const productBrand = (product.brand || '').toLowerCase();
    
    return productName.includes(query) || productBrand.includes(query);
  });

  const filteredRepairServices = repairServices.filter((service) => {
    if (!shopSearchQuery.trim()) return true;

    const query = shopSearchQuery.trim().toLowerCase();
    const serviceTitle = (service.title || '').toLowerCase();
    const serviceDesc = (service.description || '').toLowerCase();
    
    return serviceTitle.includes(query) || serviceDesc.includes(query);
  });

  const filteredRepairPackages = repairPackages.filter((pkg) => {
    if (!shopSearchQuery.trim()) return true;

    const query = shopSearchQuery.trim().toLowerCase();
    const packageName = (pkg.name || '').toLowerCase();
    const packageDesc = (pkg.description || '').toLowerCase();
    
    return packageName.includes(query) || packageDesc.includes(query);
  });

  const getProductsByCategory = (category: string) => {
    const categoryFiltered = products.filter((product) => {
      return matchesCategory(product.category || '', category);
    });

    // Also apply search filter
    if (!shopSearchQuery.trim()) return categoryFiltered;

    const query = shopSearchQuery.trim().toLowerCase();
    return categoryFiltered.filter((product) => {
      const productName = (product.name || '').toLowerCase();
      const productBrand = (product.brand || '').toLowerCase();
      return productName.includes(query) || productBrand.includes(query);
    });
  };

  useEffect(() => {
    return () => {
      Object.values(hoverTimersRef.current).forEach((timerId) => window.clearInterval(timerId));
      hoverTimersRef.current = {};
    };
  }, []);

  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (actionMenuRef.current && !actionMenuRef.current.contains(event.target as Node)) {
        setIsActionMenuOpen(false);
      }
    };

    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  // Shop search suggestions with debounce
  useEffect(() => {
    const query = shopSearchQuery.trim();
    if (query.length < 2) {
      setShopSuggestionProducts([]);
      setShopSuggestionShops([]);
      setIsShopSearchingSuggestions(false);
      return;
    }

    const timeoutId = window.setTimeout(async () => {
      if (shopSearchAbortRef.current) {
        shopSearchAbortRef.current.abort();
      }

      const controller = new AbortController();
      shopSearchAbortRef.current = controller;
      setIsShopSearchingSuggestions(true);

      try {
        const response = await fetch(
          `/api/search/suggestions?query=${encodeURIComponent(query)}`,
          {
            headers: {
              Accept: 'application/json',
            },
            signal: controller.signal,
          }
        );

        if (!response.ok) {
          throw new Error('Search request failed');
        }

        const data = await response.json();
        setShopSuggestionProducts(Array.isArray(data.products) ? data.products : []);
        setShopSuggestionShops(Array.isArray(data.shops) ? data.shops : []);
      } catch (error: any) {
        if (error?.name !== 'AbortError') {
          setShopSuggestionProducts([]);
          setShopSuggestionShops([]);
        }
      } finally {
        setIsShopSearchingSuggestions(false);
      }
    }, 220);

    return () => window.clearTimeout(timeoutId);
  }, [shopSearchQuery]);

  // Handle outside clicks to close shop search suggestions
  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (
        shopSearchContainerRef.current &&
        !shopSearchContainerRef.current.contains(event.target as Node)
      ) {
        setShopSearchFocused(false);
      }
    };

    if (shopSearchFocused) {
      document.addEventListener('mousedown', handleClickOutside);
      return () => {
        document.removeEventListener('mousedown', handleClickOutside);
      };
    }
  }, [shopSearchFocused]);

  const getProductImages = (product: Product) => {
    const images = [product.main_image, product.hover_image, ...(product.gallery_images ?? [])].filter(Boolean) as string[];
    return Array.from(new Set(images));
  };

  const startImageCycle = (product: Product) => {
    const images = getProductImages(product);
    if (images.length <= 1) return;

    setActiveImageIndexes((prev) => ({ ...prev, [product.id]: 1 }));

    if (hoverTimersRef.current[product.id]) {
      window.clearInterval(hoverTimersRef.current[product.id]);
    }

    hoverTimersRef.current[product.id] = window.setInterval(() => {
      setActiveImageIndexes((prev) => {
        const currentIndex = prev[product.id] ?? 1;
        return {
          ...prev,
          [product.id]: (currentIndex + 1) % images.length,
        };
      });
    }, 800);
  };

  const stopImageCycle = (productId: number) => {
    if (hoverTimersRef.current[productId]) {
      window.clearInterval(hoverTimersRef.current[productId]);
      delete hoverTimersRef.current[productId];
    }

    setActiveImageIndexes((prev) => ({ ...prev, [productId]: 0 }));
  };

  const handleBackNavigation = () => {
    if (window.history.length > 1) {
      window.history.back();
      return;
    }

    window.location.href = '/';
  };

  return (
    <>
    <div className="min-h-dvh flex flex-col bg-white">
      <Head title={shop.name} />
      <Navigation />

      <main className="xl:hidden flex-1 pt-16 bg-gray-50">
        <section className={`relative h-56 overflow-hidden ${hasValidCoverImage ? 'bg-gray-300' : defaultCoverColorClassName}`}>
          {hasValidCoverImage ? (
            <img
              src={shop.cover_image}
              alt="Shop Cover"
              className="h-full w-full object-cover"
              onError={() => setHasValidCoverImage(false)}
            />
          ) : (
            <div className={`h-full w-full ${defaultCoverColorClassName}`} aria-hidden="true" />
          )}
          <div className="absolute inset-0 bg-linear-to-b from-black/35 via-black/20 to-black/55" />

          <div className="absolute left-0 right-0 top-0 px-3 pt-3">
            <div className="flex items-center gap-2">
              <button
                type="button"
                onClick={handleBackNavigation}
                className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-white/90 text-gray-700 shadow-sm ring-1 ring-black/5"
                aria-label="Go back"
              >
                <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                  <path strokeLinecap="round" strokeLinejoin="round" d="m7 7-5 5 5 5" />
                  <path strokeLinecap="round" strokeLinejoin="round" d="M2 12h20" />
                </svg>
              </button>

              <div ref={shopSearchContainerRef} className="relative min-w-0 flex-1">
                <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 z-10">
                  <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-4.35-4.35m1.6-5.4a7 7 0 11-14 0 7 7 0 0114 0z" />
                  </svg>
                </span>
                <input
                  type="text"
                  value={shopSearchQuery}
                  onChange={(e) => setShopSearchQuery(e.target.value)}
                  onFocus={() => setShopSearchFocused(true)}
                  placeholder={`Search in ${shop.name}`}
                  className="h-10 w-full rounded-full border border-gray-300/70 bg-white/70 py-2.5 pl-10 pr-4 text-sm text-gray-900 shadow-sm backdrop-blur-md placeholder:text-gray-500 transition-all duration-300 focus:border-gray-400/70 focus:bg-white/90 focus:outline-none focus:ring-2 focus:ring-gray-200"
                  aria-label="Search in shop"
                />

                {/* Shop search suggestions dropdown */}
                {shopSearchFocused && shopSearchQuery.trim().length >= 2 && (
                  <div className="absolute right-0 top-full z-50 mt-1 w-full rounded-2xl border border-gray-200 bg-white shadow-lg">
                    <div className="border-b border-gray-200 px-5 py-3">
                      <p className="text-xs font-semibold uppercase tracking-wider text-gray-500">Suggestions</p>
                    </div>

                    <div className="max-h-80 overflow-y-auto">
                      {/* Products section */}
                      {shopSuggestionProducts.length > 0 && (
                        <div>
                          <div className="border-b border-gray-100 px-5 py-2">
                            <p className="text-xs font-medium text-gray-600">Products</p>
                          </div>
                          {shopSuggestionProducts.slice(0, 5).map((suggestion) => (
                            <Link
                              key={suggestion.id}
                              href={suggestion.url}
                              className="flex items-center gap-3 border-b border-gray-50 px-5 py-3 hover:bg-gray-50 transition-colors"
                            >
                              {suggestion.main_image ? (
                                <img
                                  src={suggestion.main_image}
                                  alt={suggestion.name}
                                  className="h-8 w-8 rounded-lg object-cover"
                                />
                              ) : (
                                <div className="h-8 w-8 rounded-lg bg-gray-200" />
                              )}
                              <div className="min-w-0 flex-1">
                                <p className="text-sm text-gray-800 truncate">{suggestion.name}</p>
                                <p className="text-xs text-gray-500 truncate">{suggestion.shop_name}</p>
                              </div>
                            </Link>
                          ))}
                        </div>
                      )}

                      {/* Shop Profiles section */}
                      {shopSuggestionShops.length > 0 && (
                        <div>
                          <div className="border-b border-gray-100 px-5 py-2">
                            <p className="text-xs font-medium text-gray-600">Shop Profiles</p>
                          </div>
                          {shopSuggestionShops.slice(0, 4).map((suggestion) => (
                            <div
                              key={suggestion.id}
                              className="flex items-center justify-between gap-3 border-b border-gray-50 px-5 py-3 hover:bg-gray-50 transition-colors"
                            >
                              <div className="flex items-center gap-3 min-w-0 flex-1">
                                {suggestion.image ? (
                                  <img
                                    src={suggestion.image}
                                    alt={suggestion.name}
                                    className="h-8 w-8 rounded-full object-cover shrink-0"
                                  />
                                ) : (
                                  <div className="h-8 w-8 rounded-full bg-gray-200 shrink-0" />
                                )}
                                <div className="min-w-0 flex-1">
                                  <p className="text-sm text-gray-800 truncate">{suggestion.name}</p>
                                  <p className="text-xs text-gray-500 truncate">{suggestion.location}</p>
                                </div>
                              </div>
                              <Link
                                href={suggestion.url}
                                className="ml-2 whitespace-nowrap text-xs font-semibold text-[#16233b] hover:text-[#1a2942] transition-colors"
                              >
                                PROFILE
                              </Link>
                            </div>
                          ))}
                        </div>
                      )}

                      {/* No results state */}
                      {!isShopSearchingSuggestions &&
                        shopSuggestionProducts.length === 0 &&
                        shopSuggestionShops.length === 0 && (
                          <div className="px-5 py-6 text-center">
                            <p className="text-sm text-gray-500">No results found</p>
                          </div>
                        )}

                      {/* Loading state */}
                      {isShopSearchingSuggestions && (
                        <div className="px-5 py-6 text-center">
                          <p className="text-sm text-gray-500">Searching...</p>
                        </div>
                      )}
                    </div>
                  </div>
                )}
              </div>

              <div ref={actionMenuRef} className="relative shrink-0">
                <button
                  type="button"
                  onClick={() => setIsActionMenuOpen((prev) => !prev)}
                  className="inline-flex h-9 w-9 items-center justify-center rounded-full bg-white text-gray-700 shadow-lg ring-1 ring-black/10"
                  aria-label="Open menu"
                  title="More actions"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 8a1.75 1.75 0 1 0 0-3.5A1.75 1.75 0 0 0 12 8Zm0 5.75a1.75 1.75 0 1 0 0-3.5 1.75 1.75 0 0 0 0 3.5ZM13.75 17.5a1.75 1.75 0 1 1-3.5 0 1.75 1.75 0 0 1 3.5 0Z" />
                  </svg>
                </button>

                {isActionMenuOpen && (
                  <div className="absolute right-0 mt-2 w-44 overflow-hidden rounded-xl border border-gray-200 bg-white py-1 shadow-xl ring-1 ring-black/5">
                    <button
                      type="button"
                      onClick={() => {
                        setIsActionMenuOpen(false);
                        setShowReportModal(true);
                      }}
                      className="flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm font-medium text-gray-700 transition-colors hover:bg-red-50 hover:text-red-600"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v4m0 4h.01" />
                        <path strokeLinecap="round" strokeLinejoin="round" d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z" />
                      </svg>
                      Report Shop
                    </button>
                  </div>
                )}
              </div>
            </div>
          </div>

          <div className="absolute bottom-0 left-0 right-0 px-4 pb-4">
            <div className="flex items-end justify-between gap-3">
              <div className="flex min-w-0 items-center gap-3 text-white">
                <div className="h-16 w-16 shrink-0 overflow-hidden rounded-full border-2 border-white/80 bg-white">
                  {hasValidProfilePhoto ? (
                    <img
                      src={shop.profile_photo || ''}
                      alt={shop.name}
                      className="h-full w-full object-cover"
                      onError={() => setHasValidProfilePhoto(false)}
                    />
                  ) : (
                    <div className={`flex h-full w-full items-center justify-center ${defaultCoverColorClassName} text-xl font-extrabold uppercase text-white`}>
                      {shopInitials}
                    </div>
                  )}
                </div>
                <div className="min-w-0">
                  <p className="truncate text-xl font-semibold leading-tight">{shop.name}</p>
                  <span
                    className={`mt-1 inline-flex items-center rounded-full border px-2.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide ${heroBadgeClassName}`}
                    title="Shop account type"
                  >
                    {businessTypeBadge.label}
                  </span>
                  <div className="mt-1 flex items-center gap-2 text-sm text-white/90">
                    <span className="text-yellow-300">★ {displayedRatingText}</span>
                    <span>|</span>
                    <span>{reviewLabel}</span>
                  </div>
                </div>
              </div>
              <div className="flex shrink-0 flex-col gap-2">
                <button
                  type="button"
                  onClick={() => setIsFollowing((prev) => !prev)}
                  className={`rounded-lg border px-3 py-1.5 text-sm font-medium backdrop-blur-sm transition-colors ${
                    isFollowing
                      ? 'border-white bg-white text-black'
                      : 'border-white/80 bg-transparent text-white hover:bg-white/10'
                  }`}
                >
                  {isFollowing ? 'Following' : 'Follow'}
                </button>
                <Link
                  href={`/message/${shop.id}`}
                  className="rounded-lg border border-white/80 bg-transparent px-3 py-1.5 text-center text-sm font-medium text-white backdrop-blur-sm transition-colors hover:bg-white/10"
                >
                  Chat
                </Link>
              </div>
            </div>
          </div>
        </section>

        <section className="sticky top-16 z-20 border-b border-gray-200 bg-white">
          <div className="flex items-center gap-6 overflow-x-auto px-4 py-3">
            {categories.map((category) => (
              <button
                key={category}
                type="button"
                onClick={() => setSelectedCategory(category)}
                className={`shrink-0 border-b-2 pb-2 text-base transition-colors ${
                  selectedCategory === category
                    ? 'border-black font-semibold text-black'
                    : 'border-transparent text-gray-500'
                }`}
              >
                {category}
              </button>
            ))}
            {showVirtualShowroom && (
              <Link
                href={`/shop-profile/${shop.id}/virtual-showroom`}
                className="shrink-0 border-b-2 border-transparent pb-2 text-base text-gray-500"
              >
                Showroom
              </Link>
            )}
          </div>
        </section>

        <section className="space-y-4 px-3 py-4">
          <div className="rounded-2xl border border-gray-200 bg-white p-4">
            <div className="grid grid-cols-1 gap-5">
              <div>
                <div className="flex items-center justify-between gap-3">
                  <p className="text-xs uppercase tracking-wide text-gray-400">Location</p>
                  <span className="text-xs text-gray-500">{establishedYearText}</span>
                </div>
                <p className="mt-1 text-sm font-medium text-black">{shop.address}</p>
                {shop.country && (
                  <p className="text-xs text-gray-600">{shop.country}{shop.postal_code ? ` ${shop.postal_code}` : ''}</p>
                )}
              </div>
              <div>
                <p className="text-xs uppercase tracking-wide text-gray-400">Phone</p>
                <p className="mt-1 text-sm font-medium text-black">{shop.phone}</p>
              </div>
              <div>
                <p className="text-xs uppercase tracking-wide text-gray-400">Email</p>
                <p className="mt-1 break-all text-sm font-medium text-black">{shop.email}</p>
              </div>
            </div>
          </div>

          {isServicesTab ? (
            <div className="space-y-4">
              {filteredRepairPackages.length > 0 && (
                <section className="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm">
                  <div className="mb-4 flex items-center justify-between">
                    <h2 className="text-xl font-extrabold tracking-tight text-slate-900">Packages</h2>
                    <span className="rounded-full bg-emerald-50 px-2.5 py-1 text-[11px] font-semibold text-emerald-700">Best Value</span>
                  </div>
                  <div className="-mx-1 flex snap-x snap-mandatory gap-3.5 overflow-x-auto px-1 pb-1">
                    {filteredRepairPackages.slice(0, 4).map((pkg) => (
                      <div key={pkg.id} className="w-[82%] shrink-0 snap-start rounded-2xl border border-slate-200 bg-slate-50/70 p-4 sm:w-85">
                        <p className="text-lg font-extrabold leading-tight text-slate-900">{pkg.name}</p>
                        {pkg.description && <p className="mt-1.5 line-clamp-2 text-sm leading-relaxed text-slate-600">{pkg.description}</p>}
                        <div className="mt-4 flex items-end justify-between gap-3 border-t border-slate-200/80 pt-3">
                          <span className="text-sm font-medium text-slate-500">{pkg.service_count} services</span>
                          <span className="text-3xl font-black leading-none text-slate-900">₱{pkg.package_price.toLocaleString()}</span>
                        </div>
                      </div>
                    ))}
                  </div>
                </section>
              )}

              <section className="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm">
                <div className="mb-4 flex items-center justify-between">
                  <h2 className="text-xl font-extrabold tracking-tight text-slate-900">Services</h2>
                  {filteredRepairServices.length > 0 && (
                    <Link href={`/repair-shop/${shop.id}`} className="text-sm font-semibold text-slate-900 underline-offset-4 hover:underline">
                      See More
                    </Link>
                  )}
                </div>
                {filteredRepairServices.length > 0 ? (
                  <div className="-mx-1 flex snap-x snap-mandatory gap-3.5 overflow-x-auto px-1 pb-1">
                    {filteredRepairServices.slice(0, 6).map((service) => (
                      <div key={service.id} className="w-[82%] shrink-0 snap-start rounded-2xl border border-slate-200 bg-slate-50/70 p-4 sm:w-85">
                        <p className="line-clamp-1 text-lg font-extrabold leading-tight text-slate-900">{service.title}</p>
                        <p className="mt-1.5 line-clamp-2 text-sm leading-relaxed text-slate-600">{service.description}</p>
                        <div className="mt-4 flex items-end justify-between border-t border-slate-200/80 pt-3">
                          <span className="text-3xl font-black leading-none text-slate-900">{service.price}</span>
                          <span className="text-xs font-medium text-slate-500">{service.duration}</span>
                        </div>
                      </div>
                    ))}
                  </div>
                ) : (
                  <p className="py-10 text-center text-sm text-gray-500">No repair services available right now</p>
                )}
              </section>

              {(filteredRepairPackages.length > 0 || filteredRepairServices.length > 0) && (
                <div className="px-1 pt-1">
                  <Link
                    href={`/repair-shop/${shop.id}`}
                    className="inline-flex w-full items-center justify-center rounded-2xl bg-slate-900 px-6 py-3 text-sm font-semibold text-white transition-colors hover:bg-black"
                  >
                    Book Repair Service
                  </Link>
                </div>
              )}
            </div>
          ) : filteredProducts.length > 0 ? (
            <div className="space-y-4">
              <section className="rounded-2xl border border-gray-200 bg-white p-4">
                <div className="mb-4 flex items-center justify-between">
                  <h2 className="text-xl font-bold text-black">Recommended For You</h2>
                  <span className="text-sm text-gray-500">See More</span>
                </div>
                <div className="flex gap-3 overflow-x-auto pb-1">
                  {filteredProducts.slice(0, 6).map((product) => {
                    const productImages = getProductImages(product);
                    const displayImage = productImages[0] || product.main_image;

                    return (
                      <Link
                        key={product.id}
                        href={`/products/${product.slug}`}
                        className="w-36 shrink-0 overflow-hidden rounded-xl border border-gray-200 bg-white"
                      >
                        <div className="relative aspect-square bg-gray-100">
                          {displayImage ? (
                            <img
                              src={displayImage}
                              alt={product.name}
                              className="h-full w-full object-cover"
                              loading="lazy"
                            />
                          ) : (
                            <div className="flex h-full w-full items-center justify-center text-xs text-gray-400">No Image</div>
                          )}
                        </div>
                        <div className="p-2.5">
                          <p className="line-clamp-2 text-sm font-medium text-black">{product.name}</p>
                          <p className="mt-1 text-lg font-bold text-black">₱{product.price.toLocaleString()}</p>
                          <p className="mt-1 text-xs text-gray-500">★ {displayedRatingText} | {Math.max(product.stock_quantity, 0)} stock</p>
                        </div>
                      </Link>
                    );
                  })}
                </div>
              </section>

              {retailCategoriesForSections.map((category) => {
                const sectionProducts = getProductsByCategory(category).slice(0, 10);

                if (sectionProducts.length === 0) {
                  return null;
                }

                return (
                  <section key={category} className="rounded-2xl border border-gray-200 bg-white p-4">
                    <div className="mb-4 flex items-center justify-between">
                      <h3 className="text-xl font-bold text-black">{category}</h3>
                      <button
                        type="button"
                        onClick={() => setSelectedCategory(category)}
                        className="text-sm font-medium text-black"
                      >
                        See More
                      </button>
                    </div>
                    <div className="flex gap-3 overflow-x-auto pb-1">
                      {sectionProducts.map((product) => {
                        const productImages = getProductImages(product);
                        const displayImage = productImages[0] || product.main_image;

                        return (
                          <Link
                            key={product.id}
                            href={`/products/${product.slug}`}
                            className="w-36 shrink-0 overflow-hidden rounded-xl border border-gray-200 bg-white"
                          >
                            <div className="aspect-square bg-gray-100">
                              {displayImage ? (
                                <img
                                  src={displayImage}
                                  alt={product.name}
                                  className="h-full w-full object-cover"
                                  loading="lazy"
                                />
                              ) : (
                                <div className="flex h-full w-full items-center justify-center text-xs text-gray-400">No Image</div>
                              )}
                            </div>
                            <div className="p-2.5">
                              <p className="line-clamp-2 text-sm font-medium text-black">{product.name}</p>
                              <p className="mt-1 text-lg font-bold text-black">₱{product.price.toLocaleString()}</p>
                            </div>
                          </Link>
                        );
                      })}
                    </div>
                  </section>
                );
              })}
            </div>
          ) : (
            <div className="rounded-2xl border border-gray-200 bg-white px-4 py-14 text-center">
              <p className="text-base text-gray-500">No products in this category</p>
            </div>
          )}
        </section>
      </main>

      <main className="hidden xl:block flex-1 pt-0">
        {/* Cover Image Section */}
        <div className={`relative h-88 xl:h-112 overflow-hidden ${hasValidCoverImage ? 'bg-gray-200' : defaultCoverColorClassName}`}>
          {hasValidCoverImage ? (
            <img
              src={shop.cover_image}
              alt="Shop Cover"
              className="w-full h-full object-cover"
              onError={() => setHasValidCoverImage(false)}
            />
          ) : (
            <div className={`h-full w-full ${defaultCoverColorClassName}`} aria-hidden="true" />
          )}

          <div ref={actionMenuRef} className="absolute right-4 top-24 z-70 xl:top-28">
              <button
                type="button"
                onClick={() => setIsActionMenuOpen((prev) => !prev)}
                className="inline-flex h-10 w-10 items-center justify-center rounded-full bg-white text-gray-700 shadow-lg ring-1 ring-black/10 transition hover:bg-white"
                aria-label="Open menu"
                title="More actions"
              >
                <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5" viewBox="0 0 24 24" fill="currentColor">
                  <path d="M12 8a1.75 1.75 0 1 0 0-3.5A1.75 1.75 0 0 0 12 8Zm0 5.75a1.75 1.75 0 1 0 0-3.5 1.75 1.75 0 0 0 0 3.5ZM13.75 17.5a1.75 1.75 0 1 1-3.5 0 1.75 1.75 0 0 1 3.5 0Z" />
                </svg>
              </button>

              {isActionMenuOpen && (
                <div className="absolute right-0 mt-2 w-48 overflow-hidden rounded-xl border border-gray-200 bg-white py-1 shadow-xl ring-1 ring-black/5">
                  <button
                    type="button"
                    onClick={() => {
                      setIsActionMenuOpen(false);
                      setShowReportModal(true);
                    }}
                    className="flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm font-medium text-gray-700 transition-colors hover:bg-red-50 hover:text-red-600"
                  >
                    <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                      <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v4m0 4h.01" />
                      <path strokeLinecap="round" strokeLinejoin="round" d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z" />
                    </svg>
                    Report Shop
                  </button>
                </div>
              )}
            </div>
        </div>

        {/* Shop Profile Section */}
        <div className="bg-white border-b border-gray-200 relative">
          <div className="mx-auto max-w-6xl px-4 py-10 sm:px-6 sm:py-12">
            {/* Follow + Message Buttons */}
            <div className="mb-6 flex w-full flex-row gap-2 sm:absolute sm:right-6 sm:top-6 sm:mb-0 sm:w-auto sm:flex-col">
              <button
                type="button"
                onClick={() => setIsFollowing((prev) => !prev)}
                className={`inline-flex flex-1 items-center justify-center rounded-lg px-4 py-2 text-sm font-semibold transition-colors border sm:w-36 sm:flex-none ${
                  isFollowing
                    ? 'bg-black text-white border-black hover:bg-gray-900'
                    : 'bg-white text-black border-gray-400 hover:bg-gray-50'
                }`}
              >
                {isFollowing ? 'Following' : 'Follow'}
              </button>
              <Link
                href={`/message/${shop.id}`}
                className="inline-flex flex-1 items-center justify-center rounded-lg border border-gray-400 bg-white px-4 py-2 text-center text-sm font-semibold text-black transition-colors hover:bg-gray-50 sm:w-36 sm:flex-none"
              >
                Message
              </Link>
            </div>

            <div className="flex flex-col gap-6 md:flex-row md:items-start md:gap-8">
              {/* Shop Icon and Basic Info */}
              <div className="shrink-0">
                <div className="relative z-10 -mt-20 h-28 w-28 overflow-hidden rounded-lg border-4 border-white bg-gray-100 shadow-lg sm:-mt-24 sm:h-40 sm:w-40">
                  {hasValidProfilePhoto ? (
                    <img
                      src={shop.profile_photo || ''}
                      alt={shop.name}
                      className="w-full h-full object-cover"
                      onError={() => setHasValidProfilePhoto(false)}
                    />
                  ) : (
                    <div className={`flex h-full w-full items-center justify-center ${defaultCoverColorClassName} text-4xl font-extrabold uppercase text-white sm:text-5xl`}>
                      {shopInitials}
                    </div>
                  )}
                </div>
              </div>

              {/* Shop Details */}
              <div className="flex-1">
                <div className="mb-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                  <h1 className="text-3xl font-bold text-black sm:text-4xl">{shop.name}</h1>
                  <span
                    className={`inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold uppercase tracking-wide ${businessTypeBadge.className}`}
                    title="Shop account type"
                  >
                    {businessTypeBadge.label}
                  </span>
                </div>
                
                <div className="mb-6 flex flex-wrap items-center gap-3 sm:gap-4">
                  <div className="flex items-center gap-1">
                    <span className="text-yellow-400 text-lg">★</span>
                    <span className="font-semibold text-black">{displayedRatingText}</span>
                    <span className="text-gray-500 text-sm">({reviewLabel})</span>
                  </div>
                  <span className="text-gray-300">|</span>
                  <span className="text-gray-600">{establishedYearText}</span>
                </div>

                <p className="text-gray-700 leading-relaxed mb-6 max-w-2xl">{displaySubtitle}</p>

                {/* Contact Info Grid */}
                <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                  <div>
                    <p className="text-xs text-gray-400 uppercase tracking-wider font-semibold mb-2">Location</p>
                    <p className="text-sm text-black font-medium">
                      {shop.address}
                      {shop.country && <span className="block text-xs text-gray-600">{shop.country}{shop.postal_code ? ` ${shop.postal_code}` : ''}</span>}
                    </p>
                  </div>
                  <div>
                    <p className="text-xs text-gray-400 uppercase tracking-wider font-semibold mb-2">Phone</p>
                    <p className="text-sm text-black font-medium">{shop.phone}</p>
                  </div>
                  <div>
                    <p className="text-xs text-gray-400 uppercase tracking-wider font-semibold mb-2">Email</p>
                    <p className="text-sm text-black font-medium">{shop.email}</p>
                  </div>
                </div>

              </div>
            </div>
          </div>
        </div>

        {/* Products Section */}
        <div className="mx-auto max-w-6xl px-4 py-12 sm:px-6 sm:py-16">
          <div className="mb-12">
            <h2 className="text-3xl font-bold text-black mb-8">{isServicesTab ? 'Services' : 'Featured Shoes'}</h2>

            <div className="flex gap-8 mb-10 overflow-x-auto pb-2">
              {categories.map((category) => (
                <button
                  key={category}
                  type="button"
                  onClick={() => setSelectedCategory(category)}
                  className={`text-sm font-medium tracking-wide uppercase whitespace-nowrap transition-all ${
                    selectedCategory === category
                      ? 'text-black border-b-2 border-black pb-1'
                      : 'text-gray-500 hover:text-black pb-1'
                  }`}
                >
                  {category}
                </button>
              ))}
              {showVirtualShowroom && (
                <Link
                  href={`/shop-profile/${shop.id}/virtual-showroom`}
                  className="text-sm font-medium tracking-wide uppercase whitespace-nowrap transition-all text-gray-500 hover:text-black pb-1"
                >
                  Virtual Showroom
                </Link>
              )}
            </div>

            {isServicesTab ? (
              <div className="space-y-10">
                {filteredRepairPackages.length > 0 && (
                  <section>
                    <h3 className="text-2xl font-bold text-black mb-6">Packages</h3>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                      {filteredRepairPackages.map((pkg) => (
                        <div key={pkg.id} className="border border-gray-200 rounded-2xl p-5 hover:shadow-md transition-shadow">
                          <div className="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs text-gray-600 mb-3">Package</div>
                          <h4 className="text-2xl font-bold text-black mb-2">{pkg.name}</h4>
                          {pkg.description && <p className="text-sm text-gray-600 mb-3">{pkg.description}</p>}
                          <p className="text-sm text-gray-600">Includes {pkg.service_count} service{pkg.service_count !== 1 ? 's' : ''}</p>
                          {pkg.savings_amount > 0 && (
                            <p className="text-sm text-green-700 font-medium">Save ₱{pkg.savings_amount.toLocaleString()}</p>
                          )}
                          <div className="mt-4 pt-4 border-t border-gray-100">
                            <p className="text-3xl font-black text-black">₱{pkg.package_price.toLocaleString()}</p>
                          </div>
                        </div>
                      ))}
                    </div>
                  </section>
                )}

                {filteredRepairServices.length > 0 ? (
                  <section>
                    <h3 className="text-2xl font-bold text-black mb-6">Individual Services</h3>
                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                      {filteredRepairServices.map((service) => (
                        <div key={service.id} className="border border-gray-200 rounded-2xl p-5 hover:shadow-md transition-shadow">
                          <div className="flex items-center justify-between mb-3 gap-3">
                            <h4 className="text-2xl font-bold text-black leading-tight">{service.title}</h4>
                            <span className="inline-flex items-center rounded-full bg-blue-50 px-2.5 py-0.5 text-xs text-blue-700 whitespace-nowrap">
                              {service.category}
                            </span>
                          </div>
                          <p className="text-sm text-gray-600 mb-4 line-clamp-3">{service.description}</p>
                          <div className="pt-4 border-t border-gray-100 flex items-center justify-between">
                            <p className="text-3xl font-black text-black">{service.price}</p>
                            <p className="text-xs text-gray-500">{service.duration}</p>
                          </div>
                        </div>
                      ))}
                    </div>
                  </section>
                ) : (
                  <div className="text-center py-16">
                    <p className="text-gray-500 text-lg">No repair services available right now</p>
                  </div>
                )}

                {(filteredRepairPackages.length > 0 || filteredRepairServices.length > 0) && (
                  <div className="pt-2">
                    <Link
                      href={`/repair-shop/${shop.id}`}
                      className="inline-flex items-center justify-center bg-black text-white px-6 py-3 rounded-xl text-sm font-semibold hover:bg-gray-900 transition-colors"
                    >
                      Book Repair Service
                    </Link>
                  </div>
                )}
              </div>
            ) : filteredProducts.length > 0 ? (
              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                {filteredProducts.map((product) => {
                  const productImages = getProductImages(product);
                  const activeImageIndex = activeImageIndexes[product.id] ?? 0;

                  return (
                  <Link
                    key={product.id}
                    href={`/products/${product.slug}`}
                    className="group block h-full rounded-2xl border border-gray-200 bg-white shadow-[0_12px_28px_-24px_rgba(15,23,42,0.45)] transition-all duration-300 hover:-translate-y-1 hover:border-gray-300 hover:shadow-[0_24px_40px_-24px_rgba(15,23,42,0.55)] xl:rounded-3xl xl:border-gray-300 xl:shadow-[0_16px_35px_-24px_rgba(15,23,42,0.45)]"
                    onMouseEnter={() => startImageCycle(product)}
                    onMouseLeave={() => stopImageCycle(product.id)}
                  >
                    <div className="relative flex h-full flex-col overflow-hidden rounded-2xl bg-white xl:rounded-3xl">
                      {product.compare_at_price && product.compare_at_price > product.price && (
                        <div className="absolute left-4 top-4 z-10 rounded-full bg-red-600 px-3 py-1.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-white shadow-sm">
                          SALE
                        </div>
                      )}
                      {product.stock_quantity === 0 && (
                        <div className="absolute left-4 top-4 z-10 rounded-full bg-black px-3 py-1.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-white shadow-sm">
                          SOLD OUT
                        </div>
                      )}

                      <div className="relative aspect-3/4 overflow-hidden bg-gray-50 xl:aspect-square">
                        {productImages.length > 0 ? (
                          productImages.map((image, imageIndex) => {
                            const isActiveImage = imageIndex === activeImageIndex;

                            return (
                              <img
                                key={`${product.id}-${imageIndex}`}
                                src={image}
                                alt={product.name}
                                className={`absolute inset-0 h-full w-full object-cover transition-all duration-700 ease-in-out ${
                                  isActiveImage
                                    ? 'opacity-100 scale-100 group-hover:scale-110'
                                    : 'opacity-0 scale-100 pointer-events-none'
                                }`}
                                loading="lazy"
                                onError={(e) => {
                                  if (product.main_image) {
                                    e.currentTarget.src = product.main_image;
                                  }
                                }}
                              />
                            );
                          })
                        ) : (
                          <div className="flex h-full w-full items-center justify-center text-sm text-gray-400">No Image</div>
                        )}
                      </div>

                      <div className="flex min-h-36 flex-col border-t border-gray-200 p-2.5 xl:min-h-48.5 xl:p-3.5">
                        <h3 className="mb-1 min-h-8 line-clamp-2 text-xs font-bold uppercase tracking-[0.06em] text-black xl:mb-1.5 xl:min-h-10 xl:text-sm">{product.name}</h3>

                        <div className="mb-1 min-h-4 xl:mb-1.5 xl:min-h-[1.1rem]">
                          {product.brand && (
                            <p className="text-[10px] uppercase tracking-[0.12em] text-black/55 xl:text-xs">{product.brand}</p>
                          )}
                        </div>

                        <div className="mb-1 min-h-[1.1rem]">
                          <span className={`text-xs font-medium ${product.stock_quantity > 0 ? 'text-green-600' : 'text-red-600'}`}>
                            {product.stock_quantity > 0 ? `${product.stock_quantity} in stock` : 'Out of stock'}
                          </span>
                        </div>

                        <div className="mt-auto flex items-baseline justify-between border-t border-gray-200 pt-2 xl:pt-3">
                          <div className="flex flex-col gap-0.5">
                            <div className="text-base font-bold text-black xl:text-lg">₱{product.price.toLocaleString()}</div>
                            {product.compare_at_price && product.compare_at_price > product.price && (
                              <div className="text-xs text-black/40 line-through">₱{product.compare_at_price.toLocaleString()}</div>
                            )}
                          </div>
                        </div>
                      </div>
                    </div>
                  </Link>
                );
                })}
              </div>
            ) : (
              <div className="text-center py-16">
                <p className="text-gray-500 text-lg">No products in this category</p>
              </div>
            )}
          </div>
        </div>
      </main>

      {/* Footer */}
      <footer className="hidden xl:block mt-16 bg-white border-t border-gray-100">
        <div className="mx-auto max-w-6xl px-4 py-12 sm:px-6 sm:py-16">
          <div className="grid grid-cols-1 gap-10 md:grid-cols-3 md:gap-12">
            <div>
              <div className="text-2xl font-bold mb-6 text-black">SoleSpace</div>
              <p className="text-sm text-gray-500 leading-relaxed max-w-sm">
                Your premier destination for premium footwear and expert repair services.
              </p>
            </div>
            <div className="flex flex-col">
              <h3 className="text-xs uppercase text-gray-400 font-semibold tracking-wider mb-6">Quick Links</h3>
              <nav className="flex flex-col gap-4 text-sm text-gray-700">
                <Link href="/products" className="hover:text-black transition-colors">Shoes</Link>
                <Link href="/repair-services" className="hover:text-black transition-colors">Repair Services</Link>
                <Link href="/my-orders" className="hover:text-black transition-colors">My Orders</Link>
              </nav>
            </div>
            <div className="flex flex-col">
              <h3 className="text-xs uppercase text-gray-400 font-semibold tracking-wider mb-6">Services</h3>
              <nav className="flex flex-col gap-4 text-sm text-gray-700">
                <a href="#" className="hover:text-black transition-colors">Shoe Repair</a>
                <a href="#" className="hover:text-black transition-colors">Custom Fitting</a>
                <a href="#" className="hover:text-black transition-colors">Maintenance</a>
              </nav>
            </div>
          </div>
          <div className="mt-12 flex flex-col gap-3 border-t border-gray-100 pt-8 text-xs text-gray-400 sm:flex-row sm:items-center sm:justify-between">
            <div>© 2026 SoleSpace. All rights reserved.</div>
          </div>
        </div>
      </footer>
    </div>
      <ReportShopModal
        shopId={shop.id}
        shopName={shop.name}
        isOpen={showReportModal}
        onClose={() => setShowReportModal(false)}
      />
    </>
  );
};

export default ShopProfile;
