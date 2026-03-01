import { useCallback, useEffect, useRef, useState } from "react";
import { Link, usePage } from "@inertiajs/react";
import { route } from "ziggy-js";

// Assume these icons are imported from an icon library
import {
  CalenderIcon,
  CheckLineIcon,
  HorizontaLDots,
  UserCircleIcon,
  ShootingStarIcon,
  BoxIcon,
} from "../icons";
import { useSidebar } from "../context/SidebarContext";

type NavItem = {
  name: string;
  icon: React.ReactNode;
  route?: string; // Changed from 'path' to 'route' to use Laravel route names
  path?: string;
  subItems?: { name: string; route: string; pro?: boolean; new?: boolean }[];
};

const navItems: NavItem[] = [
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <circle cx="9" cy="21" r="1"></circle>
        <circle cx="20" cy="21" r="1"></circle>
        <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
      </svg>
    ),
    name: "Dashboard",
    route: "shop-owner.dashboard",
    path: "/shop-owner/dashboard",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
        <path d="M3 9h18"></path>
        <path d="M9 21V9"></path>
      </svg>
    ),
    name: "Inventory Overview",
    route: "shop-owner.inventory-overview",
    path: "/shop-owner/inventory-overview",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
      </svg>
    ),
    name: "Audit Logs",
    route: "shop-owner.audit-logs",
    path: "/shop-owner/audit-logs",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
        <circle cx="12" cy="7" r="4"></circle>
      </svg>
    ),
    name: "User Access Control",
    route: "shopOwner.user-access-control",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <rect x="4" y="10" width="16" height="10" rx="2"></rect>
        <path d="M8 10V8a4 4 0 018 0v2"></path>
        <path d="M12 14v2"></path>
      </svg>
    ),
    name: "Suspend Accounts",
    route: "shopOwner.suspend-accounts",
    path: "/shopOwner/suspend-accounts",
  },
];

const approvalWorkflowItems: NavItem[] = [
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
      </svg>
    ),
    name: "Price Approvals",
    route: "shop-owner.price-approvals",
    path: "/shop-owner/price-approvals",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
      </svg>
    ),
    name: "Repair Reject Approval",
    route: "shop-owner.repair-reject-approval",
    path: "/shop-owner/repair-reject-approval",
  },
];

const productManagementItems: NavItem[] = [
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" strokeLinejoin="round" xmlns="http://www.w3.org/2000/svg">
        <path d="M6 6h15l-1.5 9h-12z" />
        <circle cx="9" cy="19" r="1.5" />
        <circle cx="18" cy="19" r="1.5" />
        <path d="M6 6L4 2" />
      </svg>
    ),
    name: "Job Orders Retail",
    route: "shop-owner.job-orders-retail",
    path: "/shop-owner/job-orders-retail",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
        <line x1="16" y1="2" x2="16" y2="6"></line>
        <line x1="8" y1="2" x2="8" y2="6"></line>
        <line x1="3" y1="10" x2="21" y2="10"></line>
      </svg>
    ),
    name: "Job Orders Repair",
    route: "shop-owner.job-orders-repair",
    path: "/shop-owner/job-orders-repair",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.8} strokeLinecap="round" strokeLinejoin="round" xmlns="http://www.w3.org/2000/svg">
        <path d="M3 15h4.5l2.5-3.5h3.5l2.2 2.2c.8.8 1.9 1.3 3 1.3H21a1 1 0 0 1 1 1v1a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-1a2 2 0 0 1 1-1z" />
        <path d="M8 15l1.5 1.5" />
        <path d="M11 15l1.5 1.5" />
      </svg>
    ),
    name: "Product Uploder",
    route: "shop-owner.product-uploder",
    path: "/shop-owner/product-uploder",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9"></path>
        <path d="M15 13l-3-3m0 0l-3 3m3-3v12"></path>
      </svg>
    ),
    name: "Services Uploder",
    route: "shop-owner.upload-services",
    path: "/shop-owner/upload-services",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
      </svg>
    ),
    name: "Refund Approval",
    route: "shopOwner.refund-approvals",
    path: "/shopOwner/refund-approvals",
  },
];

const customerManagementItems: NavItem[] = [
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M8 10h8m-8 4h5" />
        <path d="M21 12c0 4.418-4.03 8-9 8a9.97 9.97 0 01-4.39-.99L3 20l1.33-3.57A7.95 7.95 0 013 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
      </svg>
    ),
    name: "Customer Support",
    route: "shop-owner.customer-support",
    path: "/shop-owner/customer-support",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z" />
        <path d="M14 2v6h6" />
        <path d="M9 15h6" />
        <path d="M9 11h6" />
      </svg>
    ),
    name: "Repair Support",
    route: "shop-owner.repair-support",
    path: "/shop-owner/repair-support",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2" />
        <circle cx="9" cy="7" r="4" />
        <path d="M23 21v-2a4 4 0 00-3-3.87" />
        <path d="M16 3.13a4 4 0 010 7.75" />
      </svg>
    ),
    name: "Customers",
    route: "shop-owner.customers",
    path: "/shop-owner/customers",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M12 17l-5 3 1.5-5.5L4 10.5l5.6-.5L12 5l2.4 5 5.6.5-4.5 4 1.5 5.5z" />
      </svg>
    ),
    name: "Customer Reviews",
    route: "shop-owner.customer-reviews",
    path: "/shop-owner/customer-reviews",
  },
];

type MenuType = "main" | "approval" | "product" | "customer";

const AppSidebar_shopOwner: React.FC = () => {
  const { isExpanded, isMobileOpen, isHovered, setIsHovered, openSubmenu, toggleSubmenu } = useSidebar();
  const { url, props } = usePage();
  const auth = (props as any).auth;
  const shopOwner = auth?.shop_owner;
  const isIndividualAccount = shopOwner?.registration_type?.toLowerCase() === "individual";
  const rawBusinessType = shopOwner?.business_type?.toLowerCase();
  // Normalize business type - handle "both (retail & repair)" and "both"
  const businessType = rawBusinessType?.includes('both') ? 'both' : rawBusinessType;
  const individualAccountSectionLabel =
    businessType === "repair"
      ? "Repair"
      : businessType === "retail"
        ? "Sales"
        : businessType === "both"
          ? "Repair & Sales"
          : "Sales";
  // Company accounts only see approval items, so always show "Approval Workflow"
  const companyAccountSectionLabel = "Approval Workflow";

  const [subMenuHeight, setSubMenuHeight] = useState<Record<string, number>>(
    {}
  );
  const subMenuRefs = useRef<Record<string, HTMLDivElement | null>>({});

  // Check if menu item should be visible based on shop owner's registration and business type
  const isMenuItemVisible = useCallback((menuItem: NavItem) => {
    if (!shopOwner) return true; // Show all if no shop owner data

    const registrationType = shopOwner.registration_type?.toLowerCase();
    const rawBusinessType = shopOwner.business_type?.toLowerCase();
    // Normalize business type - handle "both (retail & repair)" and "both"
    const itemBusinessType = rawBusinessType?.includes('both') ? 'both' : rawBusinessType;
    const isCompany = shopOwner.is_company === true;
    const canManageStaff = shopOwner.can_manage_staff === true;

    // Company-only features (require Company registration)
    const companyOnlyRoutes = [
      'shopOwner.user-access-control',
      'shop-owner.price-approvals',
      'shop-owner.repair-reject-approval',
      'shopOwner.suspend-accounts'
    ];

    if (menuItem.route && companyOnlyRoutes.includes(menuItem.route)) {
      return isCompany || canManageStaff;
    }

    // Operational routes - only visible to individual accounts, NOT company accounts
    // Company accounts have staff to handle these tasks
    const operationalRoutes = [
      'shop-owner.job-orders-retail',
      'shop-owner.job-orders-repair',
      'shop-owner.product-uploder',
      'shop-owner.upload-services'
    ];

    if (menuItem.route && operationalRoutes.includes(menuItem.route)) {
      // Hide from company accounts - they manage staff who do these tasks
      if (isCompany || canManageStaff) {
        return false;
      }

      // For individual accounts, show based on business type
      const retailRoutes = ['shop-owner.job-orders-retail', 'shop-owner.product-uploder'];
      const repairRoutes = ['shop-owner.job-orders-repair', 'shop-owner.upload-services'];

      if (retailRoutes.includes(menuItem.route)) {
        return itemBusinessType === 'retail' || itemBusinessType === 'both';
      }

      if (repairRoutes.includes(menuItem.route)) {
        return itemBusinessType === 'repair' || itemBusinessType === 'both';
      }
    }

    // Business type specific support/management routes
    // Company accounts should not see support pages in the sidebar
    if (menuItem.route === 'shop-owner.customer-support') {
      if (registrationType === 'company') {
        return false;
      }
      return itemBusinessType === 'retail' || itemBusinessType === 'both';
    }

    // Company accounts should not see support pages in the sidebar
    if (menuItem.route === 'shop-owner.repair-support') {
      if (registrationType === 'company') {
        return false;
      }
      return itemBusinessType === 'repair' || itemBusinessType === 'both';
    }

    // All other items are visible to everyone
    return true;
  }, [shopOwner]);

  // Check if route is active using Inertia's route() helper
  const isActive = useCallback(
    (routeName: string) => {
      try {
        if (routeName === "shop-owner.repair-reject-approval" && url.startsWith("/shop-owner/history-rejection")) {
          return true;
        }
        // Prefer Ziggy's router check when available: route() -> Router.current(name)
        try {
          if (typeof route === "function") {
            const router = (route as any)();
            if (typeof router.current === "function") {
              if (router.current(routeName)) return true;
            }
          }
        } catch (e) {
          // ignore and fallback to URL comparison
        }

        const routeUrl = route(routeName);
        return url === routeUrl || url.startsWith(routeUrl);
      } catch {
        return false;
      }
    },
    [url]
  );

  const isPathActive = useCallback(
    (path?: string) => {
      if (!path) return false;
      return url === path || url.startsWith(path);
    },
    [url]
  );

  const getHref = useCallback((routeName?: string, path?: string) => {
    if (!routeName) return path;
    try {
      return route(routeName);
    } catch {
      return path;
    }
  }, []);

  const isMenuActive = useCallback(
    (nav: NavItem) => {
      if (nav.route && isActive(nav.route)) return true;
      if (!nav.route && nav.path && isPathActive(nav.path)) return true;
      if (nav.route === "shop-owner.repair-reject-approval" && url.startsWith("/shop-owner/history-rejection")) {
        return true;
      }
      if (nav.subItems) {
        return nav.subItems.some(sub => isActive(sub.route));
      }
      return false;
    },
    [isActive, isPathActive, url]
  );

  useEffect(() => {
    let submenuMatched = false;
    let matchedKey: string | null = null;

    ["main", "approval", "product", "customer"].forEach((menuType) => {
      const items = menuType === "main"
        ? navItems
        : menuType === "approval"
          ? approvalWorkflowItems
          : menuType === "product"
            ? productManagementItems
            : customerManagementItems;
      items.forEach((nav, index) => {
        if (nav.subItems) {
          nav.subItems.forEach((subItem) => {
            if (isActive(subItem.route)) {
              const key = `${menuType}-${index}`;
              matchedKey = key;
              submenuMatched = true;
            }
          });
        }
      });
    });

    // Only update if we found a match and it's different from current
    if (submenuMatched && matchedKey && openSubmenu !== matchedKey) {
      toggleSubmenu(matchedKey);
    }
  }, [url, isActive]);

  useEffect(() => {
    if (openSubmenu !== null) {
      const key = openSubmenu;
      if (subMenuRefs.current[key]) {
        setSubMenuHeight((prevHeights) => ({
          ...prevHeights,
          [key]: subMenuRefs.current[key]?.scrollHeight || 0,
        }));
      }
    }
  }, [openSubmenu]);

  const handleSubmenuToggle = (index: number, menuType: MenuType) => {
    const key = `${menuType}-${index}`;
    toggleSubmenu(key);
  };

  const renderMenuItems = (items: NavItem[], menuType: MenuType) => (
    <ul className="flex flex-col gap-4">
      {items.filter(isMenuItemVisible).map((nav, index) => (
        <li key={nav.name}>
          {nav.subItems ? (
            <button
              onClick={() => handleSubmenuToggle(index, menuType)}
              className={`menu-item group ${openSubmenu?.type === menuType && openSubmenu?.index === index
                  ? "menu-item-active"
                  : "menu-item-inactive"
                } cursor-pointer ${!isExpanded && !isHovered
                  ? "lg:justify-center"
                  : "lg:justify-start"
                }`}
            >
              <span
                className={`menu-item-icon-size w-6 h-6 ${openSubmenu?.type === menuType && openSubmenu?.index === index
                    ? "menu-item-icon-active"
                    : "menu-item-icon-inactive"
                  }`}
              >
                {nav.icon}
              </span>
              {(isExpanded || isHovered || isMobileOpen) && (
                <span className="menu-item-text">{nav.name}</span>
              )}
              {(isExpanded || isHovered || isMobileOpen) && (
                <svg
                  className={`ml-auto w-5 h-5 transition-transform duration-200 ${openSubmenu?.type === menuType &&
                      openSubmenu?.index === index
                      ? "rotate-180 text-brand-500"
                      : ""
                    }`}
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth="2"
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  aria-hidden="true"
                >
                  <path d="M6 9l6 6 6-6" />
                </svg>
              )}
            </button>
          ) : (
            (nav.route || nav.path) && (
              <Link
                href={getHref(nav.route, nav.path) || "#"}
                className={`menu-item group ${nav.route
                    ? isActive(nav.route)
                      ? "menu-item-active"
                      : "menu-item-inactive"
                    : isPathActive(nav.path)
                      ? "menu-item-active"
                      : "menu-item-inactive"
                  }`}
              >
                <span
                  className={`menu-item-icon-size w-6 h-6 ${nav.route
                      ? isActive(nav.route)
                        ? "menu-item-icon-active"
                        : "menu-item-icon-inactive"
                      : isPathActive(nav.path)
                        ? "menu-item-icon-active"
                        : "menu-item-icon-inactive"
                    }`}
                >
                  {nav.icon}
                </span>
                {(isExpanded || isHovered || isMobileOpen) && (
                  <span className="menu-item-text">{nav.name}</span>
                )}
              </Link>
            )
          )}
          {nav.subItems && (isExpanded || isHovered || isMobileOpen) && (
            <div
              ref={(el) => {
                subMenuRefs.current[`${menuType}-${index}`] = el;
              }}
              className="overflow-hidden transition-all duration-300"
              style={{
                height:
                  openSubmenu?.type === menuType && openSubmenu?.index === index
                    ? `${subMenuHeight[`${menuType}-${index}`]}px`
                    : "0px",
              }}
            >
              <ul className="mt-2 space-y-1 ml-9">
                {nav.subItems.map((subItem) => (
                  <li key={subItem.name}>
                    <Link
                      href={route(subItem.route)}
                      className={`menu-dropdown-item ${isActive(subItem.route)
                          ? "menu-dropdown-item-active"
                          : "menu-dropdown-item-inactive"
                        }`}
                    >
                      {subItem.name}
                      <span className="flex items-center gap-1 ml-auto">
                        {subItem.new && (
                          <span
                            className={`ml-auto ${isActive(subItem.route)
                                ? "menu-dropdown-badge-active"
                                : "menu-dropdown-badge-inactive"
                              } menu-dropdown-badge`}
                          >
                            new
                          </span>
                        )}
                        {subItem.pro && (
                          <span
                            className={`ml-auto ${isActive(subItem.route)
                                ? "menu-dropdown-badge-active"
                                : "menu-dropdown-badge-inactive"
                              } menu-dropdown-badge`}
                          >
                            pro
                          </span>
                        )}
                      </span>
                    </Link>
                  </li>
                ))}
              </ul>
            </div>
          )}
        </li>
      ))}
    </ul>
  );

  return (
    <aside
      className={`fixed mt-16 flex flex-col lg:mt-0 top-0 px-5 left-0 bg-white dark:bg-gray-900 dark:border-gray-800 text-gray-900 h-screen transition-all duration-300 ease-in-out z-50 border-r border-gray-200 
        ${isExpanded || isMobileOpen
          ? "w-[290px]"
          : isHovered
            ? "w-[290px]"
            : "w-[90px]"
        }
        ${isMobileOpen ? "translate-x-0" : "-translate-x-full"}
        lg:translate-x-0`}
      onMouseEnter={() => !isExpanded && setIsHovered(true)}
      onMouseLeave={() => setIsHovered(false)}
    >
      <div
        className={`py-8 flex ${!isExpanded && !isHovered ? "lg:justify-center" : "justify-start"
          }`}
      >
        <Link href={route("landing")} className="flex items-center gap-2 hover:scale-105 transition-transform duration-200">
          {isExpanded || isHovered || isMobileOpen ? (
            <>
              <ShootingStarIcon className="w-6 h-6 text-yellow-500 animate-pulse" />
              <span className="text-xl font-bold bg-gradient-to-r from-blue-600 to-purple-600 bg-clip-text text-transparent">
                SoleSpace
              </span>
            </>
          ) : (
            <span className="text-lg font-bold bg-gradient-to-r from-blue-600 to-purple-600 bg-clip-text text-transparent">
              SS
            </span>
          )}
        </Link>
      </div>
      <div className="flex flex-1 flex-col overflow-y-auto duration-300 ease-linear no-scrollbar">
        <nav className="mb-6 flex flex-1 flex-col">
          <div className="flex flex-1 flex-col gap-4">
            <div>
              <h2
                className={`mb-4 text-xs uppercase flex leading-[20px] text-gray-400 ${!isExpanded && !isHovered
                    ? "lg:justify-center"
                    : "justify-start"
                  }`}
              >
                {isExpanded || isHovered || isMobileOpen ? (
                  "Menu"
                ) : (
                  <HorizontaLDots className="size-6" />
                )}
              </h2>
              {renderMenuItems(navItems, "main")}
            </div>

            <div>
              <h2
                className={`${isIndividualAccount ? "mb-1" : "mb-4"} text-xs uppercase flex leading-[20px] text-gray-400 ${!isExpanded && !isHovered
                    ? "lg:justify-center"
                    : "justify-start"
                  }`}
              >
                {isExpanded || isHovered || isMobileOpen ? (
                  isIndividualAccount ? individualAccountSectionLabel : companyAccountSectionLabel
                ) : (
                  <HorizontaLDots className="size-6" />
                )}
              </h2>
              {renderMenuItems(productManagementItems, "product")}
            </div>

            {!isIndividualAccount && (
              <div>
                {renderMenuItems(approvalWorkflowItems, "approval")}
              </div>
            )}

            <div>
              <h2
                className={`mb-4 text-xs uppercase flex leading-[20px] text-gray-400 ${!isExpanded && !isHovered
                    ? "lg:justify-center"
                    : "justify-start"
                  }`}
              >
                {isExpanded || isHovered || isMobileOpen ? (
                  "Customer Management"
                ) : (
                  <HorizontaLDots className="size-6" />
                )}
              </h2>
              {renderMenuItems(customerManagementItems, "customer")}
            </div>
          </div>
        </nav>

      </div>
    </aside>
  );
};

export default AppSidebar_shopOwner;
