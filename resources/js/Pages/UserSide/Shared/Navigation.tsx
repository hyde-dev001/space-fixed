import React, { useState, useEffect, useRef } from 'react';
import { Link, usePage, router } from '@inertiajs/react';
import Swal from './UserModal';
import { route } from 'ziggy-js';
import { useCart } from '../../../contexts/CartContext';
import { dispatchCartAddedEvent } from '../../../types/cart-events';
import NotificationCenter from '../../../components/header/NotificationCenter';
import NotificationBell from '../../../components/common/NotificationBell';
import { useBadgeCounts } from '../../../hooks/useBadgeCounts';
import { getCustomerNavItems } from './navigationItems';

type SearchSuggestionProduct = {
  id: number;
  name: string;
  slug: string;
  category?: string | null;
  main_image?: string | null;
  shop_name?: string | null;
  price?: number | string | null;
  compare_at_price?: number | string | null;
  url: string;
};

type SearchSuggestionShop = {
  id: number;
  name: string;
  location?: string | null;
  image?: string | null;
  url: string;
  virtual_showroom_url?: string | null;
};

const CATEGORY_KEYWORDS: Record<string, string[]> = {
  men: ['men', 'male', 'man', 'mens'],
  women: ['women', 'woman', 'female', 'ladies', 'womens'],
  kids: ['kids', 'kid', 'child', 'children', 'youth'],
  sports: ['sports', 'sport', 'athletic', 'athletics'],
};

const resolveCategoryFromSearchQuery = (value: string): string | null => {
  const normalized = value.trim().toLowerCase();
  if (!normalized) return null;

  for (const [category, keywords] of Object.entries(CATEGORY_KEYWORDS)) {
    for (const keyword of keywords) {
      const regex = new RegExp(`\\b${keyword}\\b`, 'i');
      if (regex.test(normalized)) {
        return category;
      }
    }
  }

  return null;
};

type NavigationProps = {
  mobileMenuTriggerIcon?: 'people' | 'hamburger';
  landingSidebar?: boolean;
};

type QuickCartItem = {
  id: string;
  name: string;
  price: number;
  qty: number;
  image?: string;
  size?: string;
  color?: string;
};

type HeaderSeenCounts = {
  orderSeenCount: number;
  repairSeenCount: number;
};

const HEADER_SEEN_COUNTS_STORAGE_KEY = 'customer_header_seen_counts_v1';

const toSafeCount = (value: unknown): number => {
  const parsed = Number(value);
  if (!Number.isFinite(parsed) || parsed < 0) {
    return 0;
  }

  return Math.floor(parsed);
};

const PROMO_MESSAGES = [
  'SoleSpace summer edit',
  'Premium footwear',
  'Expert repairs',
  'Shop the latest drops',
] as const;

const Navigation: React.FC<NavigationProps> = ({ mobileMenuTriggerIcon = 'people', landingSidebar = true }) => {
  const { cartCount, isLoading: cartLoading } = useCart();
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
  const [landingSidebarOpen, setLandingSidebarOpen] = useState(false);
  const [landingSidebarExpanded, setLandingSidebarExpanded] = useState<string | null>(null);
  const [cartDrawerOpen, setCartDrawerOpen] = useState(false);
  const [quickCartItems, setQuickCartItems] = useState<QuickCartItem[]>([]);
  const [quickCartLoading, setQuickCartLoading] = useState(false);
  const [accountDrawerOpen, setAccountDrawerOpen] = useState(false);
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
  const initialOrderStatusCount = Number((page.props as any)?.orderStatusCount ?? 0);
  const initialRepairStatusCount = Number((page.props as any)?.repairStatusCount ?? 0);
  const initialChatIconCount = Number((page.props as any)?.chatIconCount ?? 0);
  const initialUserIconCount = Number((page.props as any)?.userIconCount ?? (initialOrderStatusCount + initialRepairStatusCount));
  
  // Use live badge counts hook for authenticated users
  const liveBadgeCounts = useBadgeCounts(isAuthenticated, {
    orderStatusCount: initialOrderStatusCount,
    repairStatusCount: initialRepairStatusCount,
    chatIconCount: initialChatIconCount,
    userIconCount: initialUserIconCount,
  });
  
  // Use either live counts or fallback to page props
  const orderStatusCount = isAuthenticated 
    ? liveBadgeCounts.orderStatusCount 
    : initialOrderStatusCount;
  const repairStatusCount = isAuthenticated 
    ? liveBadgeCounts.repairStatusCount 
    : initialRepairStatusCount;
  const chatIconCount = isAuthenticated 
    ? liveBadgeCounts.chatIconCount 
    : initialChatIconCount;
  const cartIconCount = Number((page.props as any)?.cartIconCount ?? 0);
  const [seenHeaderCounts, setSeenHeaderCounts] = useState<HeaderSeenCounts>({
    orderSeenCount: 0,
    repairSeenCount: 0,
  });
  
  const effectiveCartCount = isAuthenticated ? cartIconCount : (cartLoading ? 0 : cartCount);

  const persistSeenHeaderCounts = (next: HeaderSeenCounts) => {
    try {
      localStorage.setItem(HEADER_SEEN_COUNTS_STORAGE_KEY, JSON.stringify(next));
    } catch (error) {
      console.warn('Failed to persist header seen counts:', error);
    }
  };

  useEffect(() => {
    if (typeof window === 'undefined') {
      return;
    }

    try {
      const raw = localStorage.getItem(HEADER_SEEN_COUNTS_STORAGE_KEY);
      if (!raw) {
        return;
      }

      const parsed = JSON.parse(raw) as Partial<HeaderSeenCounts>;
      setSeenHeaderCounts({
        orderSeenCount: toSafeCount(parsed?.orderSeenCount),
        repairSeenCount: toSafeCount(parsed?.repairSeenCount),
      });
    } catch (error) {
      console.warn('Failed to restore header seen counts:', error);
    }
  }, []);

  useEffect(() => {
    if (!landingSidebarOpen) return;

    const handleEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        setLandingSidebarOpen(false);
        setAccountDrawerOpen(false);
      }
    };

    document.addEventListener('keydown', handleEscape);
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', handleEscape);
      document.body.style.overflow = '';
    };
  }, [landingSidebarOpen]);

  useEffect(() => {
    if (!isAuthenticated) {
      return;
    }

    setSeenHeaderCounts((prev) => {
      const clampedOrderSeen = Math.min(prev.orderSeenCount, orderStatusCount);
      const clampedRepairSeen = Math.min(prev.repairSeenCount, repairStatusCount);

      if (clampedOrderSeen === prev.orderSeenCount && clampedRepairSeen === prev.repairSeenCount) {
        return prev;
      }

      const next = {
        orderSeenCount: clampedOrderSeen,
        repairSeenCount: clampedRepairSeen,
      };

      persistSeenHeaderCounts(next);
      return next;
    });
  }, [isAuthenticated, orderStatusCount, repairStatusCount]);

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
    const trimmedQuery = searchQuery.trim();

    if (trimmedQuery) {
      const categoryFromKeyword = resolveCategoryFromSearchQuery(trimmedQuery);

      if (categoryFromKeyword) {
        router.visit(route('products', { category: categoryFromKeyword }));
      } else {
        router.visit(route('products', { search: trimmedQuery }));
      }
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
      reverseButtons: true,
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
          Swal.fire({
            icon: 'success',
            title: 'Logged out',
            text: 'You have been logged out successfully.',
            showConfirmButton: false,
            timer: 2000,
            timerProgressBar: true,
          });
        },
        onError: () => {
          Swal.fire({
            icon: 'error',
            title: 'Logout Failed',
            text: 'Please try again.',
            confirmButtonText: 'OK',
            iconColor: '#e36a5d',
          });
        },
      });
    } catch (e) {
      Swal.fire({
        icon: 'error',
        title: 'Logout Failed',
        text: 'Please try again.',
        confirmButtonText: 'OK',
        iconColor: '#e36a5d',
      });
    }
  };
  const navRef = useRef<HTMLDivElement>(null);
  const mobileUserMenuRef = useRef<HTMLDivElement>(null);
  const mobileMenuPanelRef = useRef<HTMLDivElement>(null);
  const searchContainerRef = useRef<HTMLDivElement>(null);
  const searchAbortRef = useRef<AbortController | null>(null);
  const megaMenuBaseClasses =
    'absolute top-full left-1/2 -translate-x-1/2 mt-0 bg-white text-black shadow-2xl rounded-none w-auto min-w-[700px] max-w-[900px] py-8 px-10 border-t border-gray-200 transition-all duration-200 ease-out';
  const megaMenuHiddenClasses = 'opacity-0 translate-y-2 pointer-events-none';
  const megaMenuVisibleClasses = 'opacity-100 translate-y-0 pointer-events-auto';

  const navItems = getCustomerNavItems(isAuthenticated);

  let activeIndex = -1;

  // Map URLs to nav items
  const urlToRouteMap: Record<string, string> = {
    '/': 'landing',
    '/products': 'products',
    '/repair-services': 'repair',
    '/download': 'download',
    '/services': 'services',
    '/services/product-image-spin-tutorial': 'services',
    '/register': 'login', // Register page should highlight ACCOUNT
    '/user/register': 'login', // User register page should highlight ACCOUNT
    '/login': 'login', // Login page should highlight ACCOUNT
    '/user/login': 'login', // Compatibility login URL should highlight ACCOUNT
    '/forgot-password': 'login', // Forgot password page should highlight ACCOUNT
    '/otp': 'login', // OTP page should highlight ACCOUNT
    '/new-password': 'login', // New password page should highlight ACCOUNT
    '/shop-owner/login': 'login', // Compatibility login URL should highlight ACCOUNT
    '/shop-owner/two-factor': 'login', // Shop Owner 2FA challenge should highlight ACCOUNT
    '/shop-owner-register': 'services', // Shop Owner Registration should highlight Services
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

  const mobileNavLinkClasses = (isActive: boolean) =>
    `block text-[11px] uppercase tracking-[0.22em] transition-all duration-300 ease-in-out ${
      isActive ? 'font-semibold text-gray-900' : 'font-medium text-gray-500 hover:text-gray-700'
    }`;

  const isMobileProductsActive = currentRoute === 'products' && !currentCategory;
  const isMobileMenActive = currentRoute === 'products' && currentCategory === 'men';
  const isMobileWomenActive = currentRoute === 'products' && currentCategory === 'women';
  const isMobileKidsActive = currentRoute === 'products' && currentCategory === 'kids';
  const isMobileSportsActive = currentRoute === 'products' && currentCategory === 'sports';
  const isMobileRepairActive = currentRoute === 'repair';
  const isMobileServicesActive = currentRoute === 'services';
  const isMobileAccountActive = currentRoute === 'login';

  const isMyProfileActive = cleanUrl.startsWith('/customer-profile');

  useEffect(() => {
    if (!isAuthenticated) {
      return;
    }

    const viewingOrders = cleanUrl.startsWith('/my-orders');
    const viewingRepairs = cleanUrl.startsWith('/my-repairs');

    if (!viewingOrders && !viewingRepairs) {
      return;
    }

    setSeenHeaderCounts((prev) => {
      const next = {
        orderSeenCount: viewingOrders ? orderStatusCount : prev.orderSeenCount,
        repairSeenCount: viewingRepairs ? repairStatusCount : prev.repairSeenCount,
      };

      if (next.orderSeenCount === prev.orderSeenCount && next.repairSeenCount === prev.repairSeenCount) {
        return prev;
      }

      persistSeenHeaderCounts(next);
      return next;
    });
  }, [cleanUrl, isAuthenticated, orderStatusCount, repairStatusCount]);

  const visibleOrderStatusCount = Math.max(0, orderStatusCount - seenHeaderCounts.orderSeenCount);
  const visibleRepairStatusCount = Math.max(0, repairStatusCount - seenHeaderCounts.repairSeenCount);
  const visibleUserIconCount = Math.max(0, visibleOrderStatusCount + visibleRepairStatusCount);

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

    if (!isSearchFocused || query.length === 1) {
      setSearchProducts([]);
      setSearchShops([]);
      setIsSearchingSuggestions(false);
      if (searchAbortRef.current) {
        searchAbortRef.current.abort();
        searchAbortRef.current = null;
      }
      return;
    }

    setIsSearchingSuggestions(true);

    const timeoutId = window.setTimeout(async () => {
      const controller = new AbortController();

      try {
        if (searchAbortRef.current) {
          searchAbortRef.current.abort();
        }

        searchAbortRef.current = controller;

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
      } catch (error: unknown) {
        const errorName = error && typeof error === 'object' && 'name' in error
          ? String(error.name)
          : undefined;

        if (errorName !== 'AbortError') {
          setSearchProducts([]);
          setSearchShops([]);
        }
      } finally {
        if (searchAbortRef.current === controller) {
          searchAbortRef.current = null;
          setIsSearchingSuggestions(false);
        }
      }
    }, 220);

    return () => {
      window.clearTimeout(timeoutId);
      if (searchAbortRef.current) {
        searchAbortRef.current.abort();
        searchAbortRef.current = null;
      }
    };
  }, [searchQuery, isSearchFocused]);

  useEffect(() => {
    if (landingSidebar) return;

    const handleDocumentClick = (event: MouseEvent) => {
      if (!searchContainerRef.current) return;
      if (!searchContainerRef.current.contains(event.target as Node)) {
        setIsSearchFocused(false);
      }
    };

    document.addEventListener('mousedown', handleDocumentClick);
    return () => document.removeEventListener('mousedown', handleDocumentClick);
  }, [landingSidebar]);

  useEffect(() => {
    if (!cartDrawerOpen) return;

    const loadQuickCart = async () => {
      setQuickCartLoading(true);
      try {
        if (!isAuthenticated) {
          const raw = localStorage.getItem('ss_cart');
          const localItems = raw ? JSON.parse(raw) : [];
          setQuickCartItems(Array.isArray(localItems) ? localItems.map((item: any) => ({
            id: String(item.id),
            name: item.name || 'Product',
            price: Number(item.price) || 0,
            qty: Math.max(1, Number(item.qty) || 1),
            image: item.image || item.main_image,
            size: item.size,
            color: item.color,
          })) : []);
          return;
        }

        const response = await fetch('/api/cart', { headers: { Accept: 'application/json' }, credentials: 'include' });
        if (!response.ok) throw new Error('Unable to load cart');
        const data = await response.json();
        setQuickCartItems((Array.isArray(data.items) ? data.items : []).map((item: any) => ({
          id: String(item.id ?? item.product_id),
          name: item.name || item.product?.name || 'Product',
          price: Number(item.price ?? item.product?.price) || 0,
          qty: Math.max(1, Number(item.qty ?? item.quantity) || 1),
          image: item.image || item.main_image || item.product?.main_image,
          size: item.size,
          color: item.color,
        })));
      } catch {
        setQuickCartItems([]);
      } finally {
        setQuickCartLoading(false);
      }
    };

    loadQuickCart();
  }, [cartDrawerOpen, isAuthenticated]);

  const shouldShowSearchDropdown =
    isSearchFocused &&
    (searchQuery.trim().length === 0 || searchQuery.trim().length >= 2) &&
    (isSearchingSuggestions || searchProducts.length > 0 || searchShops.length > 0);
  const normalizedSearchQuery = searchQuery.trim().toLowerCase();
  const isShowroomSearch = normalizedSearchQuery.includes('showroom');
  const isRepairSearch = /\brepair(?:er|ers)?\b/.test(normalizedSearchQuery);
  const isRetailSearch = /\bretail\b/.test(normalizedSearchQuery);
  const shopSuggestionLabel = isShowroomSearch
    ? 'Premium Showroom Shops'
    : isRepairSearch
      ? 'Repair Shops'
      : isRetailSearch
        ? 'Retail Shops'
        : 'Shop Profiles';

  const handleNavAreaMouseLeave = () => {
    setIsHovering(false);
    setHoveredIndex(null);
    setOpenDropdownKey(null);
  };

  useEffect(() => {
    const onScroll = () => setIsScrolled(window.scrollY > 80);
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
    return () => window.removeEventListener('scroll', onScroll);
  }, []);

  useEffect(() => {
    if (!mobileMenuOpen) return;

    const handleEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        setMobileMenuOpen(false);
      }
    };

    const handleMobileOutsideClick = (event: MouseEvent) => {
      const target = event.target as Node;
      const clickedTrigger = mobileUserMenuRef.current?.contains(target);
      const clickedPanel = mobileMenuPanelRef.current?.contains(target);

      if (!clickedTrigger && !clickedPanel) {
        setMobileMenuOpen(false);
      }
    };

    window.addEventListener('keydown', handleEscape);
    document.addEventListener('mousedown', handleMobileOutsideClick);
    return () => {
      window.removeEventListener('keydown', handleEscape);
      document.removeEventListener('mousedown', handleMobileOutsideClick);
    };
  }, [mobileMenuOpen]);

  useEffect(() => {
    const handleResize = () => {
      if (window.innerWidth >= 1536) {
        setMobileMenuOpen(false);
      }
    };

    window.addEventListener('resize', handleResize, { passive: true });
    return () => window.removeEventListener('resize', handleResize);
  }, []);

  return (
    <>
      <div className="fixed inset-x-0 top-0 z-[40] h-10 overflow-hidden border-b border-white/15 bg-[#111111]/70 text-white shadow-[0_8px_24px_-18px_rgba(0,0,0,0.7)] backdrop-blur-xl" aria-label="Latest offers">
        <div className="landing-marquee flex h-full min-w-max items-center gap-12 py-3 text-[11px] font-semibold uppercase tracking-[0.22em] motion-reduce:animate-none sm:gap-20 sm:text-xs">
          {[...PROMO_MESSAGES, ...PROMO_MESSAGES].map((message, index) => (
            <span key={`${message}-${index}`} className="inline-flex items-center gap-12 whitespace-nowrap sm:gap-20">
              {message}<span aria-hidden="true">•</span>
            </span>
          ))}
        </div>
      </div>
      <nav
        className={`fixed left-0 right-0 top-10 z-50 w-full transition-all duration-300 ${
          landingSidebar || isTransparentNav
            ? 'bg-transparent'
            : 'border-b border-gray-200/70 bg-white/95 backdrop-blur'
        }`}
      >
      <div className={`mx-auto w-full max-w-[1920px] px-4 sm:px-6 lg:px-10 2xl:px-12 ${landingSidebar ? 'h-0' : 'h-16 sm:h-20'}`}>
        <div className={`relative flex items-center justify-center pr-20 sm:pr-24 ${landingSidebar ? 'h-0' : 'h-16 sm:h-20'} ${landingSidebar ? '' : '2xl:pr-0'}`}>
          <Link
            href={route("landing")}
            className={`absolute top-3 text-xl font-bold leading-none tracking-tight transition-opacity hover:opacity-70 sm:top-5 sm:text-2xl ${
              landingSidebar ? 'left-1/2 -translate-x-1/2' : 'left-0'
            } ${
              isTransparentNav ? 'text-white drop-shadow-[0_1px_3px_rgba(0,0,0,0.7)]' : 'text-gray-900'
            }`}
          >
            SoleSpace
          </Link>

          {landingSidebar && (
            <button
              type="button"
              onClick={() => {
                setLandingSidebarOpen((open) => !open);
                setAccountDrawerOpen(false);
              }}
              className={`absolute left-0 top-3 inline-flex h-10 w-10 -translate-y-px items-center justify-center p-0 transition-opacity hover:opacity-70 focus-visible:outline-none focus-visible:ring-2 sm:top-5 ${isTransparentNav ? 'text-white drop-shadow-[0_1px_3px_rgba(0,0,0,0.7)] focus-visible:ring-white' : 'text-gray-900 focus-visible:ring-gray-900'}`}
              aria-label={landingSidebarOpen ? 'Close menu' : 'Toggle menu'}
            >
              <svg className="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                {landingSidebarOpen ? (
                  <path strokeLinecap="round" strokeWidth={2} d="M6 6l12 12M18 6L6 18" />
                ) : (
                  <path strokeLinecap="round" strokeWidth={2} d="M4 6h16M4 12h16M4 18h16" />
                )}
              </svg>
            </button>
          )}

          <div className={`absolute right-0 flex items-center gap-1.5 ${landingSidebar ? 'top-3 sm:top-5 2xl:hidden' : '2xl:hidden'}`} ref={mobileUserMenuRef}>
            {landingSidebar && (
              <button type="button" onClick={() => setIsSearchFocused(true)} className={headerIconButtonClasses} aria-label="Open search">
                <svg className={headerIconSvgClasses} fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-4.35-4.35m1.6-5.4a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
              </button>
            )}
            <Link
              href="/checkout"
              className={`${headerIconButtonClasses} ${landingSidebar ? 'order-3' : ''}`}
              aria-label="Shopping cart"
              onClick={landingSidebar ? (event) => {
                event.preventDefault();
                setLandingSidebarOpen(false);
                setAccountDrawerOpen(false);
                setCartDrawerOpen(true);
              } : undefined}
            >
              <svg className={headerIconSvgClasses} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 4h2l2.2 10.2a2 2 0 001.96 1.58h7.68a2 2 0 001.95-1.56L21 7H8" />
                <circle cx="10" cy="19" r="1.5" strokeWidth={2} />
                <circle cx="17" cy="19" r="1.5" strokeWidth={2} />
              </svg>
              {effectiveCartCount > 0 && (
                <span className="absolute top-0 right-0 inline-flex items-center justify-center px-2 py-1 text-xs font-bold leading-none text-white transform translate-x-1/2 -translate-y-1/2 bg-red-500 rounded-full">
                  {effectiveCartCount}
                </span>
              )}
            </Link>
            {isAuthenticated && (
              <NotificationBell
                basePath="/api/notifications"
                iconSize={24}
                className={`${isTransparentNav ? 'text-white hover:opacity-70' : 'text-gray-900 hover:opacity-70'} ${landingSidebar ? 'order-2' : ''}`}
              />
            )}
            {!landingSidebar && (
              <button
                type="button"
                onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
                className={headerIconButtonClasses}
                aria-label={mobileMenuTriggerIcon === 'hamburger' ? 'Toggle menu' : 'User account menu'}
              >
                <svg className={headerIconSvgClasses} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  {mobileMenuTriggerIcon === 'hamburger' ? (
                    mobileMenuOpen ? (
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                    ) : (
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6h16M4 12h16M4 18h16" />
                    )
                  ) : (
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                  )}
                </svg>
                {mobileMenuTriggerIcon === 'people' && isAuthenticated && visibleUserIconCount > 0 && (
                  <span className="absolute top-0 right-0 inline-flex items-center justify-center px-2 py-1 text-xs font-bold leading-none text-white transform translate-x-1/2 -translate-y-1/2 bg-red-500 rounded-full">
                    {visibleUserIconCount}
                  </span>
                )}
              </button>
            )}
          </div>
          <div
            className={`relative mt-2 hidden items-center space-x-8 ${landingSidebar ? '' : '2xl:flex'}`}
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
          </div>
          <div className={`absolute right-0 hidden items-center 2xl:flex ${landingSidebar ? 'top-3 gap-1 sm:top-5 2xl:gap-2' : 'gap-3 2xl:gap-4'}`}>
            <div className={`relative ${landingSidebar ? 'w-10' : 'w-[17rem]'}`} ref={searchContainerRef}>
            {landingSidebar ? (
              <>
                <button
                  type="button"
                  onClick={() => setIsSearchFocused((focused) => !focused)}
                  className={`${headerIconButtonClasses} -mr-2`}
                  aria-label="Open search"
                  aria-expanded={isSearchFocused}
                >
                  <svg className={headerIconSvgClasses} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-4.35-4.35m1.6-5.4a7 7 0 11-14 0 7 7 0 0114 0z" />
                  </svg>
                </button>
              </>
            ) : (
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
            )}
            {shouldShowSearchDropdown && !landingSidebar && (
              <div className="absolute right-0 top-[calc(100%+10px)] z-50 w-[min(92vw,40rem)] overflow-hidden rounded-2xl border border-gray-200 bg-linear-to-b from-white to-gray-50 shadow-2xl">
                {isSearchingSuggestions ? (
                  <div className="px-5 py-4 text-sm text-gray-500">Searching suggestions...</div>
                ) : (
                  <>
                    <div className="border-b border-gray-200 px-5 py-3">
                      <p className="text-xs font-semibold uppercase tracking-[0.2em] text-gray-500">Suggestions</p>
                    </div>
                    <div className="max-h-96 overflow-y-auto">
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
                                </div>
                              </div>
                            </Link>
                          ))}
                        </div>
                      )}

                      {searchShops.length > 0 && (
                        <div className="px-4 py-3">
                          <p className="px-1 pb-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500">
                            {shopSuggestionLabel}
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
                                {isShowroomSearch && shop.virtual_showroom_url && (
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
                      )}

                      {searchProducts.length === 0 && searchShops.length === 0 && (
                        <div className="px-5 py-4 text-sm text-gray-500">No suggestions found.</div>
                      )}
                    </div>
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
                  ? 'text-white hover:opacity-70'
                  : 'text-gray-900 hover:opacity-70'
                }
              />
            )}
            {/* User Icon */}
            <div className="relative flex shrink-0 items-center justify-center">
              <button
                type="button"
                onClick={() => {
                  setMobileMenuOpen(false);
                  setLandingSidebarOpen(true);
                  setCartDrawerOpen(false);
                  setAccountDrawerOpen((open) => !open);
                }}
                className={headerIconButtonClasses}
                aria-label="User account"
                aria-expanded={accountDrawerOpen}
              >
                <svg className={headerIconSvgClasses} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                </svg>
                {isAuthenticated && visibleUserIconCount > 0 && (
                  <span className="absolute top-0 right-0 inline-flex items-center justify-center px-2 py-1 text-xs font-bold leading-none text-white transform translate-x-1/2 -translate-y-1/2 bg-red-500 rounded-full">
                    {visibleUserIconCount}
                  </span>
                )}
              </button>
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
            <Link
              id="cart-icon"
              href="/checkout"
              className={`${headerIconButtonClasses} ${landingSidebar ? 'order-3' : ''}`}
              aria-label="Shopping cart"
              onClick={landingSidebar ? (event) => {
                event.preventDefault();
                setLandingSidebarOpen(false);
                setAccountDrawerOpen(false);
                setCartDrawerOpen(true);
              } : undefined}
            >
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

            </div>
          </div>
        </div>
        {mobileMenuOpen && !landingSidebar && (
          <div className="2xl:hidden">
            {mobileMenuTriggerIcon === 'hamburger' ? (
              <div id="mobile-nav-menu" ref={mobileMenuPanelRef} className="mx-auto mt-3 w-full max-w-[430px] rounded-[28px] border border-gray-200 bg-white px-4 py-4 text-center shadow-[0_24px_45px_-28px_rgba(15,23,42,0.45)]">
                <form onSubmit={handleSearch} className="relative w-full">
                  <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-gray-500">
                    <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
                <div className="mt-4 space-y-3.5 pb-1">
                  <Link href={route('products')} onClick={() => setMobileMenuOpen(false)} className={mobileNavLinkClasses(isMobileProductsActive)}>Products</Link>
                  <Link href={route('products', { category: 'men' })} onClick={() => setMobileMenuOpen(false)} className={mobileNavLinkClasses(isMobileMenActive)}>Men</Link>
                  <Link href={route('products', { category: 'women' })} onClick={() => setMobileMenuOpen(false)} className={mobileNavLinkClasses(isMobileWomenActive)}>Women</Link>
                  <Link href={route('products', { category: 'kids' })} onClick={() => setMobileMenuOpen(false)} className={mobileNavLinkClasses(isMobileKidsActive)}>Kids</Link>
                  <Link href={route('products', { category: 'sports' })} onClick={() => setMobileMenuOpen(false)} className={mobileNavLinkClasses(isMobileSportsActive)}>Sports</Link>
                  <Link href={route('repair')} onClick={() => setMobileMenuOpen(false)} className={mobileNavLinkClasses(isMobileRepairActive)}>Repair</Link>
                  {!isAuthenticated && (
                    <Link href={route('services')} onClick={() => setMobileMenuOpen(false)} className={mobileNavLinkClasses(isMobileServicesActive)}>Services</Link>
                  )}
                  {isAuthenticated ? (
                    <button
                      type="button"
                      onClick={() => {
                        setMobileMenuOpen(false);
                        handleLogout();
                      }}
                      className="block w-full text-[11px] font-medium uppercase tracking-[0.22em] text-gray-500 transition-all duration-300 ease-in-out hover:text-gray-700"
                    >
                      Log out
                    </button>
                  ) : (
                    <>
                      <Link href={route('login')} onClick={() => setMobileMenuOpen(false)} className={mobileNavLinkClasses(isMobileAccountActive)}>Login</Link>
                      <Link href={route('register')} onClick={() => setMobileMenuOpen(false)} className={mobileNavLinkClasses(isMobileAccountActive)}>Register</Link>
                    </>
                  )}
                </div>
              </div>
            ) : (
              <div id="mobile-user-menu" ref={mobileMenuPanelRef} className="absolute right-2 top-full z-50 mt-2 w-[min(88vw,14rem)] overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-[0_18px_35px_-20px_rgba(15,23,42,0.45)]">
                {isAuthenticated ? (
                  <>
                    <Link
                      href="/customer-profile"
                      className={`flex items-center gap-3 border-b border-gray-100 px-4 py-3 text-sm font-medium transition-colors ${
                        isMyProfileActive ? 'bg-gray-50 text-gray-900' : 'text-gray-900 hover:bg-gray-50'
                      }`}
                      onClick={() => setMobileMenuOpen(false)}
                    >
                      <svg className="h-4 w-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.9} d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                      </svg>
                      <span>Edit Profile</span>
                    </Link>
                    <Link
                      href={route('shop-owner-register')}
                      className={`flex items-center gap-3 border-b border-gray-100 px-4 py-3 text-sm font-medium transition-colors ${
                        isMobileServicesActive ? 'bg-gray-50 text-gray-900' : 'text-gray-900 hover:bg-gray-50'
                      }`}
                      onClick={() => setMobileMenuOpen(false)}
                    >
                      <svg className="h-4 w-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.9} d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2m8-8a4 4 0 100-8 4 4 0 000 8zm6-3v6m3-3h-6" />
                      </svg>
                      <span>Join Our Team</span>
                    </Link>
                    <button
                      type="button"
                      className="flex w-full items-center gap-3 px-4 py-3 text-left text-sm font-medium text-red-600 transition-colors hover:bg-red-50"
                      onClick={() => {
                        setMobileMenuOpen(false);
                        handleLogout();
                      }}
                    >
                      <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.9} d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                      </svg>
                      <span>Log out</span>
                    </button>
                  </>
                ) : (
                  <Link
                    href="/login"
                    className="flex items-center gap-3 px-4 py-3 text-sm font-medium text-gray-900 transition-colors hover:bg-gray-50"
                    onClick={() => setMobileMenuOpen(false)}
                  >
                    <svg className="h-4 w-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.9} d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1" />
                    </svg>
                    <span>Customer Login</span>
                  </Link>
                )}
              </div>
            )}
          </div>
        )}
        </div>
        {landingSidebar && (
        <>
          {isSearchFocused && (
            <>
              <button
                type="button"
                aria-label="Close search"
                onClick={() => setIsSearchFocused(false)}
                className="fixed inset-0 z-[120] bg-black/55 opacity-100 backdrop-blur-[2px] transition-opacity duration-300 motion-reduce:transition-none"
              />
              <div role="dialog" aria-modal="true" aria-label="Search products" className="fixed left-1/2 top-1/2 z-[121] w-[min(92vw,42rem)] -translate-x-1/2 -translate-y-1/2 overflow-hidden bg-white text-[#111111] shadow-2xl transition-all duration-300 ease-out motion-reduce:transition-none">
                <form onSubmit={handleSearch} className="flex items-center gap-3 border-b border-[#dedede] px-5 py-4 sm:px-7">
                  <svg className="h-5 w-5 shrink-0 text-[#555555]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M21 21l-4.35-4.35m1.6-5.4a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                  <input
                    autoFocus
                    type="text"
                    value={searchQuery}
                    onChange={(event) => setSearchQuery(event.target.value)}
                    placeholder="Search products"
                    className="min-w-0 flex-1 border-0 bg-transparent text-base outline-none placeholder:text-[#777777]"
                    aria-label="Search products"
                  />
                  <button type="button" onClick={() => setIsSearchFocused(false)} className="inline-flex h-10 w-10 items-center justify-center" aria-label="Close search">
                    <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeWidth={1.8} d="M6 6l12 12M18 6L6 18" /></svg>
                  </button>
                </form>
                <div className="max-h-[55vh] overflow-y-auto px-5 py-5 sm:px-7">
                  <p className="mb-4 text-xs font-semibold uppercase tracking-[0.18em] text-[#555555]">Products</p>
                  {isSearchingSuggestions && <p className="text-sm text-[#777777]">Searching...</p>}
                  {!isSearchingSuggestions && searchQuery.trim().length >= 2 && searchProducts.length === 0 && searchShops.length === 0 && <p className="text-sm text-[#777777]">No results found.</p>}
                  {searchProducts.length > 0 && <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
                    {searchProducts.map((product) => (
                      <Link key={`modal-product-${product.id}`} href={product.url} onClick={() => setIsSearchFocused(false)} className="group">
                        <div className="aspect-square overflow-hidden bg-[#f3f3f3]">
                          {product.main_image ? <img src={product.main_image} alt={product.name} className="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105" /> : <div className="flex h-full items-center justify-center text-sm text-[#777777]">No image</div>}
                        </div>
                        <p className="mt-2 line-clamp-2 text-sm font-medium">{product.name}</p>
                        {product.shop_name && <p className="mt-1 text-xs text-[#777777]">{product.shop_name}</p>}
                        {product.price !== null && product.price !== undefined && Number.isFinite(Number(product.price)) && (
                          <p className="mt-2 text-sm font-semibold">
                            ₱{Number(product.price).toLocaleString('en-PH', { minimumFractionDigits: 2 })}
                            {product.compare_at_price !== null && product.compare_at_price !== undefined && Number(product.compare_at_price) > Number(product.price) && (
                              <span className="ml-2 text-xs font-normal text-[#999999] line-through">
                                ₱{Number(product.compare_at_price).toLocaleString('en-PH', { minimumFractionDigits: 2 })}
                              </span>
                            )}
                          </p>
                        )}
                      </Link>
                    ))}
                  </div>}
                </div>
              </div>
            </>
          )}
          <>
            <aside
              role="dialog"
              aria-modal="true"
              aria-label="Account submenu"
              aria-hidden={!accountDrawerOpen}
              className={`fixed left-[min(88vw,31rem)] top-0 z-[110] flex h-dvh w-[min(88vw,26rem)] max-w-[26rem] flex-col border-l border-white/60 bg-white/60 text-[#111111] shadow-2xl backdrop-blur-2xl transition-transform duration-300 ease-out motion-reduce:transition-none ${accountDrawerOpen ? 'translate-x-0' : '-translate-x-full pointer-events-none'}`}
            >
              <div className="flex items-center justify-between border-b border-white/50 bg-white/10 px-5 py-5 sm:px-7">
                <div>
                  <p className="text-lg font-semibold">Account</p>
                  <p className="mt-1 text-xs uppercase tracking-[0.16em] text-[#777777]">Profile &amp; access</p>
                </div>
                <button type="button" onClick={() => setAccountDrawerOpen(false)} className="inline-flex h-10 w-10 items-center justify-center rounded-full hover:bg-white/35 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#111111]" aria-label="Close account">
                  <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeWidth={1.8} d="M6 6l12 12M18 6L6 18" /></svg>
                </button>
              </div>
              <div className="flex-1 px-5 py-6 sm:px-7">
                {isAuthenticated ? (
                  <div className="overflow-hidden rounded-2xl border border-white/60 bg-white/30 shadow-[0_20px_45px_-32px_rgba(15,23,42,0.55)]">
                    <Link
                      href="/customer-profile"
                      className={`flex min-h-14 items-center gap-3 border-b border-white/50 px-4 text-sm font-medium transition-colors ${isMyProfileActive ? 'bg-white/35 text-gray-900' : 'text-gray-900 hover:bg-white/35'}`}
                      onClick={() => setAccountDrawerOpen(false)}
                    >
                      <svg className="h-4 w-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.9} d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                      </svg>
                      <span>Edit Profile</span>
                    </Link>
                    <Link
                      href={route('shop-owner-register')}
                      className={`flex min-h-14 items-center gap-3 border-b border-white/50 px-4 text-sm font-medium transition-colors ${isMobileServicesActive ? 'bg-white/35 text-gray-900' : 'text-gray-900 hover:bg-white/35'}`}
                      onClick={() => setAccountDrawerOpen(false)}
                    >
                      <svg className="h-4 w-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.9} d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2m8-8a4 4 0 100-8 4 4 0 000 8zm6-3v6m3-3h-6" />
                      </svg>
                      <span>Join Our Team</span>
                    </Link>
                    <button
                      type="button"
                      className="flex min-h-14 w-full items-center gap-3 px-4 text-left text-sm font-medium text-red-600 transition-colors hover:bg-red-50/60"
                      onClick={() => {
                        setAccountDrawerOpen(false);
                        handleLogout();
                      }}
                    >
                      <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.9} d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                      </svg>
                      <span>Log out</span>
                    </button>
                  </div>
                ) : (
                  <Link href="/login" onClick={() => setAccountDrawerOpen(false)} className="flex min-h-12 items-center justify-center rounded-2xl border border-white/60 bg-white/30 px-4 text-sm font-semibold text-gray-900 transition-colors hover:bg-white/45">
                    Customer Login
                  </Link>
                )}
              </div>
            </aside>
          </>
          <>
            <button
              type="button"
              aria-label="Close cart"
              onClick={() => setCartDrawerOpen(false)}
              className={`fixed inset-0 z-[100] bg-black/35 backdrop-blur-[2px] transition-opacity duration-300 motion-reduce:transition-none ${cartDrawerOpen ? 'opacity-100' : 'pointer-events-none opacity-0'}`}
            />
            <aside
              aria-label="Shopping cart"
              aria-hidden={!cartDrawerOpen}
              className={`fixed right-0 top-0 z-[110] flex h-dvh w-[min(92vw,30rem)] max-w-[30rem] flex-col border-l border-white/60 bg-white/60 text-[#111111] shadow-2xl backdrop-blur-2xl transition-transform duration-300 ease-out motion-reduce:transition-none ${cartDrawerOpen ? 'translate-x-0' : 'translate-x-full pointer-events-none'}`}
            >
                <div className="flex items-center justify-between border-b border-[#dedede] px-5 py-5 sm:px-7">
                  <div><p className="text-lg font-semibold">Cart</p><p className="mt-1 text-xs uppercase tracking-[0.16em] text-[#777777]">{effectiveCartCount} {effectiveCartCount === 1 ? 'item' : 'items'}</p></div>
                  <button type="button" onClick={() => setCartDrawerOpen(false)} className="inline-flex h-10 w-10 items-center justify-center" aria-label="Close cart"><svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeWidth={1.8} d="M6 6l12 12M18 6L6 18" /></svg></button>
                </div>
                <div className="flex-1 overflow-y-auto px-5 py-5 sm:px-7">
                  {quickCartLoading && <p className="text-sm text-[#777777]">Loading your cart...</p>}
                  {!quickCartLoading && quickCartItems.length === 0 && <div className="py-12 text-center"><p className="text-base font-medium">Your cart is empty.</p><Link href={route('products')} onClick={() => setCartDrawerOpen(false)} className="mt-5 inline-flex min-h-11 items-center justify-center bg-[#111111] px-6 text-xs font-semibold uppercase tracking-[0.16em] text-white">Shop products</Link></div>}
                  {!quickCartLoading && quickCartItems.length > 0 && <div className="space-y-5">
                    {quickCartItems.map((item) => <div key={item.id} className="flex gap-4 border-b border-[#ededed] pb-5"><div className="h-24 w-24 shrink-0 overflow-hidden bg-[#f3f3f3]">{item.image ? <img src={item.image} alt={item.name} className="h-full w-full object-cover" /> : <div className="flex h-full items-center justify-center text-xs text-[#777777]">No image</div>}</div><div className="min-w-0 flex-1"><p className="font-medium">{item.name}</p>{(item.size || item.color) && <p className="mt-1 text-xs text-[#777777]">{[item.size, item.color].filter(Boolean).join(' / ')}</p>}<div className="mt-4 flex items-center justify-between text-sm"><span>Qty {item.qty}</span><span className="font-semibold">₱{item.price.toLocaleString('en-PH', { minimumFractionDigits: 2 })}</span></div></div></div>)}
                  </div>}
                </div>
                <div className="border-t border-[#dedede] px-5 py-5 sm:px-7"><div className="flex items-center justify-between text-sm font-semibold"><span>Estimated total</span><span>₱{quickCartItems.reduce((total, item) => total + item.price * item.qty, 0).toLocaleString('en-PH', { minimumFractionDigits: 2 })}</span></div><Link href="/checkout" onClick={() => setCartDrawerOpen(false)} className="mt-5 flex min-h-12 items-center justify-center bg-[#111111] text-xs font-semibold uppercase tracking-[0.16em] text-white">Checkout</Link></div>
            </aside>
          </>
          <button
            type="button"
            aria-label="Close menu"
            onClick={() => {
              setAccountDrawerOpen(false);
              setLandingSidebarOpen(false);
            }}
            className={`fixed inset-0 z-[100] bg-black/35 backdrop-blur-[2px] transition-opacity duration-300 motion-reduce:transition-none ${landingSidebarOpen ? 'opacity-100' : 'pointer-events-none opacity-0'}`}
          />
          <aside
            aria-label="Site menu"
            className={`fixed left-0 top-0 z-[110] flex h-dvh w-[min(88vw,31rem)] flex-col overflow-y-auto border-r border-white/60 bg-white/60 text-[#111111] shadow-2xl backdrop-blur-2xl transition-transform duration-300 ease-out motion-reduce:transition-none ${landingSidebarOpen ? 'translate-x-0' : '-translate-x-full'}`}
          >
            <div className="flex items-center justify-between border-b border-[#e5e5e5] px-6 py-6 sm:px-8">
              <Link href={route('landing')} onClick={() => { setAccountDrawerOpen(false); setLandingSidebarOpen(false); }} className="text-xl font-semibold tracking-[-0.06em] sm:text-2xl">
                SOLESPACE
              </Link>
              <button type="button" onClick={() => { setAccountDrawerOpen(false); setLandingSidebarOpen(false); }} className="inline-flex h-11 w-11 items-center justify-center rounded-full hover:bg-[#f5f5f5] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#111111]" aria-label="Close menu">
                <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 6l12 12M18 6L6 18" /></svg>
              </button>
            </div>
            <nav className="flex-1 px-6 py-7 sm:px-8">
              <div className="space-y-1">
                {navItems.map((item) => {
                  const hasChildren = Boolean(item.dropdownKey);
                  const href = route(item.route, item.params ?? undefined);
                  const isExpanded = landingSidebarExpanded === item.dropdownKey;
                  return (
                    <div key={`${item.label}-${item.dropdownKey ?? 'link'}`}>
                      <div className="flex items-center justify-between">
                        <Link href={href} onClick={() => { setAccountDrawerOpen(false); setLandingSidebarOpen(false); }} className="flex min-h-12 flex-1 items-center text-lg font-semibold tracking-[-0.02em] transition-opacity hover:opacity-55 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#111111] sm:text-xl">
                          {item.label}
                        </Link>
                        {hasChildren && (
                          <button type="button" onClick={() => setLandingSidebarExpanded(isExpanded ? null : item.dropdownKey ?? null)} className="inline-flex h-11 w-11 items-center justify-center rounded-full hover:bg-[#f5f5f5] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#111111]" aria-label={`${isExpanded ? 'Collapse' : 'Expand'} ${item.label}`}>
                            <svg className={`h-4 w-4 transition-transform ${isExpanded ? 'rotate-45' : ''}`} fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeWidth={2} d="M12 5v14M5 12h14" /></svg>
                          </button>
                        )}
                      </div>
                      {isExpanded && (
                        <div className="mb-3 ml-4 border-l border-[#cacacb] pl-4">
                          <Link href={route('products', item.params?.category ? { category: item.params.category } : undefined)} onClick={() => { setAccountDrawerOpen(false); setLandingSidebarOpen(false); }} className="flex min-h-10 items-center text-sm font-medium text-[#707072] hover:text-[#111111]">Shop all</Link>
                          {['New arrivals', 'Best sellers', 'Running', 'Basketball', 'Lifestyle'].map((category) => (
                            <Link key={category} href={route('products', item.params?.category ? { category: item.params.category } : undefined)} onClick={() => { setAccountDrawerOpen(false); setLandingSidebarOpen(false); }} className="flex min-h-10 items-center text-sm font-medium text-[#707072] hover:text-[#111111]">{category}</Link>
                          ))}
                        </div>
                      )}
                    </div>
                  );
                })}
              </div>
            </nav>
            <div className="border-t border-[#cacacb] px-6 py-6 sm:px-8">
              <button type="button" onClick={() => { setCartDrawerOpen(false); setAccountDrawerOpen(true); }} aria-expanded={accountDrawerOpen} className="flex min-h-12 w-full items-center gap-3 text-left text-base font-medium hover:opacity-55"><svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="8" r="3" strokeWidth={2}/><path strokeLinecap="round" strokeWidth={2} d="M5 20a7 7 0 0 1 14 0"/></svg>{isAuthenticated ? 'Account' : 'Sign in'}</button>
              <button type="button" onClick={() => { setLandingSidebarOpen(false); setAccountDrawerOpen(false); setCartDrawerOpen(true); }} className="flex min-h-12 w-full items-center gap-3 text-left text-base font-medium hover:opacity-55"><svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeWidth={2} d="M3 4h2l2.2 10.2a2 2 0 0 0 1.96 1.58h7.68a2 2 0 0 0 1.95-1.56L21 7H8"/><circle cx="10" cy="19" r="1.5"/><circle cx="17" cy="19" r="1.5"/></svg>Cart {effectiveCartCount > 0 && `(${effectiveCartCount})`}</button>
              <Link href={route('download')} onClick={() => { setAccountDrawerOpen(false); setLandingSidebarOpen(false); }} className="flex min-h-12 items-center gap-3 text-base font-medium hover:opacity-55"><svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 3v12m0 0 4-4m-4 4-4-4M5 21h14" /></svg>Download</Link>
            </div>
          </aside>
        </>
        )}
      </nav>
    </>
  );
};
export default Navigation;
