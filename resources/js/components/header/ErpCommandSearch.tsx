import { BookOpen, LayoutDashboard, Search } from "lucide-react";
import { useEffect, useMemo, useRef, useState } from "react";
import { Link, usePage } from "@inertiajs/react";

import { loadArticleCatalog } from "../../data/articleCatalogs";
import type { ArticleCatalog } from "../../data/articleGuides";
import {
  getAccessibleErpSearchPages,
  getOwnerShellSearchPages,
  getSearchBasePath,
  readErpSearchViewer,
  resolveErpSearchScope,
  searchErpCommands,
  type ErpSearchResult,
} from "../../data/erpCommandSearch";

type ErpCommandSearchProps = {
  inputRef?: React.RefObject<HTMLInputElement | null>;
  id?: string;
  inputClassName?: string;
  className?: string;
};

const DEFAULT_INPUT_CLASS = "dark:bg-dark-900 h-11 w-full rounded-lg border border-gray-200 bg-transparent py-2.5 pl-12 pr-14 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-800 dark:bg-gray-900 dark:bg-white/[0.03] dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800 xl:w-[430px]";
const DEFAULT_SHORTCUT_CLASS = "absolute right-2.5 top-1/2 inline-flex -translate-y-1/2 items-center gap-0.5 rounded-lg border border-gray-200 bg-gray-50 px-[7px] py-[4.5px] text-xs -tracking-[0.2px] text-gray-500 dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-400";

const resultId = (result: ErpSearchResult): string => `erp-search-result-${result.id}`;

export default function ErpCommandSearch({
  inputRef,
  id = "erp-command-search",
  inputClassName = DEFAULT_INPUT_CLASS,
  className = "relative",
}: ErpCommandSearchProps) {
  const page = usePage();
  const pageProps = page.props as unknown;
  const scope = resolveErpSearchScope(String(page.url ?? ""), pageProps);
  const viewer = useMemo(
    () => scope === null
      ? {
          permissions: [],
          roles: [],
          legacyRole: null,
          businessType: null,
          registrationType: null,
          ownerMode: false,
        }
      : readErpSearchViewer(pageProps, scope),
    [pageProps, scope],
  );
  const viewerKey = JSON.stringify(viewer);
  const localInputRef = useRef<HTMLInputElement>(null);
  const rootRef = useRef<HTMLDivElement>(null);
  const suppressFocusOpenRef = useRef(false);
  const searchInputRef = inputRef ?? localInputRef;
  const [query, setQuery] = useState("");
  const [isOpen, setIsOpen] = useState(false);
  const [activeIndex, setActiveIndex] = useState(-1);
  const [catalog, setCatalog] = useState<ArticleCatalog | null>(null);
  const [catalogLoading, setCatalogLoading] = useState(false);

  useEffect(() => {
    let active = true;

    if (scope === null) {
      setCatalog(null);
      setCatalogLoading(false);
      return () => {
        active = false;
      };
    }

    setCatalog(null);
    setCatalogLoading(true);
    loadArticleCatalog(scope)
      .then((nextCatalog) => {
        if (active) setCatalog(nextCatalog);
      })
      .catch(() => {
        if (active) setCatalog(null);
      })
      .finally(() => {
        if (active) setCatalogLoading(false);
      });

    return () => {
      active = false;
    };
  }, [scope, viewerKey]);

  useEffect(() => {
    if (!isOpen) return;

    const handlePointerDown = (event: PointerEvent) => {
      if (!(event.target instanceof Node)) return;
      if (!rootRef.current?.contains(event.target)) setIsOpen(false);
    };

    document.addEventListener("pointerdown", handlePointerDown);
    return () => document.removeEventListener("pointerdown", handlePointerDown);
  }, [isOpen]);

  const pages = useMemo(() => {
    if (scope === null) return [];

    if (scope === "shop-owner") {
      const props = pageProps as Record<string, unknown>;
      return getOwnerShellSearchPages(props.ownerShell);
    }

    return getAccessibleErpSearchPages(scope, viewer);
  }, [pageProps, scope, viewer]);

  const results = useMemo(() => {
    if (scope === null || catalog === null) return [];

    return searchErpCommands({
      scope,
      pages,
      catalog,
      query,
      language: "en",
      basePath: getSearchBasePath(scope),
      viewer,
    }).slice(0, 8);
  }, [catalog, pages, query, scope, viewer]);

  useEffect(() => {
    setActiveIndex((current) => {
      if (results.length === 0) return -1;
      return Math.min(current, results.length - 1);
    });
  }, [results]);

  const showDropdown = isOpen && query.trim().length > 0;
  const listboxId = `${id}-results`;

  const handleKeyDown = (event: React.KeyboardEvent<HTMLInputElement>) => {
    if (event.key === "ArrowDown") {
      event.preventDefault();
      setIsOpen(true);
      setActiveIndex((current) => results.length === 0 ? -1 : (current + 1) % results.length);
      return;
    }

    if (event.key === "ArrowUp") {
      event.preventDefault();
      setIsOpen(true);
      setActiveIndex((current) => results.length === 0
        ? -1
        : current <= 0 ? results.length - 1 : current - 1);
      return;
    }

    if (event.key === "Escape") {
      event.preventDefault();
      setIsOpen(false);
      setActiveIndex(-1);
      suppressFocusOpenRef.current = true;
      searchInputRef.current?.focus();
      return;
    }

    if (event.key === "Enter" && activeIndex >= 0 && results[activeIndex]) {
      event.preventDefault();
      document.getElementById(resultId(results[activeIndex]))?.querySelector<HTMLAnchorElement>("a")?.click();
    }
  };

  const renderResult = (result: ErpSearchResult, index: number) => {
    const Icon = result.kind === "article" ? BookOpen : LayoutDashboard;

    return (
      <div
        key={result.id}
        id={resultId(result)}
        role="option"
        aria-selected={activeIndex === index}
        data-kind={result.kind}
        className={activeIndex === index ? "bg-gray-100 dark:bg-white/10" : ""}
      >
        <Link
          href={result.href}
          className="flex items-start gap-3 px-4 py-3 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-gray-950 dark:focus-visible:ring-white"
          onClick={() => {
            setIsOpen(false);
            setActiveIndex(-1);
          }}
        >
          <Icon aria-hidden="true" className="mt-0.5 h-4 w-4 shrink-0 text-gray-500 dark:text-gray-400" />
          <span className="min-w-0">
            <span className="block truncate text-sm font-semibold text-gray-900 dark:text-white">{result.label}</span>
            <span className="mt-0.5 block truncate text-xs text-gray-500 dark:text-gray-400">{result.scopeLabel} {result.kind === "article" ? "article" : "page"} · {result.description}</span>
          </span>
        </Link>
      </div>
    );
  };

  const groupedResults = results;

  return (
    <div ref={rootRef} className={className}>
      <form onSubmit={(event) => event.preventDefault()}>
        <label htmlFor={id} className="sr-only">Search</label>
        <span className="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2">
          <Search aria-hidden="true" className="h-5 w-5 text-gray-500 dark:text-gray-400" />
        </span>
        <input
          ref={searchInputRef}
          id={id}
          type="search"
          role="combobox"
          value={query}
          placeholder="Search or type command..."
          aria-label="Search or type command"
          aria-expanded={showDropdown}
          aria-controls={showDropdown ? listboxId : undefined}
          aria-autocomplete="list"
          aria-activedescendant={activeIndex >= 0 ? resultId(results[activeIndex]) : undefined}
          className={inputClassName}
          onFocus={() => {
            if (suppressFocusOpenRef.current) {
              suppressFocusOpenRef.current = false;
              return;
            }
            if (query.trim()) setIsOpen(true);
          }}
          onChange={(event) => {
            setQuery(event.target.value);
            setActiveIndex(-1);
            setIsOpen(Boolean(event.target.value.trim()));
          }}
          onKeyDown={handleKeyDown}
        />
        <button type="button" aria-label="Focus search" className={DEFAULT_SHORTCUT_CLASS} onClick={() => searchInputRef.current?.focus()}>
          <span>⌘</span>
          <span>K</span>
        </button>
      </form>

      {showDropdown && (
        <div className="absolute left-0 top-[calc(100%+0.5rem)] z-50 w-full overflow-hidden rounded-xl border border-gray-200 bg-white shadow-xl ring-1 ring-black/5 dark:border-gray-700 dark:bg-gray-900 dark:ring-white/10" data-testid="erp-command-search-dropdown">
          {catalogLoading ? (
            <div className="px-4 py-3 text-sm text-gray-500 dark:text-gray-400" role="status">Loading suggestions...</div>
          ) : groupedResults.length === 0 ? (
            <div className="px-4 py-3 text-sm text-gray-500 dark:text-gray-400" role="status">No matching pages or articles in this account.</div>
          ) : (
            <div id={listboxId} role="listbox" aria-label="Search results" className="max-h-96 overflow-y-auto py-2">
              {groupedResults.map((result, index) => {
                const previousResult = groupedResults[index - 1];
                const showHeading = !previousResult || previousResult.kind !== result.kind;

                return (
                  <span key={result.id} className="contents">
                    {showHeading && (
                      <p className="px-4 pb-1 pt-1 text-[10px] font-bold uppercase tracking-[0.16em] text-gray-400">
                        {result.kind === "article" ? "Articles" : "Pages"}
                      </p>
                    )}
                    {renderResult(result, index)}
                  </span>
                );
              })}
            </div>
          )}
        </div>
      )}
    </div>
  );
}
