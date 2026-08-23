import { useEffect, useRef, useState } from "react";
import { Link, usePage } from "@inertiajs/react";
import { route } from "ziggy-js";
import { useSidebar } from "../context/SidebarContext";
import { ThemeToggleButton } from "../components/common/ThemeToggleButton";
import NotificationBell from "../components/common/NotificationBell";
import ShopOwnerDropdown from "../components/header/ShopOwnerDropdown";
import type { ErpActor, ErpUrls } from "../types/erp";

interface CanonicalOwnerHeaderProps {
  menuButtonRef: React.RefObject<HTMLButtonElement>;
  hideHeader?: boolean;
}

const CanonicalOwnerHeader: React.FC<CanonicalOwnerHeaderProps> = ({ menuButtonRef, hideHeader = false }) => {
  const page = usePage();
  const props = page.props as Record<string, unknown>;
  const auth = (props.auth && typeof props.auth === "object" ? props.auth : {}) as Record<string, unknown>;
  const erpActor = auth.erpActor as ErpActor | undefined;
  const erpUrls = props.erpUrls as Partial<ErpUrls> | undefined;
  const canonicalSettingsUrl = route("shop-owner.shell.settings.profile");
  const canonicalOwnerUrls: Partial<ErpUrls> = {
    ...(erpUrls ?? {}),
    profile: canonicalSettingsUrl,
    settings: canonicalSettingsUrl,
  };
  const [isApplicationMenuOpen, setApplicationMenuOpen] = useState(false);
  const { isExpanded, isMobileOpen, toggleSidebar, toggleMobileSidebar } = useSidebar();
  const inputRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    const handleKeyDown = (event: KeyboardEvent) => {
      if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === "k") {
        event.preventDefault();
        inputRef.current?.focus();
      }
    };

    document.addEventListener("keydown", handleKeyDown);
    return () => document.removeEventListener("keydown", handleKeyDown);
  }, []);

  if (hideHeader) {
    return null;
  }

  const handleToggle = () => {
    if (window.innerWidth >= 1024) {
      toggleSidebar();
    } else {
      toggleMobileSidebar();
    }
  };

  return (
    <header className="sticky top-0 z-40 flex w-full border-b border-gray-200 bg-white/90 backdrop-blur-md motion-reduce:transition-none dark:border-gray-800 dark:bg-gray-900/90">
      <div className="flex w-full items-center justify-between gap-3 px-3 py-3 sm:px-6 sm:py-4">
        <div className="flex min-w-0 items-center gap-3">
          <button
            ref={menuButtonRef}
            type="button"
            className="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-gray-200 text-gray-500 transition-colors motion-reduce:transition-none hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:border-gray-800 dark:text-gray-300 dark:hover:bg-gray-800"
            onClick={handleToggle}
            aria-label="Toggle Sidebar"
            aria-expanded={isMobileOpen || isExpanded}
            aria-controls="canonical-owner-sidebar"
          >
            <span aria-hidden="true" className="text-xl leading-none">{isMobileOpen ? "×" : "☰"}</span>
          </button>
          <Link href="/shop-owner/home" className="truncate text-lg font-bold tracking-tight text-gray-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:text-white">
            SoleSpace
          </Link>
          <div className="hidden lg:block">
            <label htmlFor="canonical-owner-search" className="sr-only">Search</label>
            <input
              ref={inputRef}
              id="canonical-owner-search"
              type="search"
              placeholder="Search or type command..."
              className="h-10 w-72 rounded-lg border border-gray-200 bg-transparent px-3 text-sm text-gray-800 outline-none transition-colors motion-reduce:transition-none focus:border-blue-400 focus:ring-2 focus:ring-blue-500/20 dark:border-gray-800 dark:text-white"
            />
          </div>
        </div>

        <button
          type="button"
          className="rounded-lg p-2 text-gray-500 transition-colors motion-reduce:transition-none hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:text-gray-300 dark:hover:bg-gray-800 lg:hidden"
          aria-label="Toggle Application Menu"
          aria-expanded={isApplicationMenuOpen}
          onClick={() => setApplicationMenuOpen((current) => !current)}
        >
          ⋯
        </button>

        <div className={`${isApplicationMenuOpen ? "flex" : "hidden"} items-center gap-2 lg:flex`}>
          <NotificationBell basePath="/api/shop-owner/notifications" iconSize={24} />
          <ThemeToggleButton />
          <ShopOwnerDropdown actor={erpActor} urls={canonicalOwnerUrls} />
        </div>
      </div>
    </header>
  );
};

export default CanonicalOwnerHeader;
