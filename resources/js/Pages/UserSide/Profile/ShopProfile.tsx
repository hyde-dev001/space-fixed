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
  established_year: number;
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
  const isRepairOnlyShop = inferredBusinessType === 'repair';
  const isRetailAndRepairShop = inferredBusinessType === 'both';
  const profileSubtitle = inferredBusinessType === 'both'
    ? 'Premium footwear products and services'
    : inferredBusinessType === 'repair'
      ? 'Premium services'
      : 'Premium footwear products';
  const categories = isRepairOnlyShop
    ? ['Services']
    : isRetailAndRepairShop
      ? ['Shoes', 'Men', 'Women', 'Kids', 'Sports', 'Services']
      : ['Shoes', 'Men', 'Women', 'Kids', 'Sports'];
  const showVirtualShowroom = !isRepairOnlyShop && Boolean(shop.has_active_premium);
  const [isFollowing, setIsFollowing] = useState<boolean>(false);
  const [showReportModal, setShowReportModal] = useState(false);
  const [isActionMenuOpen, setIsActionMenuOpen] = useState(false);
  const [selectedCategory, setSelectedCategory] = useState<string>(isRepairOnlyShop ? 'Services' : 'Shoes');
  const [activeImageIndexes, setActiveImageIndexes] = useState<Record<number, number>>({});
  const hoverTimersRef = useRef<Record<number, number>>({});
  const actionMenuRef = useRef<HTMLDivElement>(null);
  const isServicesTab = selectedCategory === 'Services';

  useEffect(() => {
    if (!categories.includes(selectedCategory)) {
      setSelectedCategory(categories[0]);
    }
  }, [categories, selectedCategory]);

  const filteredProducts = products.filter((product) => {
    if (selectedCategory === 'Shoes') {
      return true;
    }

    const normalizedCategory = (product.category || '').toLowerCase();
    const categoryParts = normalizedCategory
      .split(',')
      .map((item) => item.trim())
      .filter(Boolean);

    const target = selectedCategory.toLowerCase();
    return categoryParts.some((item) => item === target || item.includes(target));
  });

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

  return (
    <>
    <div className="min-h-dvh flex flex-col bg-white">
      <Head title={shop.name} />
      <Navigation />

      <main className="flex-1 pt-20 sm:pt-24 lg:pt-28">
        {/* Cover Image Section */}
        <div className="relative h-64 md:h-80 bg-gray-200 overflow-hidden">
          <img
            src={shop.cover_image}
            alt="Shop Cover"
            className="w-full h-full object-cover"
          />
          <div className="absolute inset-0 bg-black bg-opacity-10"></div>

          {isAuthenticated && (
            <div ref={actionMenuRef} className="absolute top-4 right-4 z-20">
              <button
                type="button"
                onClick={() => setIsActionMenuOpen((prev) => !prev)}
                className="inline-flex h-10 w-10 items-center justify-center rounded-full bg-white/90 text-gray-700 shadow-md backdrop-blur-sm transition hover:bg-white"
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
          )}
        </div>

        {/* Shop Profile Section */}
        <div className="bg-white border-b border-gray-200 relative">
          <div className="mx-auto max-w-6xl px-4 py-10 sm:px-6 sm:py-12">
            {/* Follow + Message Buttons */}
            <div className="mb-6 flex w-full flex-row gap-2 sm:absolute sm:right-6 sm:top-6 sm:mb-0 sm:w-auto sm:flex-col">
              <button
                type="button"
                onClick={() => setIsFollowing((prev) => !prev)}
                className={`inline-flex flex-1 items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors border sm:w-28 sm:flex-none sm:py-1 ${
                  isFollowing
                    ? 'bg-black text-white border-black hover:bg-gray-900'
                    : 'bg-white text-black border-black hover:bg-gray-50'
                }`}
              >
                {isFollowing ? 'Following' : 'Follow'}
              </button>
              <Link
                href={`/message/${shop.id}`}
                className="inline-flex flex-1 items-center justify-center rounded border border-black bg-white px-4 py-2 text-center text-sm font-medium text-black transition-colors hover:bg-gray-50 sm:w-28 sm:flex-none sm:py-1"
              >
                Message
              </Link>
            </div>

            <div className="flex flex-col gap-6 md:flex-row md:items-start md:gap-8">
              {/* Shop Icon and Basic Info */}
              <div className="shrink-0">
                <div className="relative z-10 -mt-20 h-28 w-28 overflow-hidden rounded-lg border-4 border-white bg-gray-100 shadow-lg sm:-mt-24 sm:h-40 sm:w-40">
                  <img
                    src={shop.profile_photo || '/images/shop/shop-icon.png'}
                    alt={shop.name}
                    className="w-full h-full object-cover"
                  />
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
                    <span className="font-semibold text-black">{shop.rating}</span>
                    <span className="text-gray-500 text-sm">({shop.total_reviews} reviews)</span>
                  </div>
                  <span className="text-gray-300">|</span>
                  <span className="text-gray-600">Est. {shop.established_year}</span>
                </div>

                <p className="text-gray-700 leading-relaxed mb-6 max-w-2xl">{profileSubtitle}</p>

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

                {/* Operating Hours */}
                {(shop.monday_open || shop.tuesday_open || shop.wednesday_open || shop.thursday_open || shop.friday_open || shop.saturday_open || shop.sunday_open) && (
                  <div className="mt-8">
                    <h3 className="text-sm font-semibold text-gray-900 uppercase tracking-wider mb-4">Operating Hours</h3>
                    <div className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2 sm:gap-4">
                      {[
                        { day: 'Monday', open: shop.monday_open, close: shop.monday_close },
                        { day: 'Tuesday', open: shop.tuesday_open, close: shop.tuesday_close },
                        { day: 'Wednesday', open: shop.wednesday_open, close: shop.wednesday_close },
                        { day: 'Thursday', open: shop.thursday_open, close: shop.thursday_close },
                        { day: 'Friday', open: shop.friday_open, close: shop.friday_close },
                        { day: 'Saturday', open: shop.saturday_open, close: shop.saturday_close },
                        { day: 'Sunday', open: shop.sunday_open, close: shop.sunday_close },
                      ].map(({ day, open, close }) => (
                        <div key={day} className="flex justify-between">
                          <span className="text-gray-600">{day}:</span>
                          <span className="font-medium text-gray-900">
                            {open && close ? `${open} - ${close}` : 'Closed'}
                          </span>
                        </div>
                      ))}
                    </div>
                  </div>
                )}
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
                {repairPackages.length > 0 && (
                  <section>
                    <h3 className="text-2xl font-bold text-black mb-6">Packages</h3>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                      {repairPackages.map((pkg) => (
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

                {repairServices.length > 0 ? (
                  <section>
                    <h3 className="text-2xl font-bold text-black mb-6">Individual Services</h3>
                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                      {repairServices.map((service) => (
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

                {(repairPackages.length > 0 || repairServices.length > 0) && (
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
                    className="group border border-gray-200 overflow-hidden hover:shadow-lg transition-all duration-300"
                    onMouseEnter={() => startImageCycle(product)}
                    onMouseLeave={() => stopImageCycle(product.id)}
                  >
                    {/* Product Image */}
                    <div className="relative h-72 bg-gray-100 overflow-hidden">
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
                                  ? 'opacity-100 scale-100 group-hover:scale-105'
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
                      {product.stock_quantity === 0 && (
                        <div className="absolute inset-0 bg-black bg-opacity-50 flex items-center justify-center">
                          <span className="bg-red-600 text-white px-4 py-2 text-sm font-semibold">OUT OF STOCK</span>
                        </div>
                      )}
                    </div>

                    {/* Product Info */}
                    <div className="p-4">
                      {product.brand && (
                        <p className="text-xs text-gray-500 uppercase tracking-wider mb-1">{product.brand}</p>
                      )}
                      <h3 className="text-lg font-bold text-black mb-2 line-clamp-2">
                        {product.name}
                      </h3>

                      {product.description && (
                        <p className="text-xs text-gray-600 mb-2 line-clamp-2">{product.description}</p>
                      )}

                      {/* Stock Info */}
                      <div className="mb-2">
                        <span className={`text-xs font-medium ${product.stock_quantity > 0 ? 'text-green-600' : 'text-red-600'}`}>
                          {product.stock_quantity > 0 ? `${product.stock_quantity} in stock` : 'Out of stock'}
                        </span>
                      </div>

                      {/* Price */}
                      <div className="pt-3 border-t border-gray-200">
                        {product.compare_at_price && (
                          <p className="text-sm text-gray-400 line-through">₱{product.compare_at_price.toLocaleString()}</p>
                        )}
                        <p className="text-2xl font-bold text-black">₱{product.price.toLocaleString()}</p>
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
      <footer className="mt-16 bg-white border-t border-gray-100">
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
