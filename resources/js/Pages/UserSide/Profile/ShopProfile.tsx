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

interface Shop {
  id: number;
  name: string;
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
}

const ShopProfile: React.FC<Props> = ({ shop, products }) => {
  const { auth } = usePage().props as any;
  const isAuthenticated = !!auth?.user;
  const [isFollowing, setIsFollowing] = useState<boolean>(false);
  const [showReportModal, setShowReportModal] = useState(false);
  const [selectedCategory, setSelectedCategory] = useState<string>('Shoes');
  const [activeImageIndexes, setActiveImageIndexes] = useState<Record<number, number>>({});
  const hoverTimersRef = useRef<Record<number, number>>({});
  const categories = ['Shoes', 'Men', 'Women', 'Kids', 'Sports', 'Services'];

  const filteredProducts = products.filter((product) => {
    if (selectedCategory === 'Shoes') {
      return true;
    }

    const normalizedCategory = (product.category || '').toLowerCase();
    const categoryParts = normalizedCategory
      .split(',')
      .map((item) => item.trim())
      .filter(Boolean);

    if (selectedCategory === 'Services') {
      return categoryParts.some((item) => item.includes('service') || item.includes('repair'));
    }

    const target = selectedCategory.toLowerCase();
    return categoryParts.some((item) => item === target || item.includes(target));
  });

  useEffect(() => {
    return () => {
      Object.values(hoverTimersRef.current).forEach((timerId) => window.clearInterval(timerId));
      hoverTimersRef.current = {};
    };
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
    <div className="min-h-screen flex flex-col bg-white">
      <Head title={shop.name} />
      <Navigation />

      <main className="flex-1">
        {/* Cover Image Section */}
        <div className="relative h-64 md:h-80 bg-gray-200 overflow-hidden">
          <img
            src={shop.cover_image}
            alt="Shop Cover"
            className="w-full h-full object-cover"
          />
          <div className="absolute inset-0 bg-black bg-opacity-10"></div>
        </div>

        {/* Shop Profile Section */}
        <div className="bg-white border-b border-gray-200 relative">
          <div className="max-w-6xl mx-auto px-6 py-12">
            {/* Follow + Message Buttons */}
            <div className="absolute top-6 right-6 flex flex-col gap-2">
              <button
                type="button"
                onClick={() => setIsFollowing((prev) => !prev)}
                className={`inline-block px-4 py-1 rounded text-sm font-medium transition-colors border ${
                  isFollowing
                    ? 'bg-black text-white border-black hover:bg-gray-900'
                    : 'bg-white text-black border-black hover:bg-gray-50'
                }`}
              >
                {isFollowing ? 'Following' : 'Follow'}
              </button>
              <Link
                href={`/message/${shop.id}`}
                className="inline-block bg-white text-black border border-black px-4 py-1 rounded text-sm font-medium hover:bg-gray-50 transition-colors"
              >
                Message
              </Link>
              {isAuthenticated && (
                <button
                  type="button"
                  onClick={() => setShowReportModal(true)}
                  className="inline-block bg-white text-red-600 border border-red-300 px-4 py-1 rounded text-sm font-medium hover:bg-red-50 transition-colors"
                >
                  Report Shop
                </button>
              )}
            </div>

            <div className="flex flex-col md:flex-row gap-8 items-start">
              {/* Shop Icon and Basic Info */}
              <div className="flex-shrink-0">
                <div className="w-40 h-40 rounded-lg border-4 border-white shadow-lg bg-gray-100 overflow-hidden -mt-24 relative z-10">
                  <img
                    src={shop.profile_photo || '/images/shop/shop-icon.png'}
                    alt={shop.name}
                    className="w-full h-full object-cover"
                  />
                </div>
              </div>

              {/* Shop Details */}
              <div className="flex-1">
                <h1 className="text-4xl font-bold text-black mb-3">{shop.name}</h1>
                
                <div className="flex items-center gap-4 mb-6">
                  <div className="flex items-center gap-1">
                    <span className="text-yellow-400 text-lg">★</span>
                    <span className="font-semibold text-black">{shop.rating}</span>
                    <span className="text-gray-500 text-sm">({shop.total_reviews} reviews)</span>
                  </div>
                  <span className="text-gray-300">|</span>
                  <span className="text-gray-600">Est. {shop.established_year}</span>
                </div>

                <p className="text-gray-700 leading-relaxed mb-6 max-w-2xl">
                  {shop.description}
                </p>

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
                    <div className="grid grid-cols-2 gap-4 text-sm">
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
        <div className="max-w-6xl mx-auto py-16 px-6">
          <div className="mb-12">
            <h2 className="text-3xl font-bold text-black mb-8">Featured Shoes</h2>

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
              <Link
                href={`/shop-profile/${shop.id}/virtual-showroom`}
                className="text-sm font-medium tracking-wide uppercase whitespace-nowrap transition-all text-gray-500 hover:text-black pb-1"
              >
                Virtual Showroom
              </Link>
            </div>

            {/* Products Grid */}
            {filteredProducts.length > 0 ? (
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
        <div className="max-w-6xl mx-auto px-6 py-16">
          <div className="grid grid-cols-1 md:grid-cols-3 gap-12">
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
          <div className="border-t border-gray-100 mt-12 pt-8 text-xs text-gray-400 flex items-center justify-between">
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
