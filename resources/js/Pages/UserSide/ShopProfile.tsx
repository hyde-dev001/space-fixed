import React, { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import Navigation from './Navigation';

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
  description?: string;
}

interface Shop {
  id: number;
  name: string;
  cover_image: string;
  profile_icon: string;
  description: string;
  address: string;
  phone: string;
  email: string;
  rating: number;
  total_reviews: number;
  established_year: number;
}

interface Props {
  shop: Shop;
  products: Product[];
}

const ShopProfile: React.FC<Props> = ({ shop, products }) => {
  const [selectedCategory, setSelectedCategory] = useState<string>('Shoes');

  const categories = ['Shoes', 'Men', 'Women', 'Kids', 'Sports', 'Repair Services'];
  
  const filteredProducts = selectedCategory === 'Shoes'
    ? products
    : [];

  return (
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
            {/* Message Button */}
            <div className="absolute top-6 right-6">
              <Link
                href="/shop/message"
                className="inline-block bg-white text-black border border-black px-4 py-1 rounded text-sm font-medium hover:bg-gray-50 transition-colors"
              >
                Message
              </Link>
            </div>

            <div className="flex flex-col md:flex-row gap-8 items-start">
              {/* Shop Icon and Basic Info */}
              <div className="flex-shrink-0">
                <div className="w-40 h-40 rounded-lg border-4 border-white shadow-lg bg-gray-100 overflow-hidden -mt-24 relative z-10">
                  <img
                    src={shop.profile_icon}
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
                    <p className="text-sm text-black font-medium">{shop.address}</p>
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
        <div className="max-w-6xl mx-auto py-16 px-6">
          <div className="mb-12">
            <h2 className="text-3xl font-bold text-black mb-8">Featured Shoes</h2>

            {/* Category Filter */}
            <div className="flex gap-8 mb-10 overflow-x-auto pb-2">
              {categories.map((category) => (
                <a
                  key={category}
                  onClick={() => setSelectedCategory(category)}
                  className={`text-sm font-medium tracking-wide uppercase whitespace-nowrap cursor-pointer transition-all ${
                    selectedCategory === category
                      ? 'text-black border-b-2 border-black pb-1'
                      : 'text-gray-500 hover:text-black pb-1'
                  }`}
                >
                  {category}
                </a>
              ))}
            </div>

            {/* Products Grid */}
            {filteredProducts.length > 0 ? (
              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                {filteredProducts.map((product) => (
                  <Link
                    key={product.id}
                    href={`/products/${product.slug}`}
                    className="group border border-gray-200 overflow-hidden hover:shadow-lg transition-all duration-300"
                  >
                    {/* Product Image */}
                    <div className="relative h-64 bg-gray-100 overflow-hidden">
                      <img
                        src={product.main_image}
                        alt={product.name}
                        className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
                      />
                      <div className="absolute top-4 right-4">
                        <span className="bg-black text-white px-3 py-1 text-xs font-semibold uppercase tracking-wider">
                          {product.category}
                        </span>
                      </div>
                      {product.stock_quantity === 0 && (
                        <div className="absolute inset-0 bg-black bg-opacity-50 flex items-center justify-center">
                          <span className="bg-red-600 text-white px-4 py-2 text-sm font-semibold">OUT OF STOCK</span>
                        </div>
                      )}
                    </div>

                    {/* Product Info */}
                    <div className="p-6">
                      {product.brand && (
                        <p className="text-xs text-gray-500 uppercase tracking-wider mb-2">{product.brand}</p>
                      )}
                      <h3 className="text-lg font-bold text-black mb-3 line-clamp-2">
                        {product.name}
                      </h3>

                      {product.description && (
                        <p className="text-xs text-gray-600 mb-3 line-clamp-2">{product.description}</p>
                      )}

                      {/* Stock Info */}
                      <div className="mb-3">
                        <span className={`text-xs font-medium ${product.stock_quantity > 0 ? 'text-green-600' : 'text-red-600'}`}>
                          {product.stock_quantity > 0 ? `${product.stock_quantity} in stock` : 'Out of stock'}
                        </span>
                      </div>

                      {/* Price */}
                      <div className="pt-4 border-t border-gray-200">
                        {product.compare_at_price && (
                          <p className="text-sm text-gray-400 line-through">₱{product.compare_at_price.toLocaleString()}</p>
                        )}
                        <p className="text-2xl font-bold text-black">₱{product.price.toLocaleString()}</p>
                      </div>
                    </div>
                  </Link>
                ))}
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
  );
};

export default ShopProfile;
