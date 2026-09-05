import { Head, usePage } from "@inertiajs/react";
import { useEffect, useState } from "react";

import AppLayoutERP from "../../../layout/AppLayout_ERP";
import ArticleDetail from "../../../components/articles/ArticleDetail";
import ArticleHub from "../../../components/articles/ArticleHub";
import {
  ARTICLE_AUDIENCE_CONFIG,
  readArticleAudience,
} from "../../../data/articleAudience";
import type {
  ArticleCatalog,
  ArticleLanguage,
  ArticleViewer,
} from "../../../data/articleGuides";
import { loadArticleCatalog } from "../../../data/articleCatalogs";
import {
  getAccessibleArticles,
  getArticleBySlug,
} from "../../../utils/articleGuides";

const LANGUAGE_STORAGE_KEY = "solespace:staff-articles:language";

type ShopOwnerProps = {
  business_type?: unknown;
  registration_type?: unknown;
  status?: unknown;
} | null;

type AuthUserProps = {
  role?: unknown;
  roles?: unknown;
  shop_owner?: ShopOwnerProps;
};

type ArticlesPageProps = {
  articleSlug?: unknown;
  articleAudience?: unknown;
  shop_owner?: ShopOwnerProps;
  auth?: {
    permissions?: unknown;
    user?: AuthUserProps;
    shop_owner?: ShopOwnerProps;
    erpActor?: { type?: unknown; ownerMode?: unknown } | null;
  };
};

const readStringArray = (value: unknown): string[] => (
  Array.isArray(value)
    ? value.filter((item): item is string => typeof item === "string")
    : []
);

const readLanguagePreference = (): ArticleLanguage => {
  if (typeof window === "undefined") return "en";

  try {
    return window.localStorage.getItem(LANGUAGE_STORAGE_KEY) === "tl" ? "tl" : "en";
  } catch {
    return "en";
  }
};

const useArticleLanguage = (): [ArticleLanguage, (language: ArticleLanguage) => void] => {
  const [language, setLanguage] = useState<ArticleLanguage>(readLanguagePreference);

  useEffect(() => {
    try {
      window.localStorage.setItem(LANGUAGE_STORAGE_KEY, language);
    } catch {
      // Private browsing or disabled storage should not break the article reader.
    }
  }, [language]);

  return [language, setLanguage];
};

const readViewer = (props: ArticlesPageProps, audience: string): ArticleViewer => {
  const auth = props.auth;
  const user = auth?.user;
  const shopOwner = auth?.shop_owner ?? user?.shop_owner ?? props.shop_owner;

  return {
    permissions: readStringArray(auth?.permissions),
    roles: readStringArray(user?.roles),
    legacyRole: typeof user?.role === "string" ? user.role : null,
    businessType: typeof shopOwner?.business_type === "string" ? shopOwner.business_type : null,
    registrationType: typeof shopOwner?.registration_type === "string" ? shopOwner.registration_type : null,
    ownerMode: audience === "shop-owner"
      && auth?.erpActor?.type === "shop_owner"
      && auth?.erpActor?.ownerMode === true,
  };
};

const LoadingState = () => (
  <section className="rounded-2xl border border-gray-200 bg-white p-8 dark:border-gray-800 dark:bg-gray-950" role="status">
    <h1 className="text-2xl font-bold tracking-tight text-gray-950 dark:text-white">Loading articles</h1>
    <p className="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">Please wait while the guides load.</p>
  </section>
);

const UnavailableState = ({ message }: { message: string }) => (
  <section className="rounded-2xl border border-red-200 bg-red-50/60 p-8 dark:border-red-900 dark:bg-red-950/20" role="alert">
    <h1 className="text-2xl font-bold tracking-tight text-gray-950 dark:text-white">Articles unavailable</h1>
    <p className="mt-2 text-sm leading-6 text-gray-700 dark:text-gray-200">{message}</p>
  </section>
);

export default function StaffArticlesIndex() {
  const page = usePage<ArticlesPageProps>();
  const props = page.props;
  const [language, setLanguage] = useArticleLanguage();
  const [catalog, setCatalog] = useState<ArticleCatalog | null>(null);
  const [loadError, setLoadError] = useState(false);
  const audience = readArticleAudience(props.articleAudience);
  const articleSlug = typeof props.articleSlug === "string" ? props.articleSlug : null;
  const config = audience === null ? null : ARTICLE_AUDIENCE_CONFIG[audience];

  useEffect(() => {
    if (audience === null) {
      setCatalog(null);
      setLoadError(false);
      return;
    }

    let active = true;
    setCatalog(null);
    setLoadError(false);

    loadArticleCatalog(audience)
      .then((nextCatalog) => {
        if (active) setCatalog(nextCatalog);
      })
      .catch(() => {
        if (active) setLoadError(true);
      });

    return () => {
      active = false;
    };
  }, [audience]);

  const viewer = readViewer(props, audience ?? "");
  const accessibleArticles = catalog === null
    ? []
    : audience === "shop-owner"
      ? getAccessibleArticles(catalog, viewer)
      : catalog.articles;
  const article = catalog === null || articleSlug === null
    ? undefined
    : getArticleBySlug(catalog, articleSlug);

  return (
    <AppLayoutERP>
      <Head title={config?.pageTitle[language] ?? "Articles - SoleSpace ERP"} />
      {audience === null ? (
        <UnavailableState message="This article page does not have a valid account catalog." />
      ) : loadError || catalog === null ? (
        loadError ? (
          <UnavailableState message="The guide list could not be loaded. Refresh the page and try again." />
        ) : (
          <LoadingState />
        )
      ) : articleSlug === null ? (
        <ArticleHub
          catalog={catalog}
          articles={accessibleArticles}
          basePath={config?.basePath ?? "/erp/articles"}
          language={language}
          onLanguageChange={setLanguage}
        />
      ) : (
        <ArticleDetail
          catalog={catalog}
          article={article}
          accessibleArticles={accessibleArticles}
          basePath={config?.basePath ?? "/erp/articles"}
          language={language}
          onLanguageChange={setLanguage}
        />
      )}
    </AppLayoutERP>
  );
}
