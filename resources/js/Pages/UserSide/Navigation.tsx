import React, { useState, useEffect, useRef } from 'react';
import { Link, usePage, router } from '@inertiajs/react';
import Swal from 'sweetalert2';
import { route } from 'ziggy-js';
import { useCart } from '../../contexts/CartContext';
import { dispatchCartAddedEvent } from '../../types/cart-events';

const Navigation: React.FC = () => {
  const { cartCount, isLoading: cartLoading } = useCart();
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
  const [userDropdownOpen, setUserDropdownOpen] = useState(false);
  const [searchQuery, setSearchQuery] = useState('');
  const [underlineTranslateX, setUnderlineTranslateX] = useState(0);
  const [underlineWidth, setUnderlineWidth] = useState(0);
  const [previousActiveIndex, setPreviousActiveIndex] = useState(-1);
  const [openDropdownKey, setOpenDropdownKey] = useState<string | null>(null);
  const [hoveredIndex, setHoveredIndex] = useState<number | null>(null);
  const [isHovering, setIsHovering] = useState(false);
  const page = usePage();
  const { url } = page;
  const { auth } = page.props as any;
  
  // Check if user is authenticated and is a regular customer (not ERP staff)
  const user = auth?.user;
  const userRole = user?.role?.toUpperCase();
  const isERPStaff = userRole && ['HR', 'FINANCE_STAFF', 'FINANCE_MANAGER', 'FINANCE', 'CRM', 'MANAGER', 'STAFF'].includes(userRole);
  const isAuthenticated = Boolean(user && !isERPStaff);

  // Shoe categories for dropdown
  const shoeCategories = [
    { name: 'Running', description: 'Running shoes with responsive cushioning' },
    { name: 'Basketball', description: 'High-performance basketball shoes' },
    { name: 'Training', description: 'Versatile training and gym shoes' },
    { name: 'Casual', description: 'Everyday sneakers and casual wear' },
    { name: 'Football', description: 'Soccer and football boots' },
    { name: 'Slides', description: 'Comfort slides and sandals' },
    { name: 'Tennis', description: 'Court shoes for tennis' },
    { name: 'Loafers', description: 'Formal and casual loafers' },
  ];

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault();
    if (searchQuery.trim()) {
      router.visit(route('products', { search: searchQuery.trim() }));
    }
  };

  const handleLogout = async () => {
    const result = await Swal.fire({
      title: 'Are you sure?',
      text: 'You will be logged out of your account.',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Log out',
      cancelButtonText: 'Cancel',
    });

    if (!result.isConfirmed) return;

    try {
      // Clear cart from localStorage
      localStorage.removeItem('ss_cart');
      
      // Dispatch event to update cart count
      try { dispatchCartAddedEvent({ total: 0 }); } catch (e) {}
      
      // Use Inertia router for proper logout
      router.post('/user/logout', {}, {
        preserveState: false,
        preserveScroll: false,
        onSuccess: () => {
          Swal.fire('Logged out', 'You have been logged out successfully.', 'success');
        },
        onError: () => {
          Swal.fire('Error', 'Logout failed. Please try again.', 'error');
        },
      });
    } catch (e) {
      Swal.fire('Error', 'Logout failed. Please try again.', 'error');
    }
  };
  const navRef = useRef<HTMLDivElement>(null);
  const dropdownRef = useRef<HTMLDivElement>(null);
  const hoverCloseTimeoutRef = useRef<number | null>(null);
  const megaMenuBaseClasses =
    'absolute top-full left-1/2 -translate-x-1/2 mt-0 bg-white text-black shadow-2xl rounded-none w-auto min-w-[700px] max-w-[900px] py-8 px-10 border-t border-gray-200 transition-all duration-200 ease-out';
  const megaMenuHiddenClasses = 'opacity-0 translate-y-2 pointer-events-none';
  const megaMenuVisibleClasses = 'opacity-100 translate-y-0 pointer-events-auto';

  const navItems = [
    { route: 'landing', label: 'Home' },
    { route: 'products', label: 'Shoes', dropdownKey: 'shoes' },
    { route: 'products', label: 'Men', params: { category: 'men' }, dropdownKey: 'men' },
    { route: 'products', label: 'Women', params: { category: 'women' }, dropdownKey: 'women' },
    { route: 'products', label: 'Kids', params: { category: 'kids' }, dropdownKey: 'kids' },
    { route: 'products', label: 'Sports', params: { category: 'sports' }, dropdownKey: 'sports' },
    { route: 'repair', label: 'Repair' },
    ...(isAuthenticated ? [] : [{ route: 'services', label: 'Services' }]),
    ...(isAuthenticated ? [] : [{ route: 'login', label: 'ACCOUNT' }])
  ];

  let activeIndex = -1;

  // Map URLs to nav items
  const urlToRouteMap: Record<string, string> = {
    '/': 'landing',
    '/products': 'products',
    '/repair-services': 'repair',
    '/services': 'services',
    '/register': 'login', // Register page should highlight ACCOUNT
    '/login': 'login', // Login page should highlight ACCOUNT
    '/user/login': 'login', // User login page should highlight ACCOUNT
    '/shop-owner/login': 'login', // Shop Owner Login should highlight ACCOUNT
    '/shop-owner-register': 'services', // Shop Owner Registration should highlight Services
    '/shop/register': 'services', // Alternative Shop Owner Registration URL
    '/shop-owner/register': 'services' // Another possible URL
  };

  const cleanUrl = url.split('?')[0]; // Remove query params
  let currentRoute = urlToRouteMap[url] || urlToRouteMap[cleanUrl];
  // Treat product detail pages (e.g. /products/product-02) as the `products` route
  if (!currentRoute && cleanUrl.startsWith('/products')) {
    currentRoute = 'products';
  }
  // Treat repair shop detail pages (e.g. /repair-shop/2) as the `repair` route
  if (!currentRoute && cleanUrl.startsWith('/repair-shop')) {
    currentRoute = 'repair';
  }
  if (currentRoute) {
    activeIndex = navItems.findIndex(item => item.route === currentRoute);
  } else if (url.includes('shop-owner') && url.includes('register')) {
    // Special case for shop owner registration pages
    activeIndex = navItems.findIndex(item => item.route === 'services');
  }

  // Function to update underline position
  const updateUnderlinePosition = (index: number) => {
    if (navRef.current && index !== -1) {
      const navChildren = Array.from(navRef.current.children);
      const targetChild = navChildren[index] as HTMLElement;

      if (targetChild) {
        const targetLink = targetChild.tagName === 'A' ? targetChild : targetChild.querySelector('a');
        
        if (targetLink) {
          const rect = targetLink.getBoundingClientRect();
          const navRect = navRef.current.getBoundingClientRect();

          const newTranslateX = rect.left - navRect.left;
          const newWidth = rect.width;

          setUnderlineTranslateX(newTranslateX);
          setUnderlineWidth(newWidth);
        }
      }
    }
  };

  // Update underline for active page on mount and URL changes
  useEffect(() => {
    if (!isHovering && activeIndex !== -1) {
      updateUnderlinePosition(activeIndex);
      setPreviousActiveIndex(activeIndex);
    }
  }, [activeIndex, url, isHovering]);

  // Handle hover state changes
  useEffect(() => {
    if (isHovering && hoveredIndex !== null) {
      updateUnderlinePosition(hoveredIndex);
    } else if (!isHovering && activeIndex !== -1) {
      updateUnderlinePosition(activeIndex);
    }
  }, [isHovering, hoveredIndex, activeIndex]);

  // Handle mouse enter on nav items
  const handleNavItemMouseEnter = (index: number, dropdownKey?: string) => {
    setHoveredIndex(index);
    setIsHovering(true);
    setOpenDropdownKey(dropdownKey || null);
  };

  const handleNavAreaMouseLeave = () => {
    setIsHovering(false);
    setHoveredIndex(null);
    setOpenDropdownKey(null);
  };

  // Close dropdown when clicking outside
  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (dropdownRef.current && !dropdownRef.current.contains(event.target as Node)) {
        setUserDropdownOpen(false);
      }
    };

    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  return (
    <nav className="bg-white border-b border-transparent sticky top-0 z-50">
      <div className="max-w-[1920px] mx-auto px-6 lg:px-12">
        <div className="flex items-center justify-center h-20 relative">
          <Link href={route("landing")} className="text-2xl font-bold text-black tracking-tight hover:opacity-70 transition-opacity absolute left-0">
            SoleSpace
          </Link>
          <div
            className="hidden md:flex items-center space-x-10 relative"
            ref={navRef}
            onMouseLeave={handleNavAreaMouseLeave}
          >
            {navItems.map((item, index) => {
              if (item.dropdownKey) {
                return (
                  <div
                    key={`${item.route}-${item.label}`}
                    onMouseEnter={() => {
                      handleNavItemMouseEnter(index, item.dropdownKey);
                    }}
                    className="relative flex items-center h-full"
                  >
                    <Link
                      href={route(item.route)}
                      aria-current={activeIndex === index ? "page" : undefined}
                      className={`text-sm uppercase tracking-wider leading-none transition-all duration-300 ease-in-out pb-2 inline-flex items-center ${
                        activeIndex === index
                          ? 'font-semibold text-gray-900'
                          : 'font-medium text-gray-500 hover:text-gray-700'
                      }`}
                    >
                      {item.label}
                    </Link>

                    <div
                      className={`${megaMenuBaseClasses} ${
                        openDropdownKey === item.dropdownKey
                          ? megaMenuVisibleClasses
                          : megaMenuHiddenClasses
                      }`}
                      aria-hidden={openDropdownKey !== item.dropdownKey}
                    >
                      {item.dropdownKey === 'shoes' && (
                        <div className="grid grid-cols-3 gap-x-10">
                          <div className="border-r border-gray-200 pr-8">
                            <h3 className="font-bold text-sm mb-4 tracking-wider">NEW & TRENDING</h3>
                            <ul className="space-y-3">
                              <li><Link href={route('products')} className="text-gray-700 hover:text-black text-sm transition-colors">New Arrivals</Link></li>
                              <li><Link href={route('products')} className="text-gray-700 hover:text-black text-sm transition-colors">Best Sellers</Link></li>
                            </ul>
                            <h3 className="font-bold text-sm mb-4 mt-6 tracking-wider">POPULAR THIS MONTH</h3>
                            <ul className="space-y-3">
                              <li><Link href={route('products')} className="text-gray-700 hover:text-black text-sm transition-colors">Running Shoes</Link></li>
                              <li><Link href={route('products')} className="text-gray-700 hover:text-black text-sm transition-colors">Basketball Shoes</Link></li>
                              <li><Link href={route('products')} className="text-gray-700 hover:text-black text-sm transition-colors">Limited Edition</Link></li>
                            </ul>
                          </div>

                          <div className="border-r border-gray-200 pr-8">
                            <h3 className="font-bold text-sm mb-4 tracking-wider">SHOES</h3>
                            <ul className="space-y-3">
                              {shoeCategories.map((category) => (
                                <li key={category.name}>
                                  <Link href={route('products')} className="text-gray-700 hover:text-black text-sm transition-colors">
                                    {category.name}
                                  </Link>
                                </li>
                              ))}
                            </ul>
                          </div>

                          <div>
                            <h3 className="font-bold text-sm mb-4 tracking-wider text-red-600">SALE</h3>
                            <ul className="space-y-3">
                              <li><Link href={route('products')} className="text-gray-700 hover:text-black text-sm transition-colors">All Shoes</Link></li>
                              <li><Link href={route('products')} className="text-gray-700 hover:text-black text-sm transition-colors">Last Sizes</Link></li>
                            </ul>
                            <div className="mt-6 bg-red-600 text-white p-4 text-center rounded-sm">
                              <h4 className="font-bold text-base">END OF SEASON</h4>
                              <p className="text-sm font-semibold">UP TO 50% OFF</p>
                            </div>
                          </div>
                        </div>
                      )}

                      {item.dropdownKey === 'men' && (
                        <div className="grid grid-cols-3 gap-x-10">
                          <div className="border-r border-gray-200 pr-8">
                            <h3 className="font-bold text-sm mb-4 tracking-wider">MEN FEATURED</h3>
                            <ul className="space-y-3">
                              <li><Link href={route('products', { category: 'men' })} className="text-gray-700 hover:text-black text-sm transition-colors">New This Week</Link></li>
                              <li><Link href={route('products', { category: 'men' })} className="text-gray-700 hover:text-black text-sm transition-colors">Best Sellers</Link></li>
                              <li><Link href={route('products', { category: 'men' })} className="text-gray-700 hover:text-black text-sm transition-colors">Performance Picks</Link></li>
                            </ul>
                          </div>

                          <div className="border-r border-gray-200 pr-8">
                            <h3 className="font-bold text-sm mb-4 tracking-wider">CATEGORIES</h3>
                            <ul className="space-y-3">
                              <li><Link href={route('products', { category: 'men' })} className="text-gray-700 hover:text-black text-sm transition-colors">Running</Link></li>
                              <li><Link href={route('products', { category: 'men' })} className="text-gray-700 hover:text-black text-sm transition-colors">Basketball</Link></li>
                              <li><Link href={route('products', { category: 'men' })} className="text-gray-700 hover:text-black text-sm transition-colors">Training</Link></li>
                              <li><Link href={route('products', { category: 'men' })} className="text-gray-700 hover:text-black text-sm transition-colors">Lifestyle</Link></li>
                            </ul>
                          </div>

                          <div>
                            <h3 className="font-bold text-sm mb-4 tracking-wider text-red-600">MEN SALE</h3>
                            <ul className="space-y-3">
                              <li><Link href={route('products', { category: 'men' })} className="text-gray-700 hover:text-black text-sm transition-colors">Clearance</Link></li>
                              <li><Link href={route('products', { category: 'men' })} className="text-gray-700 hover:text-black text-sm transition-colors">Last Sizes</Link></li>
                            </ul>
                            <div className="mt-6 bg-black text-white p-4 text-center rounded-sm">
                              <h4 className="font-bold text-base">MEN ESSENTIALS</h4>
                              <p className="text-sm font-semibold">READY FOR ANY DAY</p>
                            </div>
                          </div>
                        </div>
                      )}

                      {item.dropdownKey === 'women' && (
                        <div className="grid grid-cols-3 gap-x-10">
                          <div className="border-r border-gray-200 pr-8">
                            <h3 className="font-bold text-sm mb-4 tracking-wider">WOMEN FEATURED</h3>
                            <ul className="space-y-3">
                              <li><Link href={route('products', { category: 'women' })} className="text-gray-700 hover:text-black text-sm transition-colors">New Arrivals</Link></li>
                              <li><Link href={route('products', { category: 'women' })} className="text-gray-700 hover:text-black text-sm transition-colors">Top Rated</Link></li>
                              <li><Link href={route('products', { category: 'women' })} className="text-gray-700 hover:text-black text-sm transition-colors">Studio Favorites</Link></li>
                            </ul>
                          </div>

                          <div className="border-r border-gray-200 pr-8">
                            <h3 className="font-bold text-sm mb-4 tracking-wider">CATEGORIES</h3>
                            <ul className="space-y-3">
                              <li><Link href={route('products', { category: 'women' })} className="text-gray-700 hover:text-black text-sm transition-colors">Running</Link></li>
                              <li><Link href={route('products', { category: 'women' })} className="text-gray-700 hover:text-black text-sm transition-colors">Training</Link></li>
                              <li><Link href={route('products', { category: 'women' })} className="text-gray-700 hover:text-black text-sm transition-colors">Lifestyle</Link></li>
                              <li><Link href={route('products', { category: 'women' })} className="text-gray-700 hover:text-black text-sm transition-colors">Casual</Link></li>
                            </ul>
                          </div>

                          <div>
                            <h3 className="font-bold text-sm mb-4 tracking-wider text-red-600">WOMEN SALE</h3>
                            <ul className="space-y-3">
                              <li><Link href={route('products', { category: 'women' })} className="text-gray-700 hover:text-black text-sm transition-colors">Seasonal Deals</Link></li>
                              <li><Link href={route('products', { category: 'women' })} className="text-gray-700 hover:text-black text-sm transition-colors">Last Sizes</Link></li>
                            </ul>
                            <div className="mt-6 bg-pink-600 text-white p-4 text-center rounded-sm">
                              <h4 className="font-bold text-base">FRESH COLORWAYS</h4>
                              <p className="text-sm font-semibold">LIMITED DROPS</p>
                            </div>
                          </div>
                        </div>
                      )}

                      {item.dropdownKey === 'kids' && (
                        <div className="grid grid-cols-3 gap-x-10">
                          <div className="border-r border-gray-200 pr-8">
                            <h3 className="font-bold text-sm mb-4 tracking-wider">KIDS FEATURED</h3>
                            <ul className="space-y-3">
                              <li><Link href={route('products', { category: 'kids' })} className="text-gray-700 hover:text-black text-sm transition-colors">New Arrivals</Link></li>
                              <li><Link href={route('products', { category: 'kids' })} className="text-gray-700 hover:text-black text-sm transition-colors">Playground Ready</Link></li>
                              <li><Link href={route('products', { category: 'kids' })} className="text-gray-700 hover:text-black text-sm transition-colors">School Essentials</Link></li>
                            </ul>
                          </div>

                          <div className="border-r border-gray-200 pr-8">
                            <h3 className="font-bold text-sm mb-4 tracking-wider">CATEGORIES</h3>
                            <ul className="space-y-3">
                              <li><Link href={route('products', { category: 'kids' })} className="text-gray-700 hover:text-black text-sm transition-colors">Running</Link></li>
                              <li><Link href={route('products', { category: 'kids' })} className="text-gray-700 hover:text-black text-sm transition-colors">Casual</Link></li>
                              <li><Link href={route('products', { category: 'kids' })} className="text-gray-700 hover:text-black text-sm transition-colors">Slides</Link></li>
                              <li><Link href={route('products', { category: 'kids' })} className="text-gray-700 hover:text-black text-sm transition-colors">Sports</Link></li>
                            </ul>
                          </div>

                          <div>
                            <h3 className="font-bold text-sm mb-4 tracking-wider text-red-600">KIDS SALE</h3>
                            <ul className="space-y-3">
                              <li><Link href={route('products', { category: 'kids' })} className="text-gray-700 hover:text-black text-sm transition-colors">Bundle Deals</Link></li>
                              <li><Link href={route('products', { category: 'kids' })} className="text-gray-700 hover:text-black text-sm transition-colors">Last Sizes</Link></li>
                            </ul>
                            <div className="mt-6 bg-blue-600 text-white p-4 text-center rounded-sm">
                              <h4 className="font-bold text-base">FUN COLORS</h4>
                              <p className="text-sm font-semibold">MADE TO MOVE</p>
                            </div>
                          </div>
                        </div>
                      )}

                      {item.dropdownKey === 'sports' && (
                        <div className="grid grid-cols-3 gap-x-10">
                          <div className="border-r border-gray-200 pr-8">
                            <h3 className="font-bold text-sm mb-4 tracking-wider">SPORTS FEATURED</h3>
                            <ul className="space-y-3">
                              <li><Link href={route('products', { category: 'sports' })} className="text-gray-700 hover:text-black text-sm transition-colors">New Arrivals</Link></li>
                              <li><Link href={route('products', { category: 'sports' })} className="text-gray-700 hover:text-black text-sm transition-colors">Top Performance</Link></li>
                              <li><Link href={route('products', { category: 'sports' })} className="text-gray-700 hover:text-black text-sm transition-colors">Pro Picks</Link></li>
                            </ul>
                          </div>

                          <div className="border-r border-gray-200 pr-8">
                            <h3 className="font-bold text-sm mb-4 tracking-wider">SPORT TYPES</h3>
                            <ul className="space-y-3">
                              <li><Link href={route('products', { category: 'sports' })} className="text-gray-700 hover:text-black text-sm transition-colors">Basketball</Link></li>
                              <li><Link href={route('products', { category: 'sports' })} className="text-gray-700 hover:text-black text-sm transition-colors">Football</Link></li>
                              <li><Link href={route('products', { category: 'sports' })} className="text-gray-700 hover:text-black text-sm transition-colors">Running</Link></li>
                              <li><Link href={route('products', { category: 'sports' })} className="text-gray-700 hover:text-black text-sm transition-colors">Training</Link></li>
                            </ul>
                          </div>

                          <div>
                            <h3 className="font-bold text-sm mb-4 tracking-wider text-red-600">SPORTS SALE</h3>
                            <ul className="space-y-3">
                              <li><Link href={route('products', { category: 'sports' })} className="text-gray-700 hover:text-black text-sm transition-colors">Clearance</Link></li>
                              <li><Link href={route('products', { category: 'sports' })} className="text-gray-700 hover:text-black text-sm transition-colors">Last Sizes</Link></li>
                            </ul>
                            <div className="mt-6 bg-green-600 text-white p-4 text-center rounded-sm">
                              <h4 className="font-bold text-base">GAME READY</h4>
                              <p className="text-sm font-semibold">BUILT FOR SPEED</p>
                            </div>
                          </div>
                        </div>
                      )}
                    </div>
                  </div>
                );
              }

              // Regular nav items
              return (
                <Link
                  key={`${item.route}-${item.label}`}
                  href={route(item.route, item.params || {})}
                  aria-current={activeIndex === index ? "page" : undefined}
                  onMouseEnter={() => handleNavItemMouseEnter(index)}
                  className={`text-sm uppercase tracking-wider leading-none transition-all duration-300 ease-in-out pb-2 inline-flex items-center ${
                    activeIndex === index
                      ? 'font-semibold text-gray-900'
                      : 'font-medium text-gray-500 hover:text-gray-700'
                  }`}
                >
                  {item.label}
                </Link>
              );
            })}
            {/* Animated underline indicator that follows hover */}
            <div
              className="absolute -bottom-0 h-0.5 bg-gray-900 transition-all duration-300 ease-in-out pointer-events-none"
              style={{
                transform: `translateX(${underlineTranslateX}px)`,
                width: `${underlineWidth}px`,
                opacity: (isHovering && hoveredIndex !== null) ? 1 : 0,
              }}
            />
          </div>
          <div className="hidden md:flex items-center gap-4 absolute right-0">
            <form onSubmit={handleSearch} className="relative w-64">
              <span className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none">
                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-4.35-4.35m1.6-5.4a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
              </span>
              <input
                type="text"
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                placeholder="Search products..."
                className="w-full rounded-full border border-gray-200 bg-white py-2 pl-10 pr-4 text-sm text-gray-800 placeholder:text-gray-400 focus:border-gray-300 focus:outline-none"
                aria-label="Search products"
              />
            </form>
            <div className="flex items-center space-x-4">
            {/* User Icon with Dropdown */}
            <div
              className="relative"
              ref={dropdownRef}
              onMouseEnter={() => {
                if (hoverCloseTimeoutRef.current) {
                  window.clearTimeout(hoverCloseTimeoutRef.current);
                  hoverCloseTimeoutRef.current = null;
                }
                setUserDropdownOpen(true);
              }}
              onMouseLeave={() => {
                // delay closing slightly so clicks can register when moving to the menu
                if (hoverCloseTimeoutRef.current) window.clearTimeout(hoverCloseTimeoutRef.current);
                hoverCloseTimeoutRef.current = window.setTimeout(() => {
                  setUserDropdownOpen(false);
                  hoverCloseTimeoutRef.current = null;
                }, 150);
              }}
            >
              <button
                type="button"
                onClick={() => setUserDropdownOpen(!userDropdownOpen)}
                className="relative inline-flex items-center justify-center h-10 w-10 text-black hover:opacity-70 transition-opacity"
                aria-label="User account"
              >
                <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                </svg>
              </button>

              {/* Dropdown Menu */}
              {userDropdownOpen && (
                <div
                  className="absolute right-0 mt-2 w-48 bg-white border border-black shadow-lg rounded z-50"
                  onMouseEnter={() => {
                    if (hoverCloseTimeoutRef.current) {
                      window.clearTimeout(hoverCloseTimeoutRef.current);
                      hoverCloseTimeoutRef.current = null;
                    }
                  }}
                  onMouseLeave={() => {
                    if (hoverCloseTimeoutRef.current) window.clearTimeout(hoverCloseTimeoutRef.current);
                    hoverCloseTimeoutRef.current = window.setTimeout(() => {
                      setUserDropdownOpen(false);
                      hoverCloseTimeoutRef.current = null;
                    }, 150);
                  }}
                >
                  {isAuthenticated ? (
                    <>
                      <Link
                        href="/my-orders"
                        className="block px-4 py-3 text-black text-sm font-medium uppercase tracking-wider hover:bg-gray-100 transition-colors border-b border-gray-200"
                        onClick={() => setUserDropdownOpen(false)}
                      >
                        My Orders
                      </Link>
                      <Link
                        href="/my-repairs"
                        className="block px-4 py-3 text-black text-sm font-medium uppercase tracking-wider hover:bg-gray-100 transition-colors border-b border-gray-200"
                        onClick={() => setUserDropdownOpen(false)}
                      >
                        My Repairs
                      </Link>
                      <button
                        className="block w-full text-left px-4 py-3 text-black text-sm font-medium uppercase tracking-wider hover:bg-gray-100 transition-colors"
                        onClick={() => { setUserDropdownOpen(false); handleLogout(); }}
                      >
                        Log out
                      </button>
                    </>
                  ) : (
                    <Link
                      href="/user/login"
                      className="block px-4 py-3 text-black text-sm font-medium uppercase tracking-wider hover:bg-gray-100 transition-colors border-b border-gray-200"
                      onClick={() => setUserDropdownOpen(false)}
                    >
                      Customer Login
                    </Link>
                  )}
                </div>
              )}
            </div>

            {/* Messages Icon */}
            <Link href={route('message', { shopId: user?.shop_id || 1 })} className="relative inline-flex items-center justify-center h-10 w-10 text-black hover:opacity-70 transition-opacity" aria-label="Messages">
              <svg className="w-6 h-6 -mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  strokeWidth={2}
                  d="M7 8h10M7 12h6m-8 7l3.5-2H19a3 3 0 003-3V7a3 3 0 00-3-3H5a3 3 0 00-3 3v7a3 3 0 003 3h1l1 2z"
                />
              </svg>
              {/* Notification badge: show only if there are unread messages */}
              {/* Replace 6 with a dynamic unread count if available, or remove if not needed */}
              {/* Example: {unreadCount > 0 && ( ...badge... )} */}
            </Link>

            {/* Shopping Cart Icon */}
            <Link id="cart-icon" href="/checkout" className="relative inline-flex items-center justify-center h-10 w-10 text-black hover:opacity-70 transition-opacity" aria-label="Shopping cart">
              <svg className="w-6 h-6 -mt-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 3h2l.4 2M7 13h10l4-8H5.4m0 0L7 13m0 0l-1.1 5H19M7 13v8a2 2 0 002 2h10a2 2 0 002-2v-3" />
              </svg>
              {/* Cart badge (only for authenticated users) */}
              {isAuthenticated && (
                <span id="cart-badge" className="absolute -top-1 -right-1 bg-black text-white text-xs rounded-full h-5 w-5 flex items-center justify-center">
                  {cartLoading ? (
                    <svg className="animate-spin h-3 w-3" viewBox="0 0 24 24">
                      <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" fill="none" />
                      <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                    </svg>
                  ) : (
                    cartCount
                  )}
                </span>
              )}
            </Link>

            <button
              onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
              className="md:hidden text-black p-2"
              aria-label="Toggle menu"
              aria-expanded={mobileMenuOpen ? "true" : "false"}
            >
              <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                {mobileMenuOpen ? (
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                ) : (
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6h16M4 12h16M4 18h16" />
                )}
              </svg>
            </button>
            </div>
          </div>
        </div>
        {mobileMenuOpen && (
          <div className="md:hidden py-6 space-y-4 border-t border-gray-200">
            <form onSubmit={handleSearch} className="relative">
              <span className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none">
                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-4.35-4.35m1.6-5.4a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
              </span>
              <input
                type="text"
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                placeholder="Search products..."
                className="w-full rounded-full border border-gray-200 bg-white py-2 pl-10 pr-4 text-sm text-gray-800 placeholder:text-gray-400 focus:border-gray-300 focus:outline-none"
                aria-label="Search products"
              />
            </form>
            <Link
              href={route("landing")}
              aria-current={activeIndex === 0 ? "page" : undefined}
              className={`block text-sm font-medium uppercase tracking-wider transition-all duration-300 ease-in-out ${
                activeIndex === 0 ? 'font-semibold text-gray-900' : 'text-gray-500 hover:text-gray-700'
              }`}
            >
              Home
            </Link>
            <Link
              href={route("products")}
              aria-current={activeIndex === 1 ? "page" : undefined}
              className={`block text-sm font-medium uppercase tracking-wider transition-all duration-300 ease-in-out ${
                activeIndex === 1 ? 'font-semibold text-gray-900' : 'text-gray-500 hover:text-gray-700'
              }`}
            >
              Shoes
            </Link>
            <Link
              href={route("products", { category: 'men' })}
              aria-current={activeIndex === 2 ? "page" : undefined}
              className={`block text-sm font-medium uppercase tracking-wider transition-all duration-300 ease-in-out ${
                activeIndex === 2 ? 'font-semibold text-gray-900' : 'text-gray-500 hover:text-gray-700'
              }`}
            >
              Men
            </Link>
            <Link
              href={route("products", { category: 'women' })}
              aria-current={activeIndex === 3 ? "page" : undefined}
              className={`block text-sm font-medium uppercase tracking-wider transition-all duration-300 ease-in-out ${
                activeIndex === 3 ? 'font-semibold text-gray-900' : 'text-gray-500 hover:text-gray-700'
              }`}
            >
              Women
            </Link>
            <Link
              href={route("products", { category: 'kids' })}
              aria-current={activeIndex === 4 ? "page" : undefined}
              className={`block text-sm font-medium uppercase tracking-wider transition-all duration-300 ease-in-out ${
                activeIndex === 4 ? 'font-semibold text-gray-900' : 'text-gray-500 hover:text-gray-700'
              }`}
            >
              Kids
            </Link>
            <Link
              href={route("products", { category: 'sports' })}
              aria-current={activeIndex === 5 ? "page" : undefined}
              className={`block text-sm font-medium uppercase tracking-wider transition-all duration-300 ease-in-out ${
                activeIndex === 5 ? 'font-semibold text-gray-900' : 'text-gray-500 hover:text-gray-700'
              }`}
            >
              Sports
            </Link>
            <Link
              href={route("repair")}
              aria-current={activeIndex === 6 ? "page" : undefined}
              className={`block text-sm font-medium uppercase tracking-wider transition-all duration-300 ease-in-out ${
                activeIndex === 6 ? 'font-semibold text-gray-900' : 'text-gray-500 hover:text-gray-700'
              }`}
            >
              Repair
            </Link>
            <Link
              href={route("services")}
              aria-current={activeIndex === 7 ? "page" : undefined}
              className={`block text-sm font-medium uppercase tracking-wider transition-all duration-300 ease-in-out ${
                activeIndex === 7 ? 'font-semibold text-gray-900' : 'text-gray-500 hover:text-gray-700'
              }`}
            >
              Services
            </Link>
            {/* Contact removed from navbar */}
            {isAuthenticated ? (
              <button
                onClick={() => handleLogout()}
                className="block text-sm font-medium uppercase tracking-wider transition-all duration-300 ease-in-out text-gray-500 hover:text-gray-700"
              >
                Log out
              </button>
            ) : (
              <>
                <Link
                  href={route("login")}
                  className={`block text-sm font-medium uppercase tracking-wider transition-all duration-300 ease-in-out ${
                    (activeIndex === 8 || url === '/login') ? 'font-semibold text-gray-900' : 'text-gray-500 hover:text-gray-700'
                  }`}
                >
                  Login
                </Link>
                <Link
                  href={route("register")}
                  aria-current={activeIndex === 8 ? "page" : undefined}
                  className={`block text-sm font-medium uppercase tracking-wider transition-all duration-300 ease-in-out ${
                    activeIndex === 8 ? 'font-semibold text-gray-900' : 'text-gray-500 hover:text-gray-700'
                  }`}
                >
                  Register
                </Link>
              </>
            )}
          </div>
        )}
      </div>
    </nav>
  );
};

export default Navigation;
