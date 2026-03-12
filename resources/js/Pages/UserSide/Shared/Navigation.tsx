import React, { useState, useEffect, useRef } from 'react';
import { Link, usePage, router } from '@inertiajs/react';
import Swal from '@/Pages/UserSide/Shared/UserModal';
import { route } from 'ziggy-js';
import { useCart } from '../../../contexts/CartContext';
import { dispatchCartAddedEvent } from '../../../types/cart-events';
import NotificationCenter from '../../../components/header/NotificationCenter';
import NotificationBell from '../../../Components/common/NotificationBell';
import { useBadgeCounts } from '../../../hooks/useBadgeCounts';

type SearchSuggestionProduct = {
  id: number;
  name: string;
  slug: string;
  category?: string | null;
  main_image?: string | null;
  shop_name?: string | null;
  url: string;
};

type SearchSuggestionShop = {
  id: number;
  name: string;
  location?: string | null;
  image?: string | null;
  url: string;
  virtual_showroom_url: string;
};

const Navigation: React.FC = () => {
  const { cartCount, isLoading: cartLoading } = useCart();
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
  const [userDropdownOpen, setUserDropdownOpen] = useState(false);
  const [searchQuery, setSearchQuery] = useState('');
  const [searchProducts, setSearchProducts] = useState<SearchSuggestionProduct[]>([]);
  const [searchShops, setSearchShops] = useState<SearchSuggestionShop[]>([]);
  const [isSearchingSuggestions, setIsSearchingSuggestions] = useState(false);
  const [isSearchFocused, setIsSearchFocused] = useState(false);
  const [underlineTranslateX, setUnderlineTranslateX] = useState(0);
  const [underlineWidth, setUnderlineWidth] = useState(0);
  const [previousActiveIndex, setPreviousActiveIndex] = useState(-1);
  const [openDropdownKey, setOpenDropdownKey] = useState<string | null>(null);
  const [hoveredIndex, setHoveredIndex] = useState<number | null>(null);
  const [isHovering, setIsHovering] = useState(false);
  const [isScrolled, setIsScrolled] = useState(false);
  const page = usePage();
  const { url } = page;
  const { auth } = page.props as any;
  
  // Check if user is authenticated and is a regular customer (not ERP staff)
  const user = auth?.user;
  const isAuthenticated = Boolean(user && !user.shop_owner_id);
  
  // Use live badge counts hook for authenticated users
  const liveBadgeCounts = useBadgeCounts(isAuthenticated);
  
  // Use either live counts or fallback to page props
  const orderStatusCount = isAuthenticated 
    ? liveBadgeCounts.orderStatusCount 
    : Number((page.props as any)?.orderStatusCount ?? 0);
  const repairStatusCount = isAuthenticated 
    ? liveBadgeCounts.repairStatusCount 
    : Number((page.props as any)?.repairStatusCount ?? 0);
  const userIconCount = isAuthenticated 
    ? liveBadgeCounts.userIconCount 
    : Number((page.props as any)?.userIconCount ?? (orderStatusCount + repairStatusCount));
  const chatIconCount = isAuthenticated 
    ? liveBadgeCounts.chatIconCount 
    : Number((page.props as any)?.chatIconCount ?? 0);
  const cartIconCount = Number((page.props as any)?.cartIconCount ?? 0);
  
  const effectiveCartCount = isAuthenticated ? cartIconCount : (cartLoading ? 0 : cartCount);

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
      setIsSearchFocused(false);
    }
  };

  const handleSuggestionClick = (targetUrl: string) => {
    setIsSearchFocused(false);
    router.visit(targetUrl);
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
  const searchContainerRef = useRef<HTMLDivElement>(null);
  const hoverCloseTimeoutRef = useRef<number | null>(null);
  const searchAbortRef = useRef<AbortController | null>(null);
  const megaMenuBaseClasses =
    'absolute top-full left-1/2 -translate-x-1/2 mt-0 bg-white text-black shadow-2xl rounded-none w-auto min-w-[700px] max-w-[900px] py-8 px-10 border-t border-gray-200 transition-all duration-200 ease-out';
  const megaMenuHiddenClasses = 'opacity-0 translate-y-2 pointer-events-none';
  const megaMenuVisibleClasses = 'opacity-100 translate-y-0 pointer-events-auto';

  const navItems = [
    { route: 'landing', label: 'Home' },
    { route: 'products', label: 'Products', dropdownKey: 'shoes' },
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
    '/forgot-password': 'login', // Forgot password page should highlight ACCOUNT
    '/otp': 'login', // OTP page should highlight ACCOUNT
    '/new-password': 'login', // New password page should highlight ACCOUNT
    '/shop-owner/login': 'login', // Shop Owner Login should highlight ACCOUNT
    '/shop-owner-register': 'services', // Shop Owner Registration should highlight Services
    '/shop/register': 'services', // Alternative Shop Owner Registration URL
    '/shop-owner/register': 'services' // Another possible URL
  };

  const cleanUrl = url.split('?')[0]; // Remove query params
  const queryString = url.split('?')[1]; // Extract query string
  const isLandingPage = cleanUrl === '/';
  const isTransparentNav = isLandingPage && !isScrolled;
  const headerIconButtonClasses = `relative inline-flex h-10 w-10 shrink-0 items-center justify-center p-0 leading-none transition-all ${
    isTransparentNav
      ? 'text-white hover:opacity-70'
      : 'text-gray-900 rounded-full hover:bg-gray-100 hover:opacity-100'
  }`;
  const headerIconSvgClasses = 'block h-6 w-6 shrink-0';
  const searchIconClasses = isTransparentNav ? 'text-white/70' : 'text-gray-500';
  const desktopSearchInputClasses = `w-full rounded-full border py-2.5 pl-10 pr-4 text-sm shadow-lg backdrop-blur-xl transition-all duration-300 focus:outline-none focus:ring-2 ${
    isTransparentNav
      ? 'border-white/20 bg-white/12 text-white placeholder:text-white/60 focus:border-white/40 focus:bg-white/16 focus:ring-white/15'
      : 'border-gray-300/70 bg-white/70 text-gray-900 placeholder:text-gray-500 focus:border-gray-400/70 focus:bg-white/90 focus:ring-gray-200'
  }`;
  const suggestionActionBaseClass =
    'group inline-flex items-center justify-center gap-2 rounded-full px-4 py-2 text-[11px] font-semibold uppercase tracking-[0.16em] transition-all duration-300 focus-visible:outline-none focus-visible:ring-2';
  const suggestionActionDarkClass =
    'border border-[#16233b] bg-[#16233b] text-white shadow-[0_10px_24px_-18px_rgba(22,35,59,0.9)] hover:-translate-y-0.5 hover:bg-black focus-visible:ring-[#16233b]/50';
  const suggestionActionLightClass =
    'border border-gray-300 bg-white text-gray-900 hover:-translate-y-0.5 hover:border-gray-400 hover:bg-gray-50 focus-visible:ring-gray-300';
  const mobileSearchInputClasses =
    'w-full rounded-full border border-gray-300/70 bg-white/70 py-2.5 pl-10 pr-4 text-sm text-gray-900 shadow-sm backdrop-blur-md placeholder:text-gray-500 transition-all duration-300 focus:border-gray-400/70 focus:bg-white/90 focus:outline-none focus:ring-2 focus:ring-gray-200';
  
  // Extract category from query params
  const categoryMatch = queryString?.match(/category=([^&]+)/);
  const currentCategory = categoryMatch ? decodeURIComponent(categoryMatch[1]).toLowerCase() : null;

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
    // Special handling for products route with category
    if (currentRoute === 'products' && currentCategory) {
      activeIndex = navItems.findIndex(
        item => item.route === currentRoute && item.params?.category === currentCategory
      );
    } else {
      activeIndex = navItems.findIndex(item => item.route === currentRoute);
    }
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

  useEffect(() => {
    const query = searchQuery.trim();

    if (query.length < 2) {
      setSearchProducts([]);
      setSearchShops([]);
      setIsSearchingSuggestions(false);
      if (searchAbortRef.current) {
        searchAbortRef.current.abort();
        searchAbortRef.current = null;
      }
      return;
    }

    const timeoutId = window.setTimeout(async () => {
      try {
        if (searchAbortRef.current) {
          searchAbortRef.current.abort();
        }

        const controller = new AbortController();
        searchAbortRef.current = controller;
        setIsSearchingSuggestions(true);

        const response = await fetch(`/api/search/suggestions?query=${encodeURIComponent(query)}`, {
          headers: { Accept: 'application/json' },
          signal: controller.signal,
        });

        if (!response.ok) {
          throw new Error('Failed to load search suggestions');
        }

        const data = await response.json();
        setSearchProducts(Array.isArray(data.products) ? data.products : []);
        setSearchShops(Array.isArray(data.shops) ? data.shops : []);
      } catch (error: any) {
        if (error?.name !== 'AbortError') {
          setSearchProducts([]);
          setSearchShops([]);
        }
      } finally {
        setIsSearchingSuggestions(false);
      }
    }, 220);

    return () => window.clearTimeout(timeoutId);
  }, [searchQuery]);

  useEffect(() => {
    const handleDocumentClick = (event: MouseEvent) => {
      if (!searchContainerRef.current) return;
      if (!searchContainerRef.current.contains(event.target as Node)) {
        setIsSearchFocused(false);
      }
    };

    document.addEventListener('mousedown', handleDocumentClick);
    return () => document.removeEventListener('mousedown', handleDocumentClick);
  }, []);

  const shouldShowSearchDropdown =
    isSearchFocused &&
    searchQuery.trim().length >= 2 &&
    (isSearchingSuggestions || searchProducts.length > 0 || searchShops.length > 0);

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

  useEffect(() => {
    const onScroll = () => setIsScrolled(window.scrollY > 80);
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
    return () => window.removeEventListener('scroll', onScroll);
  }, []);

  return (
    <nav
      className={`fixed top-0 left-0 right-0 w-full z-50 transition-all duration-300 ${
        isTransparentNav
          ? 'bg-transparent'
          : 'bg-white/95 backdrop-blur'
      }`}
    >
      <div className="max-w-[1920px] mx-auto px-6 lg:px-12 h-20">
        <div className="flex items-center justify-center h-20 relative">
          <Link
            href={route("landing")}
            className={`text-2xl font-bold tracking-tight hover:opacity-70 transition-opacity absolute left-0 ${
              isTransparentNav ? 'text-white' : 'text-gray-900'
            }`}
          >
            SoleSpace
          </Link>
          <div
            className="hidden md:flex items-center space-x-10 relative mt-2"
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
                      href={route(item.route, item.params)}
                      aria-current={activeIndex === index ? "page" : undefined}
                      className={`text-sm uppercase tracking-wider leading-none transition-all duration-300 ease-in-out pb-2 inline-flex items-center ${
                        activeIndex === index
                          ? (isTransparentNav ? 'font-semibold text-white' : 'font-semibold text-gray-900')
                          : (isTransparentNav ? 'font-medium text-white/70 hover:text-white' : 'font-medium text-gray-500 hover:text-gray-700')
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
                      ? (isTransparentNav ? 'font-semibold text-white' : 'font-semibold text-gray-900')
                      : (isTransparentNav ? 'font-medium text-white/70 hover:text-white' : 'font-medium text-gray-500 hover:text-gray-700')
                  }`}
                >
                  {item.label}
                </Link>
              );
            })}
            {/* Animated underline indicator that follows hover */}
            <div
              className={`absolute -bottom-0 h-0.5 transition-all duration-300 ease-in-out pointer-events-none ${
                isTransparentNav ? 'bg-white' : 'bg-gray-900'
              }`}
              style={{
                transform: `translateX(${underlineTranslateX}px)`,
                width: `${underlineWidth}px`,
                opacity: (isHovering && hoveredIndex !== null) ? 1 : 0,
              }}
            />
          </div>
          <div className="hidden md:flex items-center gap-4 absolute right-0">
            <div className="relative w-64" ref={searchContainerRef}>
            <form onSubmit={handleSearch} className="relative w-full">
              <span className={`absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none transition-colors duration-300 ${searchIconClasses}`}>
                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-4.35-4.35m1.6-5.4a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
              </span>
              <input
                type="text"
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                onFocus={() => setIsSearchFocused(true)}
                placeholder="Search"
                className={desktopSearchInputClasses}
                aria-label="Search"
              />
            </form>
            {shouldShowSearchDropdown && (
              <div className="absolute right-0 top-[calc(100%+10px)] z-50 w-[min(92vw,44rem)] overflow-hidden rounded-2xl border border-gray-200 bg-gradient-to-b from-white to-gray-50 shadow-2xl">
                {isSearchingSuggestions ? (
                  <div className="px-5 py-4 text-sm text-gray-500">Searching suggestions...</div>
                ) : (
                  <>
                    <div className="border-b border-gray-200 px-5 py-3">
                      <p className="text-xs font-semibold uppercase tracking-[0.2em] text-gray-500">Suggestions</p>
                    </div>

                    {searchProducts.length > 0 && (
                      <div className="border-b border-gray-200 px-4 py-3">
                        <p className="px-1 pb-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500">Products</p>
                        {searchProducts.map((product) => (
                          <Link
                            key={`search-product-${product.id}`}
                            href={product.url}
                            className="flex items-center gap-3 rounded-xl px-3 py-2.5 transition hover:bg-white hover:shadow-sm"
                            onClick={() => setIsSearchFocused(false)}
                          >
                            <div className="h-11 w-11 shrink-0 overflow-hidden rounded-lg border border-gray-100 bg-gray-100">
                              {product.main_image ? (
                                <img src={product.main_image} alt={product.name} className="h-full w-full object-cover" />
                              ) : (
                                <div className="flex h-full w-full items-center justify-center text-xs font-semibold text-gray-500">P</div>
                              )}
                            </div>
                            <div className="min-w-0 flex-1">
                              <p className="truncate text-sm font-medium text-gray-900">{product.name}</p>
                              <div className="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-500">
                                {product.shop_name && <span className="truncate">{product.shop_name}</span>}
                                {product.category && (
                                  <span className="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-gray-600">
                                    {product.category}
                                  </span>
                                )}
                              </div>
                            </div>
                          </Link>
                        ))}
                      </div>
                    )}

                    {searchShops.length > 0 && (() => {
                      const isShowroomSearch = searchQuery.trim().toLowerCase().includes('showroom');
                      return (
                      <div className="px-4 py-3">
                        <p className="px-1 pb-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500">
                          {isShowroomSearch ? 'Shop Profiles (with Showroom)' : 'Shop Profiles'}
                        </p>
                        {searchShops.map((shop) => (
                          <div
                            key={`search-shop-${shop.id}`}
                            className="rounded-xl px-3 py-2.5 transition hover:bg-white hover:shadow-sm cursor-pointer"
                            role="button"
                            tabIndex={0}
                            onClick={() => handleSuggestionClick(shop.url)}
                            onKeyDown={(event) => {
                              if (event.key === 'Enter' || event.key === ' ') {
                                event.preventDefault();
                                handleSuggestionClick(shop.url);
                              }
                            }}
                          >
                            <div className="flex items-center gap-3">
                              <div className="h-11 w-11 shrink-0 overflow-hidden rounded-full border border-gray-100 bg-gray-100">
                                {shop.image ? (
                                  <img src={shop.image} alt={shop.name} className="h-full w-full object-cover" />
                                ) : (
                                  <div className="flex h-full w-full items-center justify-center text-xs font-semibold text-gray-500">S</div>
                                )}
                              </div>
                              <div className="min-w-0 flex-1">
                                <p className="truncate text-sm font-medium text-gray-900">{shop.name}</p>
                                {shop.location && <p className="truncate text-xs text-gray-500">{shop.location}</p>}
                              </div>
                            </div>
                            <div className="mt-2.5 flex flex-wrap gap-2 pl-14 text-[11px] font-semibold uppercase tracking-wide">
                              <Link
                                href={shop.url}
                                onClick={(event) => {
                                  event.stopPropagation();
                                  setIsSearchFocused(false);
                                }}
                                className={`${suggestionActionBaseClass} ${suggestionActionLightClass}`}
                              >
                                Profile
                              </Link>
                              {isShowroomSearch && (
                                <Link
                                  href={shop.virtual_showroom_url}
                                  onClick={(event) => {
                                    event.stopPropagation();
                                    setIsSearchFocused(false);
                                  }}
                                  className={`${suggestionActionBaseClass} ${suggestionActionDarkClass}`}
                                >
                                  Virtual Showroom
                                </Link>
                              )}
                            </div>
                          </div>
                        ))}
                      </div>
                      );
                    })()}
                  </>
                )}
              </div>
            )}
            </div>
            <div className="flex items-center gap-2 leading-none">
            {isAuthenticated && (
              <NotificationBell 
                basePath="/api/notifications"
                iconSize={24}
                className={isTransparentNav
                  ? 'rounded-full border border-white/65 bg-white/10 text-white hover:bg-white/20'
                  : 'rounded-full border border-gray-300 bg-white text-gray-900 hover:bg-gray-100'
                }
              />
            )}
            {/* User Icon with Dropdown */}
            <div
              className="relative flex shrink-0 items-center justify-center"
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
                }, 220);
              }}
            >
              <button
                type="button"
                onClick={() => setUserDropdownOpen(!userDropdownOpen)}
                className={headerIconButtonClasses}
                aria-label="User account"
              >
                <svg className={headerIconSvgClasses} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                </svg>
                {isAuthenticated && userIconCount > 0 && (
                  <span className="absolute top-0 right-0 inline-flex items-center justify-center px-2 py-1 text-xs font-bold leading-none text-white transform translate-x-1/2 -translate-y-1/2 bg-red-500 rounded-full">
                    {userIconCount}
                  </span>
                )}
              </button>

              {/* Dropdown Menu */}
              {userDropdownOpen && (
                <div
                  className="absolute right-0 top-full mt-0 w-48 bg-white border border-black shadow-lg rounded z-50"
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
                    }, 220);
                  }}
                >
                  {isAuthenticated ? (
                    <>
                      <Link
                        href="/my-orders"
                        className="flex items-center justify-between px-4 py-3 text-black text-sm font-medium uppercase tracking-wider hover:bg-gray-100 transition-colors border-b border-gray-200"
                        onClick={() => setUserDropdownOpen(false)}
                      >
                        <span>Orders</span>
                        {orderStatusCount > 0 && (
                          <span className="ml-2 text-xs font-semibold leading-none">{orderStatusCount}</span>
                        )}
                      </Link>
                      <Link
                        href="/my-repairs"
                        className="flex items-center justify-between px-4 py-3 text-black text-sm font-medium uppercase tracking-wider hover:bg-gray-100 transition-colors border-b border-gray-200"
                        onClick={() => setUserDropdownOpen(false)}
                      >
                        <span>Repair</span>
                        {repairStatusCount > 0 && (
                          <span className="ml-2 text-xs font-semibold leading-none">{repairStatusCount}</span>
                        )}
                      </Link>
                      <Link
                        href="/customer-profile"
                        className="block px-4 py-3 text-black text-sm font-medium uppercase tracking-wider hover:bg-gray-100 transition-colors border-b border-gray-200"
                        onClick={() => setUserDropdownOpen(false)}
                      >
                        Edit Profile
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

            {/* Messages Icon - Only visible for authenticated customers */}
            {isAuthenticated && (
              <Link href="/messages" className={headerIconButtonClasses} aria-label="Messages">
                <svg className={headerIconSvgClasses} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    strokeWidth={2}
                    d="M7 8h10M7 12h6m-8 7l3.5-2H19a3 3 0 003-3V7a3 3 0 00-3-3H5a3 3 0 00-3 3v7a3 3 0 003 3h1l1 2z"
                  />
                </svg>
                {chatIconCount > 0 && (
                  <span className="absolute top-0 right-0 inline-flex items-center justify-center px-2 py-1 text-xs font-bold leading-none text-white transform translate-x-1/2 -translate-y-1/2 bg-red-500 rounded-full">
                    {chatIconCount}
                  </span>
                )}
              </Link>
            )}

            {/* Shopping Cart Icon */}
            <Link id="cart-icon" href="/checkout" className={headerIconButtonClasses} aria-label="Shopping cart">
              <svg className={headerIconSvgClasses} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 4h2l2.2 10.2a2 2 0 001.96 1.58h7.68a2 2 0 001.95-1.56L21 7H8" />
                <circle cx="10" cy="19" r="1.5" strokeWidth={2} />
                <circle cx="17" cy="19" r="1.5" strokeWidth={2} />
              </svg>
              {/* Cart badge (only for authenticated users) */}
              {effectiveCartCount > 0 && (
                <span id="cart-badge" className="absolute top-0 right-0 inline-flex items-center justify-center px-2 py-1 text-xs font-bold leading-none text-white transform translate-x-1/2 -translate-y-1/2 bg-red-500 rounded-full">
                  {effectiveCartCount}
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
              <span className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 pointer-events-none">
                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-4.35-4.35m1.6-5.4a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
              </span>
              <input
                type="text"
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                onFocus={() => setIsSearchFocused(true)}
                placeholder="Search products or shops..."
                className={mobileSearchInputClasses}
                aria-label="Search products or shops"
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
