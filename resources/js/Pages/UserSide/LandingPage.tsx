import React from 'react';
import { Link, Head } from '@inertiajs/react';
import Navigation from './Navigation';

interface Product {
  id: number;
  name: string;
  slug: string;
  description: string;
  price: number;
  compare_at_price: number | null;
  main_image: string;
  stock_quantity: number;
  shop_owner: {
    id: number;
    business_name: string;
  };
}

interface Props {
  products: Product[];
}

const LandingPage: React.FC<Props> = ({ products = [] }) => {
  return (
    <>
      <Head title="SoleSpace - Premium Footwear & Expert Repairs" />
      <div className="min-h-screen bg-white font-outfit antialiased">
        <Navigation />

      {/* Hero Section - Adidas Style: Full Width, Bold Typography */}
      <section className="relative w-full min-h-[90vh] bg-white flex items-center">
        <div className="w-full max-w-[1920px] mx-auto px-6 lg:px-12 py-20 lg:py-32">
          <div className="max-w-4xl">
            <h1 className="text-6xl lg:text-8xl xl:text-9xl font-bold text-black mb-8 leading-[0.9] tracking-tight">
              STEP INTO
              <span className="block">EXCELLENCE</span>
            </h1>
            <p className="text-xl lg:text-2xl text-black/70 mb-12 max-w-2xl leading-relaxed font-light">
              Discover premium footwear and expert repair services in one integrated platform designed for modern lifestyles.
            </p>
            <div className="flex flex-col sm:flex-row gap-4">
              <Link
                href={route("products")}
                className="px-10 py-4 bg-black text-white font-semibold uppercase tracking-wider text-sm hover:bg-black/80 transition-colors inline-flex items-center justify-center gap-3"
              >
                Shop Collection
                <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 8l4 4m0 0l-4 4m4-4H3" />
                </svg>
              </Link>
              <Link
                href={route("repair")}
                className="px-10 py-4 bg-white border-2 border-black text-black font-semibold uppercase tracking-wider text-sm hover:bg-black hover:text-white transition-all inline-flex items-center justify-center gap-3"
              >
                Repair Services
                <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
              </Link>
            </div>
          </div>
        </div>
      </section>

      {/* Stats Section - Adidas Style: Clean, Bold Numbers */}
      <section className="w-full bg-gray-100 text-black py-20">
        <div className="max-w-[1920px] mx-auto px-6 lg:px-12">
          <div className="grid grid-cols-3 gap-12 lg:gap-20 text-center">
            <div>
              <div className="text-6xl lg:text-7xl font-bold mb-4">500+</div>
              <div className="text-sm uppercase tracking-wider text-black/70">Products</div>
            </div>
            <div>
              <div className="text-6xl lg:text-7xl font-bold mb-4">10K+</div>
              <div className="text-sm uppercase tracking-wider text-black/70">Customers</div>
            </div>
            <div>
              <div className="text-6xl lg:text-7xl font-bold mb-4">98%</div>
              <div className="text-sm uppercase tracking-wider text-black/70">Satisfaction</div>
            </div>
          </div>
        </div>
      </section>

      {/* Features Section - Adidas Style: Minimal, Clean */}
      <section className="w-full bg-white py-24 lg:py-32">
        <div className="max-w-[1920px] mx-auto px-6 lg:px-12">
          <div className="grid grid-cols-1 md:grid-cols-3 gap-16 lg:gap-24">
            <div className="text-center">
              <div className="w-20 h-20 border-2 border-black rounded-full flex items-center justify-center mx-auto mb-8">
                <svg className="w-10 h-10 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M5 13l4 4L19 7" />
                </svg>
              </div>
              <h3 className="text-2xl font-bold text-black mb-4 uppercase tracking-wide">Quality Assured</h3>
              <p className="text-black/70 leading-relaxed">Premium materials and craftsmanship guaranteed</p>
            </div>
            <div className="text-center">
              <div className="w-20 h-20 border-2 border-black rounded-full flex items-center justify-center mx-auto mb-8">
                <svg className="w-10 h-10 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
              </div>
              <h3 className="text-2xl font-bold text-black mb-4 uppercase tracking-wide">Fast Delivery</h3>
              <p className="text-black/70 leading-relaxed">Quick and reliable shipping to your doorstep</p>
            </div>
            <div className="text-center">
              <div className="w-20 h-20 border-2 border-black rounded-full flex items-center justify-center mx-auto mb-8">
                <svg className="w-10 h-10 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                </svg>
              </div>
              <h3 className="text-2xl font-bold text-black mb-4 uppercase tracking-wide">Secure Payment</h3>
              <p className="text-black/70 leading-relaxed">Safe and encrypted transaction processing</p>
            </div>
          </div>
        </div>
      </section>

      {/* Featured Products Section - Adidas Style: Large, Bold */}
      <section className="w-full bg-white py-24 lg:py-32">
        <div className="max-w-[1920px] mx-auto px-6 lg:px-12">
          <div className="mb-20">
            <h2 className="text-5xl lg:text-7xl font-bold text-black mb-6 tracking-tight">
              PREMIUM FOOTWEAR
            </h2>
            <p className="text-xl text-black/70 max-w-2xl leading-relaxed font-light">
              Discover our handpicked selection of high-quality shoes designed for comfort, style, and performance.
            </p>
          </div>
          <div className="grid grid-cols-1 md:grid-cols-3 gap-8 lg:gap-12">
            {products.length > 0 ? (
              products.map((product, index) => (
                <Link
                  key={product.id}
                  href={route('products.show', product.slug)}
                  className="group bg-white border-2 border-black overflow-hidden hover:shadow-2xl transition-all duration-300"
                >
                  <div className="relative bg-white overflow-hidden aspect-square">
                    <img
                      src={product.main_image || './images/product/default.jpg'}
                      alt={product.name}
                      className="w-full h-full object-cover group-hover:scale-110 transition-transform duration-700"
                      loading="lazy"
                    />
                    {index === 0 && (
                      <div className="absolute top-6 right-6 bg-black text-white px-4 py-2 text-xs font-bold uppercase tracking-wider">
                        New
                      </div>
                    )}
                    {product.stock_quantity === 0 && (
                      <div className="absolute inset-0 bg-black/70 flex items-center justify-center">
                        <span className="text-white text-2xl font-bold uppercase tracking-wider">Out of Stock</span>
                      </div>
                    )}
                  </div>
                  <div className="p-8 border-t-2 border-black">
                    <h3 className="text-2xl font-bold text-black mb-3 uppercase tracking-wide line-clamp-1">{product.name}</h3>
                    <p className="text-black/70 mb-6 text-sm leading-relaxed line-clamp-2">
                      {product.description || 'Premium footwear designed for comfort and style.'}
                    </p>
                    <div className="flex justify-between items-center">
                      <div className="flex flex-col">
                        <span className="text-3xl font-bold text-black">₱{product.price.toLocaleString()}</span>
                        {product.compare_at_price && product.compare_at_price > product.price && (
                          <span className="text-sm text-black/50 line-through">₱{product.compare_at_price.toLocaleString()}</span>
                        )}
                      </div>
                      <div className="px-6 py-3 bg-black text-white font-semibold uppercase tracking-wider text-xs hover:bg-black/80 transition-colors">
                        View
                      </div>
                    </div>
                  </div>
                </Link>
              ))
            ) : (
              <div className="col-span-3 text-center py-12">
                <p className="text-black/50 text-lg">No products available at the moment.</p>
              </div>
            )}
          </div>
          <div className="text-center mt-16">
            <Link
              href={route("products")}
              className="inline-flex items-center gap-3 px-10 py-4 bg-black text-white font-semibold uppercase tracking-wider text-sm hover:bg-black/80 transition-colors"
            >
              View All Products
              <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 8l4 4m0 0l-4 4m4-4H3" />
              </svg>
            </Link>
          </div>
        </div>
      </section>

      {/* Services Section - Adidas Style: Full Width Split */}
      <section className="w-full bg-gray-100 text-black py-24 lg:py-32">
        <div className="max-w-[1920px] mx-auto px-6 lg:px-12">
          <div className="mb-20">
            <h2 className="text-5xl lg:text-7xl font-bold text-black mb-6 tracking-tight">
              COMPLETE SHOE SOLUTIONS
            </h2>
            <p className="text-xl text-black/70 max-w-2xl leading-relaxed font-light">
              From retail excellence to expert repairs, we provide comprehensive footwear services tailored to your needs.
            </p>
          </div>
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-12 lg:gap-16">
            <div className="bg-white text-black p-12 border-2 border-gray-300">
              <div className="w-16 h-16 border-2 border-black rounded-full flex items-center justify-center mb-8">
                <svg className="w-8 h-8 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
                </svg>
              </div>
              <h3 className="text-3xl font-bold text-black mb-6 uppercase tracking-wide">Premium Retail</h3>
              <p className="text-black/70 mb-8 leading-relaxed text-lg">
                Discover an extensive collection of high-quality footwear from renowned brands. Our integrated platform offers personalized recommendations, competitive pricing, and seamless shopping experiences.
              </p>
                <Link
                  href={route("products")}
                  className="inline-flex items-center gap-3 text-black font-semibold uppercase tracking-wider text-sm hover:opacity-70 transition-opacity border-b-2 border-black pb-1"
                >
                Explore Collection
                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 8l4 4m0 0l-4 4m4-4H3" />
                </svg>
              </Link>
            </div>
            <div className="bg-white text-black p-12 border-2 border-gray-300">
              <div className="w-16 h-16 border-2 border-black rounded-full flex items-center justify-center mb-8">
                <svg className="w-8 h-8 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
              </div>
              <h3 className="text-3xl font-bold text-black mb-6 uppercase tracking-wide">Expert Repairs</h3>
              <p className="text-black/70 mb-8 leading-relaxed text-lg">
                Professional shoe repair services powered by intelligent decision support systems. Get expert recommendations for the best repair options that extend the life of your favorite footwear.
              </p>
                <Link
                  href={route("repair")}
                  className="inline-flex items-center gap-3 text-black font-semibold uppercase tracking-wider text-sm hover:opacity-70 transition-opacity border-b-2 border-black pb-1"
                >
                Learn More
                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 8l4 4m0 0l-4 4m4-4H3" />
                </svg>
              </Link>
            </div>
          </div>
        </div>
      </section>

      {/* CTA Section - Adidas Style: Bold, Full Width */}
      <section className="w-full bg-white py-24 lg:py-32">
        <div className="max-w-[1920px] mx-auto px-6 lg:px-12 text-center">
          <h2 className="text-5xl lg:text-7xl font-bold text-black mb-8 tracking-tight">
            READY TO STEP INTO STYLE?
          </h2>
          <p className="text-xl text-black/70 mb-12 max-w-2xl mx-auto leading-relaxed font-light">
            Join thousands of satisfied customers and discover the perfect pair today.
          </p>
          <div className="flex flex-col sm:flex-row justify-center gap-4">
            <Link
              href={route("products")}
              className="px-10 py-4 bg-black text-white font-semibold uppercase tracking-wider text-sm hover:bg-black/80 transition-colors"
            >
              Shop Now
            </Link>
            <Link
              href={route("repair")}
              className="px-10 py-4 bg-white border-2 border-black text-black font-semibold uppercase tracking-wider text-sm hover:bg-black hover:text-white transition-all"
            >
              Book Repair
            </Link>
          </div>
        </div>
      </section>

      {/* Footer - Adidas Style: Clean, Minimal */}
      <footer className="w-full bg-gray-100 text-black py-16">
        <div className="max-w-[1920px] mx-auto px-6 lg:px-12">
          <div className="grid grid-cols-1 md:grid-cols-4 gap-12 mb-12">
            <div className="md:col-span-2">
              <Link href={route("landing")} className="text-2xl font-bold text-black mb-6 inline-block">
                SoleSpace
              </Link>
              <p className="text-black/60 mb-8 max-w-md leading-relaxed">
                Your premier destination for premium footwear and expert repair services. Experience the perfect blend of style, comfort, and craftsmanship.
              </p>
              <div className="flex gap-4">
                <a href="#" className="w-12 h-12 border-2 border-black/20 hover:border-black transition-colors flex items-center justify-center" aria-label="Facebook">
                  <svg className="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z" />
                  </svg>
                </a>
                <a href="#" className="w-12 h-12 border-2 border-black/20 hover:border-black transition-colors flex items-center justify-center" aria-label="Twitter">
                  <svg className="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M23.953 4.57a10 10 0 01-2.825.775 4.958 4.958 0 002.163-2.723c-.951.555-2.005.959-3.127 1.184a4.92 4.92 0 00-8.384 4.482C7.69 8.095 4.067 6.13 1.64 3.162a4.822 4.822 0 00-.666 2.475c0 1.71.87 3.213 2.188 4.096a4.904 4.904 0 01-2.228-.616v.06a4.923 4.923 0 003.946 4.827 4.996 4.996 0 01-2.212.085 4.936 4.936 0 004.604 3.417 9.867 9.867 0 01-6.102 2.105c-.39 0-.779-.023-1.17-.067a13.995 13.995 0 007.557 2.209c9.053 0 13.998-7.496 13.998-13.985 0-.21 0-.42-.015-.63A9.935 9.935 0 0024 4.59z" />
                  </svg>
                </a>
                <a href="#" className="w-12 h-12 border-2 border-black/20 hover:border-black transition-colors flex items-center justify-center" aria-label="Instagram">
                  <svg className="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M12 0C8.74 0 8.333.015 7.053.072 5.775.132 4.905.333 4.14.63c-.789.306-1.459.717-2.126 1.384S.935 3.35.63 4.14C.333 4.905.131 5.775.072 7.053.012 8.333 0 8.74 0 12s.015 3.667.072 4.947c.06 1.277.261 2.148.558 2.913.306.788.717 1.459 1.384 2.126.667.666 1.336 1.079 2.126 1.384.766.296 1.636.499 2.913.558C8.333 23.988 8.74 24 12 24s3.667-.015 4.947-.072c1.277-.06 2.148-.262 2.913-.558.788-.306 1.459-.718 2.126-1.384.666-.667 1.079-1.335 1.384-2.126.296-.765.499-1.636.558-2.913.06-1.28.072-1.687.072-4.947s-.015-3.667-.072-4.947c-.06-1.277-.262-2.149-.558-2.913-.306-.789-.718-1.459-1.384-2.126C21.319 1.347 20.651.935 19.86.63c-.765-.297-1.636-.499-2.913-.558C15.667.012 15.26 0 12 0zm0 2.16c3.203 0 3.585.016 4.85.071 1.17.055 1.805.249 2.227.415.562.217.96.477 1.382.896.419.42.679.819.896 1.381.164.422.36 1.057.413 2.227.057 1.266.07 1.646.07 4.85s-.015 3.585-.074 4.85c-.061 1.17-.256 1.805-.413 2.227-.217.562-.477.96-.896 1.382-.42.419-.824.679-1.38.896-.42.164-1.065.36-2.235.413-1.274.057-1.649.07-4.859.07-3.211 0-3.586-.015-4.859-.074-1.171-.061-1.816-.256-2.236-.413-.569-.217-.96-.477-1.379-.896-.421-.419-.69-.824-.9-1.38-.165-.42-.359-1.065-.42-2.235-.045-1.26-.061-1.649-.061-4.844 0-3.196.016-3.586.061-4.861.061-1.17.255-1.816.42-2.236.21-.57.479-.96.9-1.381.419-.419.81-.689 1.379-.9.42-.166 1.051-.361 2.221-.421 1.275-.045 1.65-.06 4.859-.06l.045.03zm0 3.678c-3.405 0-6.162 2.76-6.162 6.162 0 3.405 2.76 6.162 6.162 6.162 3.405 0 6.162-2.76 6.162-6.162 0-3.405-2.76-6.162-6.162-6.162zM12 16c-2.21 0-4-1.79-4-4s1.79-4 4-4 4 1.79 4 4-1.79 4-4 4zm7.846-10.405c0 .795-.646 1.44-1.44 1.44-.795 0-1.44-.646-1.44-1.44 0-.794.646-1.439 1.44-1.439.793-.001 1.44.645 1.44 1.439z" />
                  </svg>
                </a>
              </div>
            </div>
            <div>
              <h4 className="text-sm font-bold uppercase tracking-wider mb-6">Quick Links</h4>
              <ul className="space-y-4">
                <li><Link href={route("products")} className="text-black/60 hover:text-black transition-colors text-sm">Products</Link></li>
                <li><Link href={route("repair")} className="text-black/60 hover:text-black transition-colors text-sm">Repair Services</Link></li>
                <li><Link href={route("services")} className="text-black/60 hover:text-black transition-colors text-sm">Services</Link></li>
                <li><Link href={route("contact")} className="text-black/60 hover:text-black transition-colors text-sm">Contact</Link></li>
              </ul>
            </div>
            <div>
              <h4 className="text-sm font-bold uppercase tracking-wider mb-6">Services</h4>
              <ul className="space-y-4">
                <li><a href="#" className="text-black/60 hover:text-black transition-colors text-sm">Shoe Repair</a></li>
                <li><a href="#" className="text-black/60 hover:text-black transition-colors text-sm">Custom Fitting</a></li>
                <li><a href="#" className="text-black/60 hover:text-black transition-colors text-sm">Maintenance</a></li>
                <li><a href="#" className="text-black/60 hover:text-black transition-colors text-sm">Consultation</a></li>
              </ul>
            </div>
            <div>
              <h4 className="text-sm font-bold uppercase tracking-wider mb-6">Support</h4>
              <ul className="space-y-4">
                <li><a href="#" className="text-black/60 hover:text-black transition-colors text-sm">Help Center</a></li>
                <li><a href="#" className="text-black/60 hover:text-black transition-colors text-sm">FAQ</a></li>
                <li><a href="#" className="text-black/60 hover:text-black transition-colors text-sm">Privacy Policy</a></li>
                <li><a href="#" className="text-black/60 hover:text-black transition-colors text-sm">Terms of Service</a></li>
              </ul>
            </div>
          </div>
          <div className="border-t border-black/10 pt-8">
            <div className="flex flex-col md:flex-row justify-between items-center gap-4">
              <p className="text-black/60 text-sm">&copy; 2024 SoleSpace. All rights reserved.</p>
              <div className="flex items-center gap-8 text-sm text-black/60">
                <a href="#" className="hover:text-black transition-colors">Privacy</a>
                <a href="#" className="hover:text-black transition-colors">Terms</a>
                <a href="#" className="hover:text-black transition-colors">Cookies</a>
              </div>
            </div>
          </div>
        </div>
        </footer>
      </div>
    </>
  );
};

export default LandingPage;
