import { useCallback, useEffect, useMemo, useRef, useState } from "react";
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
import type { ShopModuleKey } from "../types/shopModules";
import { canRenderShopModule } from "../utils/shopModuleAccess";

type NavItem = {
  name: string;
  icon: React.ReactNode;
  route?: string; // Changed from 'path' to 'route' to use Laravel route names
  path?: string;
  moduleKey?: ShopModuleKey;
  subItems?: { name: string; route: string; moduleKey?: ShopModuleKey; pro?: boolean; new?: boolean }[];
};

type OwnerErpPage = {
  label: string;
  routeName: string;
  url: string;
  groupKey?: string | null;
  groupLabel?: string | null;
  groupOrder?: number | null;
  pageOrder?: number | null;
};

type OwnerErpModule = {
  key: string;
  slug: string;
  label: string;
  description?: string;
  pages: OwnerErpPage[];
};

type AppSidebarShopOwnerProps = {
  activeModule?: OwnerErpModule | null;
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
        <path d="M9 3H5a2 2 0 0 0-2 2v4m6-6h10a2 2 0 0 1 2 2v4M9 3v18m0 0h10a2 2 0 0 0 2-2V9M9 21H5a2 2 0 0 1-2-2V9m0 0h18" />
      </svg>
    ),
    name: "Suspend Accounts",
    route: "shopOwner.suspend-accounts",
    path: "/shopOwner/suspend-accounts",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <rect x="2" y="3" width="20" height="14" rx="2"></rect>
        <path d="M8 21h8m-4-4v4"></path>
        <path d="M7 8h.01M11 8h6M7 12h4m2 0h3"></path>
      </svg>
    ),
    name: "Assist Center",
    route: "shop-owner.dss-insights",
    path: "/shop-owner/dss-insights",
  },
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <path d="M21 8.5V6a2 2 0 0 0-2-2h-5.5a2 2 0 0 0-1.414.586L3.5 13.172a2 2 0 0 0 0 2.828l4.5 4.5a2 2 0 0 0 2.828 0l8.586-8.586A2 2 0 0 0 20 10.5V8.5z"></path>
        <circle cx="16" cy="8" r="1"></circle>
        <path d="M8 12h8"></path>
        <path d="M12 8v8"></path>
      </svg>
    ),
    name: "Vouchers & Discount",
    route: "shop-owner.vouchers-discount",
    path: "/shop-owner/vouchers-discount",
  },
];

const approvalWorkflowItems: NavItem[] = [
  {
    icon: (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M9 12h6M9 16h6M9 8h6" />
        <path d="M7 4h10a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z" />
      </svg>
    ),
    name: "Approval Pages",
    subItems: [
      { name: "Refund Approval", route: "shop-owner.refund-approvals", moduleKey: "finance" },
      { name: "Price Approvals", route: "shop-owner.price-approvals", moduleKey: "finance" },
      { name: "Payslip Approval", route: "shop-owner.payslip-approvals", moduleKey: "finance" },
      { name: "Salary Adjustment Approval", route: "shop-owner.salary-adjustment-approvals", moduleKey: "finance" },
      { name: "Purchase Request Approval", route: "shop-owner.purchase-request-approval", moduleKey: "procurement" },
      { name: "Expense Approvals", route: "shop-owner.expense-approvals", moduleKey: "finance" },
      { name: "Repair Reject Approval", route: "shop-owner.repair-reject-approval", moduleKey: "repair_operations" },
    ],
  },
];

type MenuType = "main" | "approval";
const SIDEBAR_PREFETCH: Array<"hover"> = ["hover"];
const SIDEBAR_PREFETCH_CACHE = "30s";

const normalizePath = (value: string): string => value
  .replace(/^https?:\/\/[^/]+/i, "")
  .split("?")[0]
  .replace(/\/$/, "") || "/";

const AppSidebar_shopOwner: React.FC<AppSidebarShopOwnerProps> = ({ activeModule: activeModuleProp }) => {
  const { isExpanded, isMobileOpen, isHovered, setIsHovered, openSubmenu, toggleSubmenu } = useSidebar();
  const { url, props } = usePage();
  const auth = (props as any).auth;
  const shopOwner = auth?.shop_owner || auth?.user?.shop_owner || (props as any)?.shop_owner;
  const authModuleStates = auth?.shopModules;
  const sharedModuleStates = (props as any)?.moduleStates;
  const currentPath = normalizePath(url);
  const isModuleScopedErpRoute = /^\/shop-owner\/erp\/(?!workspace(?:\/|$))/i.test(currentPath);
  const activeModule = isModuleScopedErpRoute
    ? activeModuleProp ?? ((props as any)?.activeModule as OwnerErpModule | null | undefined) ?? null
    : null;
  const shopModules = authModuleStates && typeof authModuleStates === "object" && Object.keys(authModuleStates).length > 0
    ? authModuleStates
    : sharedModuleStates;
  const moduleEnforcementEnabled = auth?.shopModuleEnforcementEnabled
    ?? (props as any)?.shopModuleEnforcementEnabled
    ?? Boolean(shopModules);
  const erpUrls = (props as any)?.erpUrls as { workspace?: string | null } | undefined;
  const isCompanyAccount = shopOwner?.is_company === true
    || shopOwner?.registration_type?.toLowerCase() === "company";
  const ownerWorkspaceUrl = isCompanyAccount && typeof erpUrls?.workspace === "string"
    ? erpUrls.workspace
    : null;
  const mainMenuItems = useMemo<NavItem[]>(() => ownerWorkspaceUrl
    ? [
        navItems[0],
        {
          icon: (
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
              <rect x="3" y="3" width="7" height="7" rx="1" />
              <rect x="14" y="3" width="7" height="7" rx="1" />
              <rect x="3" y="14" width="7" height="7" rx="1" />
              <rect x="14" y="14" width="7" height="7" rx="1" />
            </svg>
          ),
          name: "ERP Workspace",
          path: ownerWorkspaceUrl,
        },
        ...navItems.slice(1),
      ]
    : navItems, [ownerWorkspaceUrl]);
  const businessAccountSectionLabel = "Approval Workflow";

  const [subMenuHeight, setSubMenuHeight] = useState<Record<string, number>>(
    {}
  );
  const subMenuRefs = useRef<Record<string, HTMLDivElement | null>>({});
  const [openModuleGroup, setOpenModuleGroup] = useState<string | null>(null);

  // Check if menu item should be visible based on shop owner's registration and business type
  const isModuleVisible = useCallback((menuItem: { moduleKey?: ShopModuleKey }) => {
    return canRenderShopModule(shopModules, menuItem.moduleKey, moduleEnforcementEnabled);
  }, [shopModules, moduleEnforcementEnabled]);

  const isSubItemVisible = useCallback((subItem: { route: string; moduleKey?: ShopModuleKey }) => {
    if (!isModuleVisible(subItem)) {
      return false;
    }

    if (!shopOwner) {
      return subItem.route !== 'shop-owner.repair-reject-approval';
    }

    const rawSubItemBusinessType = String(shopOwner.business_type || '').toLowerCase();
    const subItemBusinessType = rawSubItemBusinessType.includes('both') ? 'both' : rawSubItemBusinessType;
    const isCompanySubItem = shopOwner.is_company === true || shopOwner.registration_type?.toLowerCase() === 'company';
    const canManageStaffSubItem = shopOwner.can_manage_staff === true;

    if (subItem.route === 'shop-owner.repair-reject-approval') {
      return (isCompanySubItem || canManageStaffSubItem)
        && (subItemBusinessType === 'repair' || subItemBusinessType === 'both');
    }

    return true;
  }, [isModuleVisible, shopOwner]);

  const isMenuItemVisible = useCallback((menuItem: NavItem) => {
    if (!isModuleVisible(menuItem)) {
      return false;
    }

    if (!shopOwner) return true; // Show all if no shop owner data

    const rawBusinessType = shopOwner.business_type?.toLowerCase();
    const itemBusinessType = rawBusinessType?.includes('both') ? 'both' : rawBusinessType;
    const isCompany = shopOwner.is_company === true;
    const canManageStaff = shopOwner.can_manage_staff === true;

    if (menuItem.route === 'shop-owner.dss-insights') {
      return true;
    }

    if (menuItem.route === 'shop-owner.vouchers-discount') {
      return itemBusinessType === 'retail' || itemBusinessType === 'both';
    }

    const companyOnlyRoutes = [
      'shopOwner.user-access-control',
      'shop-owner.audit-logs',
      'shop-owner.price-approvals',
      'shop-owner.salary-adjustment-approvals',
      'shop-owner.purchase-request-approval',
      'shopOwner.suspend-accounts'
    ];

    if (menuItem.route && companyOnlyRoutes.includes(menuItem.route)) {
      return isCompany || canManageStaff;
    }

    return true;
  }, [isModuleVisible, shopOwner]);

  const hasVisibleMenuItems = useCallback((items: NavItem[]) => (
    items.some((item) => (
      isMenuItemVisible(item)
      && (!item.subItems || item.subItems.some(isSubItemVisible))
    ))
  ), [isMenuItemVisible, isSubItemVisible]);

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

      const currentPath = normalizePath(url);
      const targetPath = normalizePath(path);

      return currentPath === targetPath || currentPath.startsWith(`${targetPath}/`);
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

  const isScopedPageActive = useCallback((pageUrl: string) => {
    const currentPath = normalizePath(url);
    const pagePath = normalizePath(pageUrl);

    return currentPath === pagePath || currentPath.startsWith(`${pagePath}/`);
  }, [url]);

  const scopedModuleNavigation = useMemo(() => {
    const directPages: OwnerErpPage[] = [];
    const groups = new Map<string, {
      key: string;
      label: string;
      order: number;
      pages: OwnerErpPage[];
    }>();

    activeModule?.pages.forEach((page) => {
      if (!page.groupKey) {
        directPages.push(page);
        return;
      }

      const existing = groups.get(page.groupKey);
      if (existing) {
        existing.pages.push(page);
        return;
      }

      groups.set(page.groupKey, {
        key: page.groupKey,
        label: page.groupLabel || page.groupKey,
        order: page.groupOrder ?? Number.MAX_SAFE_INTEGER,
        pages: [page],
      });
    });

    const sortPages = (pages: OwnerErpPage[]) => pages.sort((left, right) => (
      (left.pageOrder ?? Number.MAX_SAFE_INTEGER) - (right.pageOrder ?? Number.MAX_SAFE_INTEGER)
    ));

    sortPages(directPages);
    const groupedPages = Array.from(groups.values())
      .map((group) => ({ ...group, pages: sortPages(group.pages) }))
      .sort((left, right) => left.order - right.order);

    return { directPages, groupedPages };
  }, [activeModule]);

  useEffect(() => {
    const activeGroup = scopedModuleNavigation.groupedPages.find((group) => (
      group.pages.some((page) => isScopedPageActive(page.url))
    ));

    setOpenModuleGroup(activeGroup?.key ?? null);
  }, [isScopedPageActive, scopedModuleNavigation]);

  const renderScopedModuleItems = () => {
    if (!activeModule) {
      return null;
    }

    return (
      <div>
        <h2
          className={`mb-4 text-xs uppercase flex leading-[20px] text-gray-400 ${!isExpanded && !isHovered
              ? "lg:justify-center"
              : "justify-start"
            }`}
        >
          {isExpanded || isHovered || isMobileOpen ? (
            activeModule.label
          ) : (
            <HorizontaLDots className="size-6" />
          )}
        </h2>
        <ul className="flex flex-col gap-4">
          {scopedModuleNavigation.directPages.map((page) => {
            const active = isScopedPageActive(page.url);

            return (
              <li key={page.routeName}>
                <Link
                  href={page.url}
                  prefetch={SIDEBAR_PREFETCH}
                  cacheFor={SIDEBAR_PREFETCH_CACHE}
                  className={`menu-item group ${active ? "menu-item-active" : "menu-item-inactive"}`}
                >
                  <span className={`menu-item-icon-size w-6 h-6 ${active ? "menu-item-icon-active" : "menu-item-icon-inactive"}`}>
                    <BoxIcon className="w-5 h-5" />
                  </span>
                  {(isExpanded || isHovered || isMobileOpen) && (
                    <span className="menu-item-text">{page.label}</span>
                  )}
                </Link>
              </li>
            );
          })}
          {scopedModuleNavigation.groupedPages.map((group) => {
            const isOpen = openModuleGroup === group.key;

            return (
              <li key={group.key}>
                <button
                  type="button"
                  aria-expanded={isOpen}
                  onClick={() => setOpenModuleGroup((current) => current === group.key ? null : group.key)}
                  className={`menu-item group ${isOpen ? "menu-item-active" : "menu-item-inactive"} cursor-pointer ${!isExpanded && !isHovered
                      ? "lg:justify-center"
                      : "lg:justify-start"
                    }`}
                >
                  <span className={`menu-item-icon-size w-6 h-6 ${isOpen ? "menu-item-icon-active" : "menu-item-icon-inactive"}`}>
                    <BoxIcon className="w-5 h-5" />
                  </span>
                  {(isExpanded || isHovered || isMobileOpen) && (
                    <span className="menu-item-text">{group.label}</span>
                  )}
                  {(isExpanded || isHovered || isMobileOpen) && (
                    <svg
                      className={`ml-auto w-5 h-5 transition-transform duration-200 ${isOpen ? "rotate-180 text-brand-500" : ""}`}
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
                {isOpen && (isExpanded || isHovered || isMobileOpen) && (
                  <ul className="mt-2 space-y-1 ml-9">
                    {group.pages.map((page) => {
                      const active = isScopedPageActive(page.url);

                      return (
                        <li key={page.routeName}>
                          <Link
                            href={page.url}
                            prefetch={SIDEBAR_PREFETCH}
                            cacheFor={SIDEBAR_PREFETCH_CACHE}
                            className={`menu-dropdown-item ${active ? "menu-dropdown-item-active" : "menu-dropdown-item-inactive"}`}
                          >
                            {page.label}
                          </Link>
                        </li>
                      );
                    })}
                  </ul>
                )}
              </li>
            );
          })}
        </ul>
      </div>
    );
  };
  useEffect(() => {
    let submenuMatched = false;
    let matchedKey: string | null = null;

    (["main", "approval"] as const).forEach((menuType) => {
      const items = menuType === "main" ? mainMenuItems : approvalWorkflowItems;
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
  }, [url, isActive, mainMenuItems]);

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
      {items.filter((item) => (
        isMenuItemVisible(item)
        && (!item.subItems || item.subItems.some(isSubItemVisible))
      )).map((nav, index) => {
        const active = nav.route ? isActive(nav.route) : isPathActive(nav.path);

        return (
          <li key={nav.name}>
          {nav.subItems ? (
            <button
              onClick={() => handleSubmenuToggle(index, menuType)}
              className={`menu-item group ${openSubmenu === `${menuType}-${index}`
                  ? "menu-item-active"
                  : "menu-item-inactive"
                } cursor-pointer ${!isExpanded && !isHovered
                  ? "lg:justify-center"
                  : "lg:justify-start"
                }`}
            >
              <span
                className={`menu-item-icon-size w-6 h-6 ${openSubmenu === `${menuType}-${index}`
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
                  className={`ml-auto w-5 h-5 transition-transform duration-200 ${openSubmenu === `${menuType}-${index}`
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
                prefetch={SIDEBAR_PREFETCH}
                cacheFor={SIDEBAR_PREFETCH_CACHE}
                aria-current={active ? "page" : undefined}
                className={`menu-item group ${active
                    ? "menu-item-active lg:border-l-2 lg:border-brand-500 lg:shadow-theme-sm"
                    : "menu-item-inactive lg:border-l-2 lg:border-transparent"
                  }`}
              >
                <span
                  className={`menu-item-icon-size w-6 h-6 ${active
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
                  openSubmenu === `${menuType}-${index}`
                    ? `${subMenuHeight[`${menuType}-${index}`]}px`
                    : "0px",
              }}
            >
              <ul className="mt-2 space-y-1 ml-9">
                {nav.subItems.filter(isSubItemVisible).map((subItem) => (
                  <li key={subItem.name}>
                    <Link
                      href={route(subItem.route)}
                      prefetch={SIDEBAR_PREFETCH}
                      cacheFor={SIDEBAR_PREFETCH_CACHE}
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
        );
      })}
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
                <Link
          href={route("landing")}
          prefetch={SIDEBAR_PREFETCH}
          cacheFor={SIDEBAR_PREFETCH_CACHE}
          className="flex items-center gap-2 hover:scale-105 transition-transform duration-200"
        >
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
              {activeModule ? (
                <>
                  {renderMenuItems(mainMenuItems.filter((item) => item.name === "ERP Workspace"), "main")}
                  {renderScopedModuleItems()}
                </>
              ) : (
                <>
                  {renderMenuItems(mainMenuItems, "main")}

                  {hasVisibleMenuItems(approvalWorkflowItems) && (
                    <div>
                      <h2
                        className={`mb-4 text-xs uppercase flex leading-[20px] text-gray-400 ${!isExpanded && !isHovered
                            ? "lg:justify-center"
                            : "justify-start"
                          }`}
                      >
                        {isExpanded || isHovered || isMobileOpen ? (
                          businessAccountSectionLabel
                        ) : (
                          <HorizontaLDots className="size-6" />
                        )}
                      </h2>
                      {renderMenuItems(approvalWorkflowItems, "approval")}
                    </div>
                  )}
                </>
              )}
            </div>
          </div>
        </nav>

      </div>
    </aside>
  );
};

export default AppSidebar_shopOwner;
