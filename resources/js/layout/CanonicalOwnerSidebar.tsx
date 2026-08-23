import { useEffect, useMemo, useState } from "react";
import { Link, usePage } from "@inertiajs/react";
import {
  BarChart3,
  Boxes,
  ChevronDown,
  ClipboardList,
  CreditCard,
  ExternalLink,
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
);

const visibleLabelClass = "transition-opacity duration-200 motion-reduce:transition-none";

const ITEM_ICONS: Record<string, LucideIcon> = {
  home: Home,
  retail: ShoppingBag,
  repair: Wrench,
  customers: Users,
  payments: CreditCard,
  finance: BarChart3,
  workforce: Users,
  inventory: Boxes,
  procurement: ShoppingCart,
  logistics: Truck,
  reports: FileText,
  audit: ClipboardList,
};

const CanonicalOwnerSidebar: React.FC<CanonicalOwnerSidebarProps> = ({ metadata }) => {
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
    () => new Set(groups.filter((group) => group.default_expanded).map((group) => group.key)),
  );

  useEffect(() => {
    setExpandedGroups(new Set(groups.filter((group) => group.default_expanded).map((group) => group.key)));
  }, [groups]);

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

  const renderItem = (item: OwnerShellItem) => {
    const active = item.available && isItemActive(currentPath, item);
    const Icon = ITEM_ICONS[item.key] ?? FileText;
    const itemClassName = `flex min-h-10 items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors motion-reduce:transition-none focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 ${active
      ? "menu-item-active bg-blue-50 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300"
      : "text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-800 dark:hover:text-white"
      }`;

    if (!item.available) {
      return (
        <li key={item.key} className="space-y-1 rounded-lg px-3 py-2 text-sm text-gray-500 dark:text-gray-400">
          <span className="block font-medium text-gray-700 dark:text-gray-200" title={item.label}>{item.label}</span>
          <span className="block text-xs leading-5">{item.unavailable_reason}</span>
          {item.management_url && (
            <Link
              href={item.management_url}
              className="inline-flex rounded-md text-xs font-semibold text-blue-600 underline underline-offset-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:text-blue-300"
            >
              Manage in Settings
            </Link>
          )}
        </li>
      );
    }

    return (
      <li key={item.key}>
        <Link
          href={item.canonical_url}
          className={itemClassName}
          aria-current={active ? "page" : undefined}
          title={showLabels ? undefined : item.label}
        >
          <Icon
            data-testid={`canonical-owner-item-icon-${item.key}`}
            aria-hidden="true"
            className="h-4 w-4 shrink-0"
          />
          <span className={showLabels ? visibleLabelClass : "sr-only"}>{item.label}</span>
        </Link>
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
          className="flex items-center gap-2 rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
          title={showLabels ? undefined : "SoleSpace"}
        >
          <span className="text-xl font-bold tracking-tight text-blue-700 dark:text-blue-300">{showLabels ? "SoleSpace" : "SS"}</span>
        </Link>
      </div>

      <nav data-testid="canonical-owner-primary-navigation" aria-label="Shop Owner navigation" className="flex min-h-0 flex-1 flex-col overflow-y-auto pb-6">
        <ul className="space-y-3">
          {groups.filter((group) => group.items.length > 0).map((group) => {
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

      {metadata.compatibility.show_erp_fallback && metadata.compatibility.fallback_url && (
        <div data-testid="canonical-owner-compatibility" className="border-t border-gray-200 py-4 dark:border-gray-800">
          <Link
            href={metadata.compatibility.fallback_url}
            aria-label="Open existing ERP Workspace"
            className="flex min-h-10 items-center rounded-lg px-3 py-2 text-sm font-medium text-gray-500 transition-colors motion-reduce:transition-none hover:bg-gray-100 hover:text-gray-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-white"
            title={showLabels ? undefined : "Open existing ERP Workspace"}
          >
            <ExternalLink
              data-testid="canonical-owner-fallback-icon"
              aria-hidden="true"
              className="h-4 w-4 shrink-0"
            />
            <span className={showLabels ? visibleLabelClass : "sr-only"}>Open existing ERP Workspace</span>
          </Link>
        </div>
      )}
    </aside>
  );
};

export default CanonicalOwnerSidebar;
