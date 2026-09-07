import { useEffect, useRef, useState } from "react";
import { Link, usePage } from "@inertiajs/react";
import { route } from "ziggy-js";
import { useSidebar } from "../context/SidebarContext";
import { ThemeToggleButton } from "../components/common/ThemeToggleButton";
import NotificationBell from "../components/common/NotificationBell";
import ShopOwnerDropdown from "../components/header/ShopOwnerDropdown";
import ErpCommandSearch from "../components/header/ErpCommandSearch";
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
    profile: typeof erpUrls?.profile === "string" ? erpUrls.profile : route("shop-owner.shop-profile"),
    settings: canonicalSettingsUrl,
  };
  const [isApplicationMenuOpen, setApplicationMenuOpen] = useState(false);
  const [isMobileSearchOpen, setMobileSearchOpen] = useState(false);
  const { isExpanded, isMobileOpen, toggleSidebar, toggleMobileSidebar } = useSidebar();
  const inputRef = useRef<HTMLInputElement>(null);
  const mobileInputRef = useRef<HTMLInputElement>(null);

  const openMobileSearch = () => {
    setMobileSearchOpen(true);
    window.requestAnimationFrame(() => mobileInputRef.current?.focus());
  };

  useEffect(() => {
    const handleKeyDown = (event: KeyboardEvent) => {
      if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === "k") {
        event.preventDefault();
        if (window.innerWidth >= 1024) {
          inputRef.current?.focus();
        } else {
          openMobileSearch();
        }
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
            className="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-gray-200 text-gray-500 transition-colors motion-reduce:transition-none hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-950 dark:border-gray-800 dark:text-gray-300 dark:hover:bg-gray-800"
            onClick={handleToggle}
            aria-label="Toggle Sidebar"
            aria-expanded={isMobileOpen || isExpanded}
            aria-controls="canonical-owner-sidebar"
          >
            <span aria-hidden="true" className="text-xl leading-none">{isMobileOpen ? "×" : "☰"}</span>
          </button>
          <Link
            href="/shop-owner/home"
            className="hidden truncate text-lg font-bold tracking-tight text-gray-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-950 dark:inline-flex dark:text-white"
            aria-label="SoleSpace"
          >
            SoleSpace
          </Link>
          <div className="hidden lg:block">
            <ErpCommandSearch
              id="canonical-owner-search"
              inputRef={inputRef}
              inputClassName="h-10 w-72 rounded-lg border border-gray-200 bg-transparent py-2.5 pl-11 pr-14 text-sm text-gray-800 outline-none transition-colors motion-reduce:transition-none focus:border-[#111111] focus:ring-2 focus:ring-[#111111]/10 dark:border-gray-800 dark:text-white dark:focus:border-gray-300 dark:focus:ring-gray-300/20"
            />
          </div>
        </div>

        <button
          type="button"
          className="rounded-lg p-2 text-gray-500 transition-colors motion-reduce:transition-none hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-950 dark:text-gray-300 dark:hover:bg-gray-800 lg:hidden"
          aria-label="Toggle Search"
          aria-expanded={isMobileSearchOpen}
          onClick={() => {
            if (isMobileSearchOpen) {
              setMobileSearchOpen(false);
            } else {
              openMobileSearch();
            }
          }}
        >
          <svg aria-hidden="true" width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="m17.5 17.5-3.75-3.75m1.667-4.583a6.25 6.25 0 1 1-12.5 0 6.25 6.25 0 0 1 12.5 0Z" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
          </svg>
        </button>

        <button
          type="button"
          className="rounded-lg p-2 text-gray-500 transition-colors motion-reduce:transition-none hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-950 dark:text-gray-300 dark:hover:bg-gray-800 lg:hidden"
          aria-label="Toggle Application Menu"
          aria-expanded={isApplicationMenuOpen}
          onClick={() => setApplicationMenuOpen((current) => !current)}
        >
          ⋯
        </button>

        <div className={`${isApplicationMenuOpen ? "flex" : "hidden"} items-center gap-2 lg:flex`}>
          <NotificationBell
            basePath="/api/shop-owner/notifications"
            iconSize={24}
            className="rounded-full border border-gray-200 bg-white text-gray-900 hover:bg-gray-100 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:bg-gray-800"
          />
          <ThemeToggleButton />
          <ShopOwnerDropdown actor={erpActor} urls={canonicalOwnerUrls} businessStyle />
        </div>
      </div>

      {isMobileSearchOpen && (
        <div className="absolute left-0 right-0 top-full border-b border-gray-200 bg-white px-3 pb-3 pt-2 shadow-sm dark:border-gray-800 dark:bg-gray-900 lg:hidden">
          <ErpCommandSearch
            id="canonical-owner-search-mobile"
            inputRef={mobileInputRef}
            inputClassName="h-10 w-full rounded-lg border border-gray-200 bg-transparent py-2.5 pl-11 pr-14 text-sm text-gray-800 outline-none transition-colors motion-reduce:transition-none focus:border-[#111111] focus:ring-2 focus:ring-[#111111]/10 dark:border-gray-800 dark:text-white dark:focus:border-gray-300 dark:focus:ring-gray-300/20"
          />
        </div>
      )}
    </header>
  );
};

export default CanonicalOwnerHeader;
