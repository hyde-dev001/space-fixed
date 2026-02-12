import { useCallback, useEffect, useRef, useState } from "react";
import { Link, usePage } from "@inertiajs/react";

// Assume these icons are imported from an icon library
import {
  CalenderIcon,
  CheckLineIcon,
  ChevronDownIcon,
  GridIcon,
  HorizontaLDots,
  UserIcon,
  UserCircleIcon,
  ShootingStarIcon,
} from "../icons";
import { useSidebar } from "../context/SidebarContext";

type NavItem = {
  name: string;
  icon: React.ReactNode;
  route?: string; // Changed from path to route
  subItems?: { name: string; route: string; icon?: React.ReactNode; pro?: boolean; new?: boolean; superAdminOnly?: boolean }[];
  superAdminOnly?: boolean; // Flag for super admin only items
};

const AppSidebar: React.FC = () => {
  const { isExpanded, isMobileOpen, isHovered, setIsHovered, openSubmenu, toggleSubmenu } = useSidebar();
  const { url, props } = usePage();
  const auth = (props as any).auth;
  // Check if user is super admin (from super_admin guard) or has super_admin role
  const isSuperAdmin = auth?.superAdmin?.role === 'super_admin' || auth?.user?.role === 'super_admin';

  // Filter nav items based on role
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
        route: "superAdmin.system-monitoring-dashboard",
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
          // Always show Admin Management for super admin
          { name: "Admin Management", route: "admin.admin-management", pro: false },
          { name: "User Management", route: "superAdmin.super-admin-user-management", pro: false },
          { name: "Shop Management", route: "superAdmin.shop-owner-registration-view", pro: false },
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
        route: "admin.registered-shops",
      },
      {
        icon: (
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
          </svg>
        ),
        name: "Notification & Communication Tools",
        route: "superAdmin.notification-communication-tools",
      },
      {
        icon: (
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
            <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V5h14v14zm-5-7h-4v4h4v-4z"></path>
          </svg>
        ),
        name: "Data & Report Access",
        route: "superAdmin.data-report-access",
      },
      
    ];

    // Return all items - the backend middleware will protect restricted routes
    return items;
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

        const routeUrl = route(routeName);
        return url === routeUrl || url.startsWith(routeUrl);
      } catch {
        return false;
      }
    },
    [url]
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
          const subItems = nav.subItems?.filter((s) => s.name !== "Create Admin") || nav.subItems;
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
                    <ChevronDownIcon
                      className={`ml-auto w-5 h-5 transition-transform duration-200 ${
                        openSubmenu === `${menuType}-${index}`
                          ? "rotate-180 text-brand-500"
                          : ""
                      }`}
                    />
                  )}
                </button>
              ) : (
                nav.route && (
                  <Link
                    href={route(nav.route)}
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
                    {subItems.map((subItem) => (
                      <li key={subItem.name}>
                        <Link
                          href={route(subItem.route)}
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
                    ))}
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
      className={`fixed mt-16 flex flex-col lg:mt-0 top-0 px-5 left-0 bg-white dark:bg-gray-900 dark:border-gray-800 text-gray-900 h-screen transition-all duration-300 ease-in-out z-50 border-r border-gray-200 
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
