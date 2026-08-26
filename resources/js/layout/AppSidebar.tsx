import { useCallback, useEffect, useRef, useState } from "react";
import { Link, usePage } from "@inertiajs/react";

// Assume these icons are imported from an icon library
import {
  CheckLineIcon,
  HorizontaLDots,
  ShootingStarIcon,
} from "../icons";
import { useSidebar } from "../context/SidebarContext";

type NavItem = {
  name: string;
  icon: React.ReactNode;
  route?: string;
  capability?: string;
  subItems?: {
    name: string;
    route: string;
    icon?: React.ReactNode;
    pro?: boolean;
    new?: boolean;
    capability?: string;
  }[];
};

const routeFallbacks: Record<string, string> = {
  'admin.system-monitoring': '/admin/system-monitoring',
  'admin.audit': '/admin/audit',
  'admin.administrators.index': '/admin/administrators',
  'admin.business-upgrade-requests.index': '/admin/business-upgrade-requests',
  'admin.registrations.index': '/admin/registrations',
  'admin.document-renewals.index': '/admin/document-renewals',
  'admin.shop-reports': '/admin/shop-reports',
  'admin.suspension-appeals': '/admin/appeals',
  'admin.shops.index': '/admin/shops',
  'admin.subscriptions.index': '/admin/subscriptions',
  'admin.users.index': '/admin/users',
  landing: '/',
};

const AppSidebar: React.FC = () => {
  const { isExpanded, isMobileOpen, isHovered, setIsHovered, openSubmenu, toggleSubmenu } = useSidebar();
  const { url, props } = usePage();
  const auth = (props as { auth?: { super_admin?: { capabilities?: unknown[] } } }).auth;
  const capabilities = new Set(
    Array.isArray(auth?.super_admin?.capabilities)
      ? auth.super_admin.capabilities.filter((capability): capability is string => typeof capability === 'string')
      : [],
  );

  const hasCapability = useCallback(
    (capability?: string) => capability === undefined || capabilities.has(capability),
    [capabilities],
  );

  const resolveRouteHref = useCallback((routeName: string): string | null => {
    try {
      return route(routeName);
    } catch {
      return routeFallbacks[routeName] ?? null;
    }
  }, []);

  const getNavItems = (): NavItem[] => {
    const items: NavItem[] = [
      {
        icon: (
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
            <circle cx="12" cy="12" r="1"></circle>
            <circle cx="19" cy="12" r="1"></circle>
            <circle cx="5" cy="12" r="1"></circle>
            <circle cx="12" cy="5" r="1"></circle>
            <circle cx="12" cy="19" r="1"></circle>
            <circle cx="17.66" cy="6.34" r="1"></circle>
            <circle cx="6.34" cy="17.66" r="1"></circle>
            <circle cx="17.66" cy="17.66" r="1"></circle>
            <circle cx="6.34" cy="6.34" r="1"></circle>
          </svg>
        ),
        name: "Dashboard",
        route: "admin.system-monitoring",
        capability: "view_monitoring",
      },
      {
        icon: (
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
            <circle cx="9" cy="7" r="4"></circle>
            <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
            <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
          </svg>
        ),
        name: "Account Management",
        subItems: [
          { name: "Admin Management", route: "admin.administrators.index", capability: "manage_administrators", pro: false },
          { name: "User Management", route: "admin.users.index", capability: "intervene_accounts", pro: false },
          { name: "Shop Management", route: "admin.registrations.index", capability: "review_registrations", pro: false },
          { name: "Document Renewals", route: "admin.document-renewals.index", capability: "review_registrations", pro: false },
          { name: "Business Upgrade Requests", route: "admin.business-upgrade-requests.index", capability: "review_registrations", pro: false },
          { name: "Shop Reports", route: "admin.shop-reports", capability: "moderate_reports", pro: false },
          { name: "Suspension Appeals", route: "admin.suspension-appeals", capability: "view_appeals", pro: false },
          { name: "Audit History", route: "admin.audit", capability: "view_privileged_audit", pro: false },
        ],
      },
      {
        icon: (
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
            <rect x="3" y="3" width="7" height="7"></rect>
            <rect x="14" y="3" width="7" height="7"></rect>
            <rect x="14" y="14" width="7" height="7"></rect>
            <rect x="3" y="14" width="7" height="7"></rect>
          </svg>
        ),
        name: "Registered Shops",
        route: "admin.shops.index",
        capability: "intervene_accounts",
      },
      {
        icon: (
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
            <rect x="3" y="5" width="18" height="14" rx="2"></rect>
            <path d="M3 7h18"></path>
            <path d="M7 12h4"></path>
            <path d="M7 16h4"></path>
          </svg>
        ),
        name: "Subscription Management",
        route: "admin.subscriptions.index",
        capability: "manage_plans",
      },
    ];

    return items.reduce<NavItem[]>((visibleItems, item) => {
      if (!hasCapability(item.capability)) {
        return visibleItems;
      }

      if (item.subItems) {
        const subItems = item.subItems.filter((subItem) => hasCapability(subItem.capability));
        if (subItems.length === 0) {
          return visibleItems;
        }

        visibleItems.push({ ...item, subItems });
        return visibleItems;
      }

      visibleItems.push(item);
      return visibleItems;
    }, []);
  };

  const navItems = getNavItems();
  const othersItems: NavItem[] = [];

  const [subMenuHeight, setSubMenuHeight] = useState<Record<string, number>>(
    {}
  );
  const subMenuRefs = useRef<Record<string, HTMLDivElement | null>>({});

  const isActive = useCallback(
    (routeName: string) => {
      try {
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

        const routeUrl = resolveRouteHref(routeName);
        if (!routeUrl) return false;
        return url === routeUrl || url.startsWith(routeUrl);
      } catch {
        return false;
      }
    },
    [resolveRouteHref, url]
  );

  const isMenuActive = useCallback(
    (nav: NavItem) => {
      if (nav.route && isActive(nav.route)) return true;
      if (nav.subItems) {
        return nav.subItems.some(sub => isActive(sub.route));
      }
      return false;
    },
    [isActive]
  );

  useEffect(() => {
    let submenuMatched = false;
    let matchedKey: string | null = null;
    
    ["main", "others"].forEach((menuType) => {
      const items = menuType === "main" ? navItems : othersItems;
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

  const handleSubmenuToggle = (index: number, menuType: "main" | "others") => {
    const key = `${menuType}-${index}`;
    toggleSubmenu(key);
  };

  const renderMenuItems = (items: NavItem[], menuType: "main" | "others") => {
    return (
      <ul className="flex flex-col gap-4">
        {items.map((nav, index) => {
          const subItems = nav.subItems?.filter((s) => s.name !== "Create Admin" && hasCapability(s.capability));
          if (nav.subItems && (!subItems || subItems.length === 0)) {
            return null;
          }

          return (
            <li key={nav.name}>
              {subItems ? (
                <button
                  onClick={() => handleSubmenuToggle(index, menuType)}
                  className={`menu-item group ${
                    isMenuActive(nav) || openSubmenu === `${menuType}-${index}`
                      ? "menu-item-active"
                      : "menu-item-inactive"
                  } cursor-pointer ${
                    !isExpanded && !isHovered
                      ? "lg:justify-center"
                      : "lg:justify-start"
                  }`}
                >
                  <span
                    className={`menu-item-icon-size w-6 h-6 ${
                      isMenuActive(nav) || openSubmenu === `${menuType}-${index}`
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
                      className={`ml-auto w-5 h-5 transition-transform duration-200 ${
                        openSubmenu === `${menuType}-${index}`
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
                nav.route && resolveRouteHref(nav.route) && (
                  <Link
                    href={resolveRouteHref(nav.route) as string}
                    className={`menu-item group ${
                      isActive(nav.route) ? "menu-item-active" : "menu-item-inactive"
                    }`}
                  >
                    <span
                      className={`menu-item-icon-size w-6 h-6 ${
                        isActive(nav.route)
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

              {subItems && (isExpanded || isHovered || isMobileOpen) && (
                <div
                  ref={(el) => {
                    subMenuRefs.current[`${menuType}-${index}`] = el;
                  }}
                  className="overflow-hidden transition-all duration-300"
                  style={{
                    height:
                      openSubmenu === `${menuType}-${index}`
                        ? `${subMenuHeight[`${menuType}-${index}`]}px`
                        : "0px",
                  }}
                >
                  <ul className="mt-2 space-y-1 ml-9">
                    {subItems.map((subItem) => {
                      const href = resolveRouteHref(subItem.route);
                      if (!href) return null;

                      return (
                      <li key={subItem.name}>
                        <Link
                          href={href}
                          className={`menu-dropdown-item ${
                            isActive(subItem.route)
                              ? "menu-dropdown-item-active"
                              : "menu-dropdown-item-inactive"
                          }`}
                        >
                          {subItem.icon && (
                            <span className="flex items-center justify-center w-4 h-4">
                              {subItem.icon}
                            </span>
                          )}
                          {subItem.name}
                          <span className="flex items-center gap-1 ml-auto">
                            {isActive(subItem.route) && (
                              <CheckLineIcon className="w-4 h-4 text-green-500" />
                            )}
                            {subItem.new && (
                              <span
                                className={`${
                                  isActive(subItem.route)
                                    ? "menu-dropdown-badge-active"
                                    : "menu-dropdown-badge-inactive"
                                } menu-dropdown-badge`}
                              >
                                new
                              </span>
                            )}
                            {subItem.pro && (
                              <span
                                className={`${
                                  isActive(subItem.route)
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
                      );
                    })}
                  </ul>
                </div>
              )}
            </li>
          );
        })}
      </ul>
    );
  };

  return (
    <aside
      className={`erp-sidebar fixed mt-16 flex flex-col lg:mt-0 top-0 px-5 left-0 h-screen transition-all duration-300 ease-in-out z-50 border-r
        ${
          isExpanded || isMobileOpen
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
        className={`py-8 flex ${
          !isExpanded && !isHovered ? "lg:justify-center" : "justify-start"
        }`}
      >
        <Link href={resolveRouteHref("landing") ?? "/"} className="flex items-center gap-2 hover:scale-105 transition-transform duration-200">
          {isExpanded || isHovered || isMobileOpen ? (
            <>
              <ShootingStarIcon className="w-6 h-6 text-yellow-500 animate-pulse" />
              <span className="text-xl font-bold tracking-tight text-gray-900">
                SoleSpace
              </span>
            </>
          ) : (
            <span className="text-lg font-bold tracking-tight text-gray-900">
              SS
            </span>
          )}
        </Link>
      </div>
      <div className="flex flex-col overflow-y-auto duration-300 ease-linear no-scrollbar">
        <nav className="mb-6">
          <div className="flex flex-col gap-4">
            <div>
              <h2
                className={`mb-4 text-xs uppercase flex leading-[20px] text-gray-400 ${
                  !isExpanded && !isHovered
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
          </div>
        </nav>

      </div>
    </aside>
  );
};

export default AppSidebar;
