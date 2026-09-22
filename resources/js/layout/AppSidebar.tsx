import { useCallback } from "react";
import { Link, usePage } from "@inertiajs/react";

import {
  AlertIcon,
  CurrencyDollarIcon,
  DocsIcon,
  GroupIcon,
  GridIcon,
  HorizontaLDots,
  LockIcon,
  PieChartIcon,
  ShootingStarIcon,
  TaskIcon,
  TimeIcon,
  UserIcon,
} from "../icons";
import { useSidebar } from "../context/SidebarContext";

type NavItem = {
  name: string;
  icon: React.ReactNode;
  section: string;
  route: string;
  capability?: string;
  pageKey?: string;
};

const routeFallbacks: Record<string, string> = {
  "admin.system-monitoring": "/admin/system-monitoring",
  "admin.maintenance.index": "/admin/maintenance",
  "admin.audit": "/admin/audit",
  "admin.administrators.index": "/admin/administrators",
  "admin.business-upgrade-requests.index": "/admin/business-upgrade-requests",
  "admin.registrations.index": "/admin/registrations",
  "admin.document-renewals.index": "/admin/document-renewals",
  "admin.shop-reports": "/admin/shop-reports",
  "admin.suspension-appeals": "/admin/appeals",
  "admin.shops.index": "/admin/shops",
  "admin.subscriptions.index": "/admin/subscriptions",
  "admin.platform-fees.index": "/admin/platform-fees",
  "admin.users.index": "/admin/users",
  landing: "/",
};

const AppSidebar: React.FC = () => {
  const { isExpanded, isMobileOpen, isHovered, setIsHovered } = useSidebar();
  const { url, props } = usePage();
  const auth = (props as {
    auth?: {
      super_admin?: {
        role?: string;
        capabilities?: unknown[];
        page_permissions?: unknown[];
      };
    };
  }).auth;

  const capabilities = new Set(
    Array.isArray(auth?.super_admin?.capabilities)
      ? auth.super_admin.capabilities.filter((capability): capability is string => typeof capability === "string")
      : [],
  );
  const pagePermissions = new Set(
    Array.isArray(auth?.super_admin?.page_permissions)
      ? auth.super_admin.page_permissions.filter((page): page is string => typeof page === "string")
      : [],
  );
  const hasPagePermissionPayload = Array.isArray(auth?.super_admin?.page_permissions);

  const hasCapability = useCallback(
    (capability?: string) => capability === undefined || capabilities.has(capability),
    [capabilities],
  );
  const hasPage = useCallback(
    (pageKey?: string) => {
      if (pageKey === undefined || pageKey === "dashboard") return true;
      if (auth?.super_admin?.role === "super_admin") return true;

      return !hasPagePermissionPayload || pagePermissions.has(pageKey);
    },
    [auth?.super_admin?.role, hasPagePermissionPayload, pagePermissions],
  );

  const resolveRouteHref = useCallback((routeName: string): string | null => {
    try {
      return route(routeName);
    } catch {
      return routeFallbacks[routeName] ?? null;
    }
  }, []);

  const navItems: NavItem[] = [
    {
      section: "OVERVIEW",
      name: "Dashboard",
      icon: (
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <circle cx="12" cy="12" r="1" />
          <circle cx="19" cy="12" r="1" />
          <circle cx="5" cy="12" r="1" />
          <circle cx="12" cy="5" r="1" />
          <circle cx="12" cy="19" r="1" />
          <circle cx="17.66" cy="6.34" r="1" />
          <circle cx="6.34" cy="17.66" r="1" />
          <circle cx="17.66" cy="17.66" r="1" />
          <circle cx="6.34" cy="6.34" r="1" />
        </svg>
      ),
      route: "admin.system-monitoring",
      capability: "view_monitoring",
      pageKey: "dashboard",
    },
    {
      section: "PEOPLE & ACCESS",
      name: "Admin Management",
      icon: <GroupIcon className="w-5 h-5" />,
      route: "admin.administrators.index",
      capability: "manage_administrators",
      pageKey: "admin_management",
    },
    {
      section: "PEOPLE & ACCESS",
      name: "User Management",
      icon: <UserIcon className="w-5 h-5" />,
      route: "admin.users.index",
      capability: "intervene_accounts",
      pageKey: "user_management",
    },
    {
      section: "SHOP OPERATIONS",
      name: "Registered Shops",
      icon: <GridIcon className="w-5 h-5" />,
      route: "admin.shops.index",
      capability: "intervene_accounts",
      pageKey: "registered_shops",
    },
    {
      section: "SHOP OWNER APPROVALS",
      name: "Shop Management",
      icon: <GroupIcon className="w-5 h-5" />,
      route: "admin.registrations.index",
      capability: "review_registrations",
      pageKey: "shop_management",
    },
    {
      section: "SHOP OWNER APPROVALS",
      name: "Document Renewals",
      icon: <DocsIcon className="w-5 h-5" />,
      route: "admin.document-renewals.index",
      capability: "review_registrations",
      pageKey: "document_renewals",
    },
    {
      section: "SHOP OWNER APPROVALS",
      name: "Business Upgrade Requests",
      icon: <TaskIcon className="w-5 h-5" />,
      route: "admin.business-upgrade-requests.index",
      capability: "review_registrations",
      pageKey: "business_upgrade_requests",
    },
    {
      section: "USER APPROVALS & APPEALS",
      name: "Suspension Appeals",
      icon: <AlertIcon className="w-5 h-5" />,
      route: "admin.suspension-appeals",
      capability: "view_appeals",
      pageKey: "suspension_appeals",
    },
    {
      section: "REPORTS & AUDIT",
      name: "Shop Reports",
      icon: <PieChartIcon className="w-5 h-5" />,
      route: "admin.shop-reports",
      capability: "moderate_reports",
      pageKey: "shop_reports",
    },
    {
      section: "REPORTS & AUDIT",
      name: "Audit History",
      icon: <LockIcon className="w-5 h-5" />,
      route: "admin.audit",
      capability: "view_privileged_audit",
      pageKey: "audit_history",
    },
    {
      section: "PLATFORM ADMINISTRATION",
      name: "System Maintenance",
      icon: <TimeIcon className="w-5 h-5" />,
      route: "admin.maintenance.index",
      capability: "view_platform_maintenance",
      pageKey: "system_maintenance",
    },
    {
      section: "PLATFORM ADMINISTRATION",
      name: "Subscription Management",
      icon: (
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <rect x="3" y="5" width="18" height="14" rx="2" />
          <path d="M3 7h18" />
          <path d="M7 12h4" />
          <path d="M7 16h4" />
        </svg>
      ),
      route: "admin.subscriptions.index",
      capability: "manage_plans",
      pageKey: "subscription_management",
    },
    {
      section: "PLATFORM ADMINISTRATION",
      name: "Platform Fees",
      icon: <CurrencyDollarIcon className="w-5 h-5" />,
      route: "admin.platform-fees.index",
      capability: "manage_platform_fees",
      pageKey: "platform_fees",
    },
  ].filter((item) => hasCapability(item.capability) && hasPage(item.pageKey));

  const isActive = useCallback(
    (routeName: string) => {
      try {
        try {
          if (typeof route === "function") {
            const router = (route as any)();
            if (typeof router.current === "function" && router.current(routeName)) {
              return true;
            }
          }
        } catch {
          // Fall back to URL comparison when the router helper is unavailable.
        }

        const routeUrl = resolveRouteHref(routeName);
        return routeUrl ? url === routeUrl || url.startsWith(routeUrl) : false;
      } catch {
        return false;
      }
    },
    [resolveRouteHref, url],
  );

  const renderMenuItems = (items: NavItem[]) => (
    <ul className="flex flex-col gap-4">
      {items.map((item) => {
        const href = resolveRouteHref(item.route);
        if (!href) return null;

        const active = isActive(item.route);

        return (
          <li key={item.name}>
            <Link
              href={href}
              viewTransition
              aria-current={active ? "page" : undefined}
              className={[
                "menu-item group",
                active ? "menu-item-active" : "menu-item-inactive",
              ].join(" ")}
            >
              <span
                className={[
                  "menu-item-icon-size w-6 h-6",
                  active ? "menu-item-icon-active" : "menu-item-icon-inactive",
                ].join(" ")}
              >
                {item.icon}
              </span>
              {(isExpanded || isHovered || isMobileOpen) && (
                <span className="menu-item-text">{item.name}</span>
              )}
            </Link>
          </li>
        );
      })}
    </ul>
  );

  const renderSectionedMenuItems = (items: NavItem[]) => {
    const sections = items.reduce<Array<{ label: string; items: NavItem[] }>>((groups, item) => {
      const current = groups[groups.length - 1];
      if (current?.label === item.section) {
        current.items.push(item);
      } else {
        groups.push({ label: item.section, items: [item] });
      }
      return groups;
    }, []);

    return (
      <div className="space-y-6">
        {sections.map((section) => (
          <div key={section.label} className="space-y-3">
            {(isExpanded || isHovered || isMobileOpen) && (
              <h3 className="px-2 text-[11px] font-semibold uppercase tracking-[0.16em] text-gray-400 dark:text-gray-500">
                {section.label}
              </h3>
            )}
            {renderMenuItems(section.items)}
          </div>
        ))}
      </div>
    );
  };

  const sidebarWidth = isExpanded || isMobileOpen || isHovered ? "w-[290px]" : "w-[90px]";
  const sidebarVisibility = isMobileOpen ? "translate-x-0" : "-translate-x-full";

  return (
    <aside
      className={[
        "erp-sidebar fixed mt-16 flex flex-col lg:mt-0 top-0 px-5 left-0 bg-white dark:bg-gray-900 dark:border-gray-800 text-gray-900 h-screen transition-all duration-300 ease-in-out z-50 border-r border-gray-200",
        sidebarWidth,
        sidebarVisibility,
        "lg:translate-x-0",
      ].join(" ")}
      onMouseEnter={() => !isExpanded && setIsHovered(true)}
      onMouseLeave={() => setIsHovered(false)}
    >
      <div className={[
        "py-8 flex",
        !isExpanded && !isHovered ? "lg:justify-center" : "justify-start",
      ].join(" ")}>
        <Link
          href={resolveRouteHref("landing") ?? "/"}
          className="flex items-center gap-2 hover:scale-105 transition-transform duration-200"
        >
          {isExpanded || isHovered || isMobileOpen ? (
            <>
              <ShootingStarIcon className="h-6 w-6 text-gray-900 dark:text-gray-100" />
              <span className="text-xl font-bold text-gray-900 dark:text-gray-100">SoleSpace</span>
            </>
          ) : (
            <span className="text-lg font-bold text-gray-900 dark:text-gray-100">SS</span>
          )}
        </Link>
      </div>

      <div className="flex flex-col overflow-y-auto duration-300 ease-linear no-scrollbar">
        <nav className="mb-6">
          <div className="flex flex-col gap-4">
            <div>
              <h2 className={[
                "mb-4 text-xs uppercase flex leading-[20px] text-gray-400",
                !isExpanded && !isHovered ? "lg:justify-center" : "justify-start",
              ].join(" ")}>
                {isExpanded || isHovered || isMobileOpen ? "Menu" : <HorizontaLDots className="size-6" />}
              </h2>
              {renderSectionedMenuItems(navItems)}
            </div>
          </div>
        </nav>
      </div>
    </aside>
  );
};

export default AppSidebar;
