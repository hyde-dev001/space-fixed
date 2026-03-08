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

interface Props {
  // will accept products from backend later
}

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
  
  const [products, setProducts] = useState<Product[]>([]);
  const [loading, setLoading] = useState(true);
  const [sortBy, setSortBy] = useState('near_me');
  const [isSortOpen, setIsSortOpen] = useState(false);
  const [searchQuery, setSearchQuery] = useState(searchParam);
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
    setSearchQuery(search);
  }, [window.location.search]);

  useEffect(() => {
    fetchProducts();
  }, [sortBy, currentPage, searchQuery]);

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
    near_me: 'Near me 📍',
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

  return (
    <>
      <Head title="Products" />
      <div className="min-h-screen bg-white font-outfit antialiased">
        <Navigation />

        <div className="max-w-[1920px] mx-auto px-4 sm:px-6 lg:px-12 py-8 lg:py-16">
          <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between mb-6 gap-3">
            <div className="text-xs sm:text-sm text-black/60 tracking-wide">HOME / ALL SHOES</div>
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
                      className={`h-3.5 w-3.5 text-black/70 transition-transform duration-200 ${isSortOpen ? 'rotate-180' : ''}`}
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
                  <div className="absolute right-0 mt-3 w-52 border border-gray-200 bg-white py-3 shadow-sm z-20" role="menu">
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
                          className="group w-full px-5 py-2 text-left text-sm"
                        >
                          <span className={`relative inline-block ${isActive ? 'text-black font-medium' : 'text-black/80'}`}>
                            {option.label}
                            <span
                              className="absolute -bottom-0.5 left-0 h-px w-full bg-black origin-left scale-x-0 transition-transform duration-300 group-hover:scale-x-100"
                            />
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
            <div className="mb-6 rounded bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
              {locError}
            </div>
          )}

          <h1 className="text-3xl sm:text-4xl lg:text-5xl font-bold text-black mb-3 tracking-tight">
            {searchQuery ? `Search Results for "${searchQuery}"` : 'ALL SHOES'}
          </h1>
          <p className="text-sm sm:text-base text-black/70 mb-8 max-w-2xl leading-relaxed">
            {searchQuery 
              ? `Showing results matching "${searchQuery}"`
              : 'Browse our curated collection of shoes. Click a product to view details and select sizes.'}
          </p>

          {loading ? (
            <div className="text-center py-12">
              <p className="text-gray-500">Loading products...</p>
            </div>
          ) : products.length === 0 ? (
            <div className="text-center py-12">
              <p className="text-gray-500">No products available at the moment.</p>
            </div>
          ) : (
            <div className="grid gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
              {sortedProducts.map((p) => {
                const productImages = getProductImages(p);
                const activeImageIndex = activeImageIndexes[p.id] ?? 0;
                const activeImage = productImages[activeImageIndex] ?? p.main_image;
                const shopDist = (sortBy === 'near_me' && userCoords) ? getShopDistance(p) : null;

                return (
                <Link
                  key={p.id}
                  href={`/products/${p.slug}`}
                  className="group block"
                  onMouseEnter={() => startImageCycle(p)}
                  onMouseLeave={() => stopImageCycle(p.id)}
                >
                  <div className="bg-white rounded-lg border border-gray-100 overflow-hidden hover:shadow-lg transition-all duration-200 relative flex flex-col h-full">
                    {p.compare_at_price && p.compare_at_price > p.price && (
                      <div className="absolute left-3 top-3 bg-red-600 text-white text-xs px-2.5 py-1 rounded font-semibold z-10 shadow-sm">
                        SALE
                      </div>
                    )}
                    {p.stock_quantity === 0 && (
                      <div className="absolute left-3 top-3 bg-black text-white text-xs px-2.5 py-1 rounded font-semibold z-10 shadow-sm">
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
                                    ? 'opacity-100 scale-100 group-hover:scale-105'
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

                    <div className="p-3.5 flex flex-col flex-grow">
                      <h3 className="text-sm font-medium text-black mb-1.5 line-clamp-2">{p.name}</h3>
                      
                      {p.brand && (
                        <p className="text-xs text-gray-500 mb-2">{p.brand}</p>
                      )}
                      
                      {p.shop_owner && (
                        <p className="text-xs text-gray-500 mb-2">
                          Sold by{' '}
                          <span
                            className="font-medium text-black hover:underline"
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

                      {shopDist !== null && (
                        <p className="text-xs text-black/50 mb-2">📍 {formatDistance(shopDist)}</p>
                      )}
                      
                      <div className="flex items-baseline justify-between mt-auto pt-2.5 border-t border-gray-100">
                        <div className="flex flex-col gap-0.5">
                          <div className="text-lg font-bold text-black">₱{p.price.toLocaleString()}</div>
                          {p.compare_at_price && p.compare_at_price > p.price && (
                            <div className="text-xs text-gray-400 line-through">₱{p.compare_at_price.toLocaleString()}</div>
                          )}
                        </div>
                        <div className="text-xs text-gray-500">
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
            <div className="mt-8 flex items-center justify-center gap-2">
              <button
                onClick={() => goToPage(currentPage - 1)}
                disabled={currentPage === 1}
                className={`px-3 py-1.5 border rounded-md text-sm font-medium transition-colors ${
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
                        className={`px-3 py-1.5 border rounded-md text-sm font-medium transition-colors ${
                          currentPage === page
                            ? 'bg-black text-white border-black'
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
                className={`px-3 py-1.5 border rounded-md text-sm font-medium transition-colors ${
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
            <div className="mt-4 text-center text-sm text-gray-500">
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
