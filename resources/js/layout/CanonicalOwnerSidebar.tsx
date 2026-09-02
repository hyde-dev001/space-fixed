import { useEffect, useMemo, useRef, useState } from "react";
import type { ReactNode } from "react";
import { Link, usePage } from "@inertiajs/react";
import {
  BarChart3,
  Boxes,
  ChevronDown,
  ClipboardList,
  FileText,
  Home,
  ShoppingBag,
  ShoppingCart,
  Truck,
  Users,
  Wrench,
} from "lucide-react";
import type { LucideIcon } from "lucide-react";
import { useSidebar } from "../context/SidebarContext";
import type { OwnerShellGroup, OwnerShellItem, OwnerShellMetadata } from "../types/ownerShell";

interface CanonicalOwnerSidebarProps {
  metadata: OwnerShellMetadata;
}

const normalizePath = (value: string): string => (
  value.replace(/^https?:\/\/[^/]+/i, "").split("?")[0].replace(/\/$/, "") || "/"
);

const matchesActivePath = (path: string, pattern: string): boolean => {
  const normalizedPattern = normalizePath(pattern);
  return normalizedPattern.endsWith("*")
    ? path.startsWith(normalizedPattern.slice(0, -1))
    : path === normalizedPattern;
};

const isItemActive = (path: string, item: OwnerShellItem): boolean => (
  item.active_matching.some((pattern) => matchesActivePath(path, pattern))
  || (item.children ?? []).some((child) => child.available && isItemActive(path, child))
);

const hasActiveChild = (path: string, item: OwnerShellItem): boolean => (
  (item.children ?? []).some((child) => child.available && isItemActive(path, child))
);

const visibleLabelClass = "transition-opacity duration-200 motion-reduce:transition-none";
const EXPANDED_GROUPS_STORAGE_KEY = "canonicalOwnerSidebarExpandedGroups";

const ITEM_ICONS: Record<string, LucideIcon> = {
  home: Home,
  "assist-center": FileText,
  "action-center": ClipboardList,
  retail: ShoppingBag,
  repair: Wrench,
  "job-orders-retail": ShoppingCart,
  "product-management": ShoppingBag,
  "vouchers-discount": FileText,
  "job-orders-repair": Wrench,
  "warranty-queue": ClipboardList,
  "services-management": Wrench,
  "repair-support": Users,
  cashier: ShoppingCart,
  "retail-pos": ShoppingCart,
  "repair-pos": ShoppingCart,
  "stock-management": Boxes,
  "product-stock": Boxes,
  "repair-materials": Boxes,
  customers: Users,
  finance: BarChart3,
  workforce: Users,
  inventory: Boxes,
  procurement: ShoppingCart,
  logistics: Truck,
  reports: FileText,
  audit: ClipboardList,
};

const defaultExpandedGroupKeys = (groups: OwnerShellGroup[]): Set<string> => (
  new Set(groups.filter((group) => group.default_expanded).map((group) => group.key))
);

const readStoredExpandedGroupKeys = (groups: OwnerShellGroup[]): Set<string> => {
  const defaults = defaultExpandedGroupKeys(groups);

  if (typeof window === "undefined") {
    return defaults;
  }

  try {
    const stored = window.localStorage.getItem(EXPANDED_GROUPS_STORAGE_KEY);
    if (stored === null) {
      return defaults;
    }

    const parsed = JSON.parse(stored);
    if (!Array.isArray(parsed)) {
      return defaults;
    }

    const availableKeys = new Set(groups.map((group) => group.key));
    return new Set(parsed.filter((key): key is string => (
      typeof key === "string" && availableKeys.has(key)
    )));
  } catch {
    return defaults;
  }
};

const CanonicalOwnerSidebar = ({ metadata }: CanonicalOwnerSidebarProps) => {
  const { isExpanded, isMobileOpen, isHovered, setIsHovered } = useSidebar();
  const page = usePage();
  const currentPath = normalizePath(String(page.url || "/"));
  const groups = useMemo<OwnerShellGroup[]>(
    () => [...metadata.groups]
      .filter((group) => group.key !== "settings")
      .sort((left, right) => left.order - right.order),
    [metadata.groups],
  );
  const [expandedGroups, setExpandedGroups] = useState<Set<string>>(
    () => readStoredExpandedGroupKeys(groups),
  );
  const previousGroupKeys = useRef(new Set(groups.map((group) => group.key)));

  useEffect(() => {
    const availableKeys = new Set(groups.map((group) => group.key));
    const newExpandedKeys = groups
      .filter((group) => !previousGroupKeys.current.has(group.key) && group.default_expanded)
      .map((group) => group.key);
    const activeGroupKeys = groups
      .filter((group) => group.items.some((item) => item.available && isItemActive(currentPath, item)))
      .map((group) => group.key);

    setExpandedGroups((current) => {
      const next = new Set([...current].filter((key) => availableKeys.has(key)));
      newExpandedKeys.forEach((key) => next.add(key));
      activeGroupKeys.forEach((key) => next.add(key));
      return next;
    });
    previousGroupKeys.current = availableKeys;
  }, [currentPath, groups]);

  useEffect(() => {
    try {
      window.localStorage.setItem(EXPANDED_GROUPS_STORAGE_KEY, JSON.stringify([...expandedGroups]));
    } catch {
      // Sidebar state is still functional when storage is unavailable.
    }
  }, [expandedGroups]);

  const showLabels = isExpanded || isHovered || isMobileOpen;
  const toggleGroup = (groupKey: string) => {
    setExpandedGroups((current) => {
      const next = new Set(current);
      if (next.has(groupKey)) {
        next.delete(groupKey);
      } else {
        next.add(groupKey);
      }
      return next;
    });
  };

  const renderItem = (item: OwnerShellItem): ReactNode => {
    if (!item.available) {
      return null;
    }

    const active = item.available && isItemActive(currentPath, item);
    const activeChild = item.available && hasActiveChild(currentPath, item);
    const Icon = ITEM_ICONS[item.key] ?? FileText;
    const itemClassName = `flex min-h-10 items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors motion-reduce:transition-none focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#111111] dark:focus-visible:ring-blue-300 ${active
      ? "menu-item-active bg-[#111111] text-white dark:bg-blue-500/15 dark:text-blue-300"
      : "text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-800 dark:hover:text-white"
      }`;

    return (
      <li key={item.key}>
        <Link
          href={item.canonical_url}
          className={itemClassName}
          aria-current={active && !activeChild ? "page" : undefined}
          title={showLabels ? undefined : item.label}
        >
          <Icon
            data-testid={`canonical-owner-item-icon-${item.key}`}
            aria-hidden="true"
            className="h-4 w-4 shrink-0"
          />
          <span className={showLabels ? visibleLabelClass : "sr-only"}>{item.label}</span>
        </Link>
        {showLabels && item.children && item.children.length > 0 && (
          <ul className="ml-4 mt-1 space-y-1 border-l border-gray-200 pl-2 dark:border-gray-700" aria-label={`${item.label} pages`}>
            {item.children.map((child) => renderItem(child))}
          </ul>
        )}
      </li>
    );
  };

  return (
    <aside
      id="canonical-owner-sidebar"
      data-testid="canonical-owner-sidebar"
      className={`fixed top-0 left-0 z-50 flex h-screen flex-col border-r border-gray-200 bg-white px-5 text-gray-900 transition-all duration-300 ease-in-out motion-reduce:transition-none dark:border-gray-800 dark:bg-gray-900 ${isExpanded || isMobileOpen || isHovered ? "w-[290px]" : "w-[90px]"} ${isMobileOpen ? "translate-x-0" : "-translate-x-full"} xl:translate-x-0`}
      onMouseEnter={() => !isExpanded && setIsHovered(true)}
      onMouseLeave={() => setIsHovered(false)}
    >
      <div className={`flex py-8 ${showLabels ? "justify-start" : "justify-center"}`}>
        <Link
          href="/shop-owner/home"
          className="flex items-center gap-2 rounded-lg text-[#111111] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#111111] dark:text-gray-100 dark:focus-visible:ring-gray-300"
          title={showLabels ? undefined : "SoleSpace"}
        >
          <span className="text-xl font-bold tracking-tight">{showLabels ? "SoleSpace" : "SS"}</span>
        </Link>
      </div>

      <nav data-testid="canonical-owner-primary-navigation" aria-label="Shop Owner navigation" className="flex min-h-0 flex-1 flex-col overflow-y-auto pb-6">
        <ul className="space-y-3">
          {groups
            .map((group) => ({ ...group, items: group.items.filter((item) => item.available) }))
            .filter((group) => group.items.length > 0)
            .map((group) => {
            const expanded = expandedGroups.has(group.key);
            const itemsId = `canonical-owner-items-${group.key}`;

            return (
              <li key={group.key}>
                <button
                  type="button"
                  data-testid={`canonical-owner-group-${group.key}`}
                  data-group-key={group.key}
                  className="flex min-h-9 w-full items-center justify-between rounded-lg px-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-400 transition-colors motion-reduce:transition-none hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:hover:bg-gray-800"
                  aria-expanded={expanded}
                  aria-controls={itemsId}
                  title={showLabels ? undefined : group.label}
                  onClick={() => toggleGroup(group.key)}
                >
                  <span className={showLabels ? visibleLabelClass : "sr-only"}>{group.label}</span>
                  <ChevronDown
                    data-testid={`canonical-owner-group-icon-${group.key}`}
                    aria-hidden="true"
                    className={`h-4 w-4 shrink-0 text-gray-400 transition-transform motion-reduce:transition-none ${expanded ? "rotate-180" : ""}`}
                  />
                </button>
                {expanded && (
                  <ul id={itemsId} className="mt-1 space-y-1" aria-label={group.label}>
                    {group.items.map(renderItem)}
                  </ul>
                )}
              </li>
            );
          })}
        </ul>
      </nav>

    </aside>
  );
};

export default CanonicalOwnerSidebar;
