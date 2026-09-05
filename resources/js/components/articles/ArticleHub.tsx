import { ArrowRight, BookOpen, Clock3, RotateCcw, Search } from "lucide-react";
import { useEffect, useMemo, useState } from "react";
import { Link } from "@inertiajs/react";

import type {
  ArticleCatalog,
  ArticleGuide,
  ArticleLanguage,
} from "../../data/articleGuides";
import {
  getArticleCategories,
  searchArticles,
} from "../../utils/articleGuides";

type ArticleHubProps = {
  catalog: ArticleCatalog;
  articles: readonly ArticleGuide[];
  basePath: string;
  language: ArticleLanguage;
  onLanguageChange: (language: ArticleLanguage) => void;
};

type HubCategory = string | "all";

const ALL_CATEGORY: Record<ArticleLanguage, string> = {
  en: "All articles",
  tl: "Lahat ng artikulo",
};

const readHubState = (catalog: ArticleCatalog): { query: string; category: HubCategory } => {
  if (typeof window === "undefined") return { query: "", category: "all" };

  const params = new URLSearchParams(window.location.search);
  const category = params.get("category") ?? "";

  return {
    query: params.get("q") ?? "",
    category: catalog.categories.some((item) => item.key === category) ? category : "all",
  };
};

const getCategoryLabel = (
  catalog: ArticleCatalog,
  category: HubCategory,
  language: ArticleLanguage,
): string => {
  if (category === "all") return ALL_CATEGORY[language];

  return catalog.categories.find((item) => item.key === category)?.label[language] ?? category;
};

const getHubCopy = (catalog: ArticleCatalog, language: ArticleLanguage) => {
  const label = catalog.label[language];

  return language === "en"
    ? {
        eyebrow: `${label} knowledge base`,
        title: catalog.title[language],
        intro: catalog.intro[language],
        searchLabel: `Search ${label} articles`,
        searchPlaceholder: "Search by task, status, or question",
        categories: "Browse by category",
        recommended: "Recommended reads",
        results: "Articles",
        articles: "articles",
        readMinutes: "min read",
        open: "Open article",
        noResults: `No ${label} articles match those filters.`,
        noResultsHint: "Try a wider word or clear the filters to see all available articles.",
        clear: "Clear search and filters",
        empty: "No articles are available for this account.",
        emptyHint: "This list follows the access and shop settings of the signed-in account.",
      }
    : {
        eyebrow: `Mga guide para sa ${label}`,
        title: catalog.title[language],
        intro: catalog.intro[language],
        searchLabel: `Maghanap ng ${label} articles`,
        searchPlaceholder: "Maghanap ayon sa task, status, o tanong",
        categories: "Mag-browse ayon sa category",
        recommended: "Mga inirerekomendang basahin",
        results: "Mga artikulo",
        articles: "artikulo",
        readMinutes: "min na basa",
        open: "Buksan ang artikulo",
        noResults: `Walang ${label} articles na tumugma sa filters.`,
        noResultsHint: "Subukan ang mas malawak na salita o i-clear ang filters para makita ang lahat.",
        clear: "I-clear ang search at filters",
        empty: "Walang available na artikulo para sa account na ito.",
        emptyHint: "Sinusunod ng listahang ito ang access at shop settings ng naka-sign-in na account.",
      };
};

export function ArticleLanguageToggle({
  language,
  onLanguageChange,
}: {
  language: ArticleLanguage;
  onLanguageChange: (language: ArticleLanguage) => void;
}) {
  return (
    <div
      className="inline-flex min-h-11 items-center rounded-full border border-gray-300 bg-white p-1 dark:border-gray-700 dark:bg-gray-950"
      role="group"
      aria-label={language === "tl" ? "Wika ng artikulo" : "Article language"}
    >
      {["en", "tl"].map((option) => (
        <button
          key={option}
          type="button"
          aria-pressed={language === option}
          className={`min-h-9 rounded-full px-3 text-xs font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-950 focus-visible:ring-offset-1 dark:focus-visible:ring-white ${
            language === option
              ? "bg-gray-950 text-white dark:bg-white dark:text-gray-950"
              : "text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/10"
          }`}
          onClick={() => onLanguageChange(option as ArticleLanguage)}
        >
          {option === "en" ? "English" : "Tagalog"}
        </button>
      ))}
    </div>
  );
}

function ArticleCard({
  article,
  basePath,
  catalog,
  language,
  openLabel,
  readMinutes,
}: {
  article: ArticleGuide;
  basePath: string;
  catalog: ArticleCatalog;
  language: ArticleLanguage;
  openLabel: string;
  readMinutes: string;
}) {
  const translation = article.translations[language];
  const category = getCategoryLabel(catalog, article.category, language);
  const href = `${basePath}/${article.slug}`;

  return (
    <article className="flex h-full flex-col rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-950">
      <div className="flex flex-wrap items-center gap-2 text-xs font-semibold text-gray-500 dark:text-gray-400">
        <span className="rounded-full border border-gray-200 bg-white px-2.5 py-1 dark:border-gray-800 dark:bg-gray-950">{category}</span>
        <span className="inline-flex items-center gap-1">
          <Clock3 aria-hidden="true" className="h-3.5 w-3.5" />
          {translation.readingMinutes} {readMinutes}
        </span>
      </div>
      <h3 className="mt-4 text-lg font-semibold tracking-tight text-gray-950 dark:text-white">
        <Link
          href={href}
          className="rounded-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-950 focus-visible:ring-offset-2 dark:focus-visible:ring-white"
          aria-label={`${openLabel}: ${translation.title}`}
        >
          {translation.title}
        </Link>
      </h3>
      <p className="mt-2 text-sm font-medium text-gray-700 dark:text-gray-200">{translation.question}</p>
      <p className="mt-3 flex-1 text-sm leading-6 text-gray-600 dark:text-gray-400">{translation.summary}</p>
      <Link
        href={href}
        className="mt-5 inline-flex min-h-11 items-center gap-2 self-start rounded-full border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 transition-colors hover:border-gray-950 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-950 focus-visible:ring-offset-2 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100 dark:hover:border-white dark:focus-visible:ring-white"
      >
        {openLabel}
        <ArrowRight aria-hidden="true" className="h-4 w-4" />
      </Link>
    </article>
  );
}

export default function ArticleHub({
  catalog,
  articles,
  basePath,
  language,
  onLanguageChange,
}: ArticleHubProps) {
  const languageCopy = getHubCopy(catalog, language);
  const [hubState, setHubState] = useState(() => readHubState(catalog));
  const { query, category } = hubState;
  const categories = getArticleCategories(catalog, articles);
  const categoryCounts = new Map(categories.map((item) => [item.key, item.count]));

  useEffect(() => {
    setHubState(readHubState(catalog));
  }, [catalog]);

  const filteredArticles = useMemo(() => {
    const searched = searchArticles(articles, query, language);

    return category === "all"
      ? searched
      : searched.filter((article) => article.category === category);
  }, [articles, category, language, query]);

  useEffect(() => {
    if (typeof window === "undefined") return;

    const params = new URLSearchParams();
    if (query.trim()) params.set("q", query.trim());
    if (category !== "all") params.set("category", category);
    const search = params.toString();

    window.history.replaceState(
      {},
      "",
      `${window.location.pathname}${search ? `?${search}` : ""}`,
    );
  }, [category, query]);

  const recommended = articles.filter((article) => article.recommended).slice(0, 3);
  const hasFilters = Boolean(query.trim()) || category !== "all";
  const clearFilters = () => setHubState({ query: "", category: "all" });
  const setQuery = (nextQuery: string) => setHubState((current) => ({ ...current, query: nextQuery }));
  const setCategory = (nextCategory: HubCategory) => setHubState((current) => ({ ...current, category: nextCategory }));
  const testId = catalog.audience === "staff" ? "staff-articles-hub" : `${catalog.audience}-articles-hub`;

  return (
    <main className="min-w-0 space-y-8" data-testid={testId}>
      <header className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-950 sm:p-8">
        <div className="flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between">
          <div className="max-w-3xl">
            <p className="text-xs font-bold uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">{languageCopy.eyebrow}</p>
            <h1 className="mt-3 text-3xl font-bold tracking-tight text-gray-950 dark:text-white sm:text-4xl">{languageCopy.title}</h1>
            <p className="mt-4 max-w-2xl text-base leading-7 text-gray-600 dark:text-gray-300">{languageCopy.intro}</p>
          </div>
          <ArticleLanguageToggle language={language} onLanguageChange={onLanguageChange} />
        </div>

        <div className="mt-7 max-w-3xl">
          <label htmlFor="article-search" className="sr-only">{languageCopy.searchLabel}</label>
          <div className="relative">
            <Search aria-hidden="true" className="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-500 dark:text-gray-400" />
            <input
              id="article-search"
              type="search"
              value={query}
              placeholder={languageCopy.searchPlaceholder}
              aria-label={languageCopy.searchLabel}
              className="min-h-12 w-full rounded-full border border-gray-300 bg-white pl-12 pr-4 text-sm text-gray-950 outline-none transition-colors placeholder:text-gray-500 focus:border-gray-950 focus:ring-2 focus:ring-gray-950/10 dark:border-gray-700 dark:bg-gray-950 dark:text-white dark:placeholder:text-gray-400 dark:focus:border-white dark:focus:ring-white/20"
              onChange={(event) => setQuery(event.target.value)}
            />
          </div>
        </div>
      </header>

      <section aria-labelledby="article-categories">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
          <div>
            <p className="text-xs font-bold uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">{languageCopy.categories}</p>
            <h2 id="article-categories" className="mt-2 text-xl font-semibold tracking-tight text-gray-950 dark:text-white">
              {getCategoryLabel(catalog, category, language)}
            </h2>
          </div>
          <p className="text-sm text-gray-500 dark:text-gray-400">{filteredArticles.length} {languageCopy.articles}</p>
        </div>
        <div className="mt-4 flex flex-wrap gap-2">
          <button
            type="button"
            aria-pressed={category === "all"}
            className={`min-h-11 rounded-full border px-4 py-2 text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-950 focus-visible:ring-offset-2 dark:focus-visible:ring-white ${category === "all" ? "border-gray-950 bg-gray-950 text-white dark:border-white dark:bg-white dark:text-gray-950" : "border-gray-300 bg-white text-gray-700 hover:border-gray-950 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-200 dark:hover:border-white"}`}
            onClick={() => setCategory("all")}
          >
            {ALL_CATEGORY[language]} <span className="ml-1 opacity-70">{articles.length}</span>
          </button>
          {categories.map((item) => (
            <button
              key={item.key}
              type="button"
              aria-pressed={category === item.key}
              className={`min-h-11 rounded-full border px-4 py-2 text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-950 focus-visible:ring-offset-2 dark:focus-visible:ring-white ${category === item.key ? "border-gray-950 bg-gray-950 text-white dark:border-white dark:bg-white dark:text-gray-950" : "border-gray-300 bg-white text-gray-700 hover:border-gray-950 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-200 dark:hover:border-white"}`}
              onClick={() => setCategory(category === item.key ? "all" : item.key)}
            >
              {item.label[language]} <span className="ml-1 opacity-70">{categoryCounts.get(item.key)}</span>
            </button>
          ))}
        </div>
      </section>

      {recommended.length > 0 && !hasFilters && (
        <section aria-labelledby="article-recommended">
          <div className="flex items-center gap-3">
            <BookOpen aria-hidden="true" className="h-5 w-5 text-gray-700 dark:text-gray-200" />
            <h2 id="article-recommended" className="text-xl font-semibold tracking-tight text-gray-950 dark:text-white">{languageCopy.recommended}</h2>
          </div>
          <div className="mt-4 grid gap-4 md:grid-cols-3">
            {recommended.map((article) => (
              <ArticleCard key={article.slug} article={article} basePath={basePath} catalog={catalog} language={language} openLabel={languageCopy.open} readMinutes={languageCopy.readMinutes} />
            ))}
          </div>
        </section>
      )}

      <section aria-labelledby="article-results">
        <div className="flex items-center justify-between gap-4">
          <h2 id="article-results" className="text-xl font-semibold tracking-tight text-gray-950 dark:text-white">{languageCopy.results}</h2>
          {hasFilters && filteredArticles.length > 0 && (
            <button
              type="button"
              className="inline-flex min-h-11 items-center gap-2 rounded-full px-3 text-sm font-semibold text-gray-700 underline decoration-gray-300 underline-offset-4 hover:text-gray-950 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-950 dark:text-gray-200 dark:hover:text-white dark:focus-visible:ring-white"
              onClick={clearFilters}
            >
              <RotateCcw aria-hidden="true" className="h-4 w-4" />
              {languageCopy.clear}
            </button>
          )}
        </div>

        {filteredArticles.length === 0 ? (
          <div className="mt-4 rounded-2xl border border-dashed border-gray-300 bg-white p-8 dark:border-gray-700 dark:bg-gray-950" role="status">
            <h3 className="text-lg font-semibold text-gray-950 dark:text-white">
              {articles.length === 0 ? languageCopy.empty : languageCopy.noResults}
            </h3>
            <p className="mt-2 max-w-2xl text-sm leading-6 text-gray-600 dark:text-gray-300">
              {articles.length === 0 ? languageCopy.emptyHint : languageCopy.noResultsHint}
            </p>
            {articles.length > 0 && (
              <button
                type="button"
                className="mt-5 inline-flex min-h-11 items-center gap-2 rounded-full border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 transition-colors hover:border-gray-950 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-950 focus-visible:ring-offset-2 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100 dark:hover:border-white dark:focus-visible:ring-white"
                onClick={clearFilters}
              >
                <RotateCcw aria-hidden="true" className="h-4 w-4" />
                {languageCopy.clear}
              </button>
            )}
          </div>
        ) : (
          <div className="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            {filteredArticles.map((article) => (
              <ArticleCard key={article.slug} article={article} basePath={basePath} catalog={catalog} language={language} openLabel={languageCopy.open} readMinutes={languageCopy.readMinutes} />
            ))}
          </div>
        )}
      </section>
    </main>
  );
}
