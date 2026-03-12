import React, { useState, useEffect, useRef, useMemo, useCallback } from 'react';
import { Head, Link } from '@inertiajs/react';
import Navigation from '../Shared/Navigation';

type Product = {
  id: number;
  name: string;
  price: number;
  compare_at_price?: number | null;
  slug: string;
  main_image: string | null;
  hover_image?: string | null;
  gallery_images?: string[];
  brand: string | null;
  stock_quantity: number;
  description?: string | null;
  shop_owner?: {
    id: number;
    name?: string;
    business_name?: string;
    latitude?: number | null;
    longitude?: number | null;
  };
};

type ShopSearchResult = {
  id: number;
  name: string;
  location?: string | null;
  image?: string | null;
  url: string;
  virtual_showroom_url: string;
};

interface Props {
  // will accept products from backend later
}

const ALLOWED_CATEGORY_FILTERS = ['men', 'women', 'kids', 'sports'] as const;

// --- Near Me helpers ---
const haversine = (lat1: number, lng1: number, lat2: number, lng2: number): number => {
  const R = 6371;
  const dLat = (lat2 - lat1) * (Math.PI / 180);
  const dLng = (lng2 - lng1) * (Math.PI / 180);
  const a =
    Math.sin(dLat / 2) ** 2 +
    Math.cos(lat1 * (Math.PI / 180)) * Math.cos(lat2 * (Math.PI / 180)) *
    Math.sin(dLng / 2) ** 2;
  return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
};

const formatDistance = (km: number): string => {
  if (km < 1) return `${Math.round(km * 1000)} m away`;
  return `${km.toFixed(1)} km away`;
};

const Products: React.FC<Props> = () => {
  const urlParams = new URLSearchParams(window.location.search);
  const searchParam = urlParams.get('search') || '';
  const rawCategoryParam = (urlParams.get('category') || '').toLowerCase();
  const categoryParam = ALLOWED_CATEGORY_FILTERS.includes(rawCategoryParam as typeof ALLOWED_CATEGORY_FILTERS[number])
    ? rawCategoryParam
    : '';
  
  const [products, setProducts] = useState<Product[]>([]);
  const [shopResults, setShopResults] = useState<ShopSearchResult[]>([]);
  const [loadingShops, setLoadingShops] = useState(false);
  const [loading, setLoading] = useState(true);
  const [sortBy, setSortBy] = useState('near_me');
  const [isSortOpen, setIsSortOpen] = useState(false);
  const [searchQuery, setSearchQuery] = useState(searchParam);
  const [activeCategory, setActiveCategory] = useState(categoryParam);
  const [currentPage, setCurrentPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [activeImageIndexes, setActiveImageIndexes] = useState<Record<number, number>>({});
  const [userCoords, setUserCoords] = useState<{ lat: number; lng: number } | null>(null);
  const [locating, setLocating] = useState(false);
  const [locError, setLocError] = useState<string | null>(null);
  const sortMenuRef = useRef<HTMLDivElement | null>(null);
  const hoverTimersRef = useRef<Record<number, number>>({});

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const search = params.get('search') || '';
    const category = (params.get('category') || '').toLowerCase();
    setSearchQuery(search);
    setActiveCategory(
      ALLOWED_CATEGORY_FILTERS.includes(category as typeof ALLOWED_CATEGORY_FILTERS[number])
        ? category
        : ''
    );
  }, [window.location.search]);

  useEffect(() => {
    fetchProducts();
  }, [sortBy, currentPage, searchQuery, activeCategory]);

  useEffect(() => {
    const fetchShops = async () => {
      const query = searchQuery.trim();

      if (!query) {
        setShopResults([]);
        setLoadingShops(false);
        return;
      }

      try {
        setLoadingShops(true);
        const response = await fetch(`/api/search/suggestions?query=${encodeURIComponent(query)}`, {
          headers: { Accept: 'application/json' },
        });

        if (!response.ok) throw new Error('Failed to fetch shop results');

        const data = await response.json();
        setShopResults(Array.isArray(data.shops) ? data.shops : []);
      } catch (error) {
        console.error('Error fetching shop results:', error);
        setShopResults([]);
      } finally {
        setLoadingShops(false);
      }
    };

    fetchShops();
  }, [searchQuery]);

  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (sortMenuRef.current && !sortMenuRef.current.contains(event.target as Node)) {
        setIsSortOpen(false);
      }
    };

    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  useEffect(() => {
    return () => {
      Object.values(hoverTimersRef.current).forEach((timerId) => window.clearInterval(timerId));
      hoverTimersRef.current = {};
    };
  }, []);

  const fetchProducts = async () => {
    try {
      setLoading(true);
      const params = new URLSearchParams();

      if (sortBy === 'near_me') {
        // Fetch all products so we can sort client-side by shop distance
        params.append('sort', '-created_at');
        params.append('page', '1');
        params.append('per_page', '1000');
      } else {
        // Sorting (use - prefix for descending)
        if (sortBy === 'featured') {
          params.append('sort', '-created_at');
        } else if (sortBy === 'best_selling') {
          params.append('sort', '-sales_count');
        } else if (sortBy === 'name_asc') {
          params.append('sort', 'name');
        } else if (sortBy === 'name_desc') {
          params.append('sort', '-name');
        } else if (sortBy === 'price_asc') {
          params.append('sort', 'price');
        } else if (sortBy === 'price_desc') {
          params.append('sort', '-price');
        } else if (sortBy === 'created_at_asc') {
          params.append('sort', 'created_at');
        } else {
          params.append('sort', '-created_at');
        }

        // Pagination
        params.append('page', currentPage.toString());
      }

      // Search filter
      if (searchQuery) {
        params.append('filter[search_all]', searchQuery);
      }

      // Category filter (only for supported storefront categories)
      if (activeCategory) {
        params.append('filter[category]', activeCategory);
      }

      const response = await fetch(`/api/products/?${params.toString()}`, {
        headers: { 'Accept': 'application/json' }
      });

      if (!response.ok) throw new Error('Failed to fetch products');

      const data = await response.json();
      setProducts(data.products.data || []);
      setCurrentPage(data.products.current_page || 1);
      setLastPage(data.products.last_page || 1);
      setTotal(data.products.total || 0);
    } catch (error) {
      console.error('Error fetching products:', error);
    } finally {
      setLoading(false);
    }
  };

  const goToPage = (page: number) => {
    if (page >= 1 && page <= lastPage) {
      setCurrentPage(page);
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }
  };

  const sortLabelMap: Record<string, string> = {
    featured: 'Featured',
    best_selling: 'Best selling',
    name_asc: 'Alphabetically, A-Z',
    name_desc: 'Alphabetically, Z-A',
    price_asc: 'Price, low to high',
    price_desc: 'Price, high to low',
    created_at_asc: 'Date, old to new',
    created_at_desc: 'Date, new to old',
    near_me: 'Near me📍',
  };

  const sortOptions = [
    { value: 'near_me', label: 'Near me' },
    { value: 'featured', label: 'Featured' },
    { value: 'best_selling', label: 'Best selling' },
    { value: 'name_asc', label: 'Alphabetically, A-Z' },
    { value: 'name_desc', label: 'Alphabetically, Z-A' },
    { value: 'price_asc', label: 'Price, low to high' },
    { value: 'price_desc', label: 'Price, high to low' },
    { value: 'created_at_asc', label: 'Date, old to new' },
    { value: 'created_at_desc', label: 'Date, new to old' },
  ];

  const getProductImages = (product: Product) => {
    const images = [product.main_image, ...(product.gallery_images ?? [])].filter(Boolean) as string[];
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

  // --- Near Me ---
  const requestLocation = useCallback(() => {
    if (!navigator.geolocation) {
      setLocError('Geolocation is not supported by your browser.');
      return;
    }
    setLocating(true);
    setLocError(null);
    navigator.geolocation.getCurrentPosition(
      (position) => {
        setUserCoords({ lat: position.coords.latitude, lng: position.coords.longitude });
        setLocating(false);
      },
      () => {
        setLocError('Location access denied. Please allow location access and try again.');
        setLocating(false);
      },
    );
  }, []);

  useEffect(() => {
    if (sortBy === 'near_me' && !userCoords) {
      requestLocation();
    }
  }, [sortBy, userCoords, requestLocation]);

  const sortedProducts = useMemo(() => {
    if (sortBy !== 'near_me' || !userCoords) return products;
    return [...products].sort((a, b) => {
      const aLat = a.shop_owner?.latitude;
      const aLng = a.shop_owner?.longitude;
      const bLat = b.shop_owner?.latitude;
      const bLng = b.shop_owner?.longitude;
      const aDist =
        aLat != null && aLng != null
          ? haversine(userCoords.lat, userCoords.lng, aLat, aLng)
          : Infinity;
      const bDist =
        bLat != null && bLng != null
          ? haversine(userCoords.lat, userCoords.lng, bLat, bLng)
          : Infinity;
      return aDist - bDist;
    });
  }, [products, sortBy, userCoords]);

  const getShopDistance = (product: Product): number | null => {
    if (!userCoords || product.shop_owner?.latitude == null || product.shop_owner?.longitude == null)
      return null;
    return haversine(userCoords.lat, userCoords.lng, product.shop_owner.latitude!, product.shop_owner.longitude!);
  };

  const isShowroomSearch = searchQuery.trim().toLowerCase().includes('showroom');
  const buttonBaseClass =
    'group inline-flex items-center justify-center gap-2 rounded-full px-4 py-2 text-xs font-semibold uppercase tracking-[0.16em] transition-all duration-300 focus-visible:outline-none focus-visible:ring-2';
  const buttonDarkClass =
    'border border-[#16233b] bg-[#16233b] text-white hover:-translate-y-0.5 hover:bg-black focus-visible:ring-[#16233b]/50';
  const buttonLightClass =
    'border border-gray-300 bg-white text-gray-900 hover:-translate-y-0.5 hover:border-gray-400 hover:bg-gray-50 focus-visible:ring-gray-300';

  return (
    <>
      <Head title="Products" />
      <div className="min-h-screen bg-white font-outfit antialiased">
        <Navigation />

        <div className="max-w-[1920px] mx-auto px-6 lg:px-12 pt-28 pb-10 lg:pt-32 lg:pb-20">
          <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between mb-8 gap-4">
            <div className="text-[11px] sm:text-xs text-black/55 tracking-[0.18em] uppercase">Home / All Shoes</div>
            <div className="flex items-center gap-3">
              <div className="relative" ref={sortMenuRef}>
                <button
                  type="button"
                  onClick={() => setIsSortOpen((prev) => !prev)}
                  className="flex items-center gap-2 text-sm text-black/80"
                >
                  <span>
                    <span className="font-semibold">Sort by:</span>{' '}
                    <span>{sortLabelMap[sortBy]}</span>
                  </span>
                  <span className="flex h-6 w-6 items-center justify-center rounded-full bg-gray-100">
                    <svg
                      className={`h-3.5 w-3.5 text-gray-700 transition-transform duration-200 ${isSortOpen ? 'rotate-180' : ''}`}
                      viewBox="0 0 20 20"
                      fill="none"
                      xmlns="http://www.w3.org/2000/svg"
                      aria-hidden="true"
                    >
                      <path d="M5 12L10 7L15 12" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" />
                    </svg>
                  </span>
                </button>

                {isSortOpen && (
                  <div className="absolute right-0 mt-3 w-56 rounded-2xl border border-gray-300 bg-white py-3 shadow-[0_20px_40px_-24px_rgba(15,23,42,0.55)] z-20" role="menu">
                    {sortOptions.map((option) => {
                      const isActive = sortBy === option.value;

                      return (
                        <button
                          key={option.value}
                          type="button"
                          role="menuitem"
                          onClick={() => {
                            if (option.value === 'near_me') {
                              setSortBy('near_me');
                              setCurrentPage(1);
                              setIsSortOpen(false);
                              if (!userCoords) requestLocation();
                            } else {
                              setSortBy(option.value);
                              setIsSortOpen(false);
                            }
                          }}
                          className="group w-full px-5 py-2.5 text-left text-sm"
                        >
                          <span className={`relative inline-block ${isActive ? 'text-black font-semibold' : 'text-black/75'}`}>
                            {option.label}
                            <span className={`absolute bottom-0 left-0 h-[1.5px] bg-black transition-all duration-300 ${isActive ? 'w-full' : 'w-0 group-hover:w-full'}`} />
                          </span>
                        </button>
                      );
                    })}
                  </div>
                )}
              </div>
            </div>
          </div>

          {locError && (
            <div className="mb-8 rounded-2xl bg-red-50 border border-red-200 px-5 py-3.5 text-sm text-red-700">
              {locError}
            </div>
          )}

          <h1 className="text-4xl sm:text-5xl lg:text-6xl font-bold text-black mb-4 tracking-tight uppercase">
            {searchQuery ? `Search Results for "${searchQuery}"` : 'ALL SHOES'}
          </h1>
          <p className="text-base text-black/65 mb-10 max-w-3xl leading-relaxed font-light">
            {searchQuery 
              ? `Showing results matching "${searchQuery}"`
              : 'Browse our curated collection of shoes. Click a product to view details and select sizes.'}
          </p>

          {searchQuery && (
            <div className="mb-10">
              <h2 className="mb-4 text-xl font-bold text-black uppercase tracking-[0.08em]">
                {isShowroomSearch ? 'Shops with virtual showroom' : 'Matching shops'}
              </h2>
              {loadingShops ? (
                <p className="text-sm text-black/60">Loading shop profiles...</p>
              ) : shopResults.length === 0 ? (
                <p className="text-sm text-black/60">
                  {isShowroomSearch
                    ? 'No shop profiles with virtual showroom were found.'
                    : 'No matching shop profiles found.'}
                </p>
              ) : (
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                  {shopResults.map((shop) => (
                    <div key={shop.id} className="rounded-2xl border border-gray-300 bg-white p-4 shadow-[0_16px_30px_-24px_rgba(15,23,42,0.45)] transition-all duration-300 hover:-translate-y-0.5 hover:shadow-[0_24px_40px_-24px_rgba(15,23,42,0.55)]">
                      <Link href={shop.url} className="flex items-center gap-3">
                        <div className="h-11 w-11 overflow-hidden rounded-full bg-gray-100 border border-gray-200">
                          {shop.image ? (
                            <img src={shop.image} alt={shop.name} className="h-full w-full object-cover" />
                          ) : (
                            <div className="flex h-full w-full items-center justify-center text-xs text-gray-500">S</div>
                          )}
                        </div>
                        <div className="min-w-0">
                          <p className="truncate text-sm font-semibold text-black uppercase tracking-[0.06em]">{shop.name}</p>
                          {shop.location && <p className="truncate text-xs text-black/55">{shop.location}</p>}
                        </div>
                      </Link>
                      <div className="mt-3 flex gap-2">
                        <Link
                          href={shop.url}
                          className={`${buttonBaseClass} ${buttonLightClass}`}
                        >
                          View profile
                        </Link>
                        <Link
                          href={shop.virtual_showroom_url}
                          className={`${buttonBaseClass} ${buttonDarkClass}`}
                        >
                          Virtual showroom
                        </Link>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>
          )}

          {loading ? (
            <div className="text-center py-16 border border-gray-200 rounded-3xl bg-gray-50/50">
              <p className="text-black/60">Loading products...</p>
            </div>
          ) : products.length === 0 ? (
            <div className="text-center py-16 border border-gray-200 rounded-3xl bg-gray-50/50">
              <p className="text-black/60">
                {searchQuery && shopResults.length > 0
                  ? 'No matching products found, but matching shop profiles are available above.'
                  : 'No products available for this search right now.'}
              </p>
            </div>
          ) : (
            <div className="grid auto-rows-fr gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
              {sortedProducts.map((p) => {
                const productImages = getProductImages(p);
                const activeImageIndex = activeImageIndexes[p.id] ?? 0;
                const activeImage = productImages[activeImageIndex] ?? p.main_image;
                const shopDist = (sortBy === 'near_me' && userCoords) ? getShopDistance(p) : null;
                const productHref = activeCategory
                  ? `/products/${p.slug}?category=${encodeURIComponent(activeCategory)}`
                  : `/products/${p.slug}`;

                return (
                <Link
                  key={p.id}
                  href={productHref}
                  className="group block h-full rounded-3xl border border-gray-300 bg-white shadow-[0_16px_35px_-24px_rgba(15,23,42,0.45)] transition-all duration-300 hover:-translate-y-1 hover:border-gray-400 hover:shadow-[0_28px_48px_-24px_rgba(15,23,42,0.55)]"
                  onMouseEnter={() => startImageCycle(p)}
                  onMouseLeave={() => stopImageCycle(p.id)}
                >
                  <div className="bg-white rounded-3xl overflow-hidden relative flex flex-col h-full">
                    {p.compare_at_price && p.compare_at_price > p.price && (
                      <div className="absolute left-4 top-4 bg-red-600 text-white text-[10px] px-3 py-1.5 rounded-full font-semibold uppercase tracking-[0.14em] z-10 shadow-sm">
                        SALE
                      </div>
                    )}
                    {p.stock_quantity === 0 && (
                      <div className="absolute left-4 top-4 bg-black text-white text-[10px] px-3 py-1.5 rounded-full font-semibold uppercase tracking-[0.14em] z-10 shadow-sm">
                        SOLD OUT
                      </div>
                    )}

                    <div className="aspect-square bg-gray-50 flex items-center justify-center overflow-hidden relative">
                      {activeImage ? (
                        <>
                          {productImages.map((image, imageIndex) => {
                            const isActiveImage = imageIndex === activeImageIndex;

                            return (
                              <img
                                key={`${p.id}-${imageIndex}`}
                                src={image}
                                alt={p.name}
                                className={`absolute inset-0 h-full w-full object-cover transition-all duration-700 ease-in-out ${
                                  isActiveImage
                                    ? 'opacity-100 scale-100 group-hover:scale-110'
                                    : 'opacity-0 scale-100 pointer-events-none'
                                }`}
                                loading="lazy"
                                onError={(e) => {
                                  if (p.main_image) {
                                    e.currentTarget.src = p.main_image;
                                  }
                                }}
                              />
                            );
                          })}
                        </>
                      ) : (
                        <div className="text-gray-400 text-sm">No Image</div>
                      )}
                    </div>

                    <div className="border-t border-gray-200 p-3.5 flex flex-col h-[194px]">
                      <h3 className="text-sm font-bold text-black mb-1.5 uppercase tracking-[0.06em] line-clamp-2 min-h-[2.5rem]">{p.name}</h3>
                      
                      <div className="min-h-[1.1rem] mb-1.5">
                        {p.brand && (
                          <p className="text-xs text-black/55 uppercase tracking-[0.12em]">{p.brand}</p>
                        )}
                      </div>
                      
                      <div className="min-h-[1.1rem] mb-1.5">
                        {p.shop_owner && (
                          <p className="text-xs text-black/60">
                            Sold by{' '}
                            <span
                              className="font-semibold text-black hover:underline"
                              onClick={(e) => {
                                e.preventDefault();
                                e.stopPropagation();
                                window.location.href = `/shop-profile/${p.shop_owner?.id}`;
                              }}
                            >
                              {p.shop_owner.business_name || p.shop_owner.name || 'Shop'}
                            </span>
                          </p>
                        )}
                      </div>

                      <div className="min-h-[1.1rem] mb-1.5">
                        {shopDist !== null && (
                          <p className="text-xs text-black/50">📍 {formatDistance(shopDist)}</p>
                        )}
                      </div>
                      
                      <div className="flex items-baseline justify-between mt-auto pt-3 border-t border-gray-200">
                        <div className="flex flex-col gap-0.5">
                          <div className="text-lg font-bold text-black">₱{p.price.toLocaleString()}</div>
                          {p.compare_at_price && p.compare_at_price > p.price && (
                            <div className="text-xs text-black/40 line-through">₱{p.compare_at_price.toLocaleString()}</div>
                          )}
                        </div>
                        <div className="text-xs text-black/55 uppercase tracking-[0.08em]">
                          {p.stock_quantity > 0 ? `${p.stock_quantity} left` : 'Out of stock'}
                        </div>
                      </div>
                    </div>
                  </div>
                </Link>
                );
              })}
            </div>
          )}

          {/* Pagination */}
          {!loading && products.length > 0 && lastPage > 1 && sortBy !== 'near_me' && (
            <div className="mt-10 flex items-center justify-center gap-2">
              <button
                onClick={() => goToPage(currentPage - 1)}
                disabled={currentPage === 1}
                className={`px-4 py-2 border rounded-full text-xs font-semibold uppercase tracking-[0.12em] transition-colors ${
                  currentPage === 1
                    ? 'border-gray-200 text-gray-400 cursor-not-allowed'
                    : 'border-gray-300 text-black hover:bg-gray-50'
                }`}
              >
                Previous
              </button>

              <div className="flex items-center gap-1">
                {Array.from({ length: lastPage }, (_, i) => i + 1).map((page) => {
                  if (
                    page === 1 ||
                    page === lastPage ||
                    (page >= currentPage - 1 && page <= currentPage + 1)
                  ) {
                    return (
                      <button
                        key={page}
                        onClick={() => goToPage(page)}
                        className={`min-w-9 px-3 py-2 border rounded-full text-xs font-semibold uppercase tracking-[0.12em] transition-colors ${
                          currentPage === page
                            ? 'bg-[#16233b] text-white border-[#16233b]'
                            : 'border-gray-300 text-black hover:bg-gray-50'
                        }`}
                      >
                        {page}
                      </button>
                    );
                  } else if (page === currentPage - 2 || page === currentPage + 2) {
                    return (
                      <span key={page} className="px-2 text-gray-400">...</span>
                    );
                  }
                  return null;
                })}
              </div>

              <button
                onClick={() => goToPage(currentPage + 1)}
                disabled={currentPage === lastPage}
                className={`px-4 py-2 border rounded-full text-xs font-semibold uppercase tracking-[0.12em] transition-colors ${
                  currentPage === lastPage
                    ? 'border-gray-200 text-gray-400 cursor-not-allowed'
                    : 'border-gray-300 text-black hover:bg-gray-50'
                }`}
              >
                Next
              </button>
            </div>
          )}

          {/* Results info */}
          {!loading && products.length > 0 && (
            <div className="mt-5 text-center text-xs uppercase tracking-[0.14em] text-black/50">
              {sortBy === 'near_me'
                ? `Showing ${sortedProducts.length} products sorted by distance`
                : `Showing ${products.length} of ${total} products (Page ${currentPage} of ${lastPage})`}
            </div>
          )}
        </div>
      </div>
    </>
  );
};

export default Products;
