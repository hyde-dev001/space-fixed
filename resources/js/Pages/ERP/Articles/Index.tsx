import { Head, usePage } from "@inertiajs/react";
import { useEffect, useState } from "react";

import AppLayoutERP from "../../../layout/AppLayout_ERP";
import ArticleDetail from "../../../components/articles/ArticleDetail";
import ArticleHub from "../../../components/articles/ArticleHub";
import type { ArticleLanguage } from "../../../data/staffArticles";
import type { StaffArticleViewer } from "../../../data/staffArticleAccess";
import {
  getAccessibleStaffArticles,
  getStaffArticleBySlug,
} from "../../../utils/staffArticles";

const LANGUAGE_STORAGE_KEY = "solespace:staff-articles:language";

type AuthUserProps = {
  role?: unknown;
  roles?: unknown;
  shop_owner?: { business_type?: unknown } | null;
};

type ArticlesPageProps = {
  articleSlug?: unknown;
  auth?: {
    permissions?: unknown;
    user?: AuthUserProps | null;
    shop_owner?: { business_type?: unknown } | null;
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

const readViewer = (props: ArticlesPageProps): StaffArticleViewer => {
  const auth = props.auth;
  const user = auth?.user;
  const businessType = user?.shop_owner?.business_type ?? auth?.shop_owner?.business_type;

  return {
    permissions: readStringArray(auth?.permissions),
    roles: readStringArray(user?.roles),
    legacyRole: typeof user?.role === "string" ? user.role : null,
    businessType: typeof businessType === "string" ? businessType : null,
  };
};

export default function StaffArticlesIndex() {
  const page = usePage<ArticlesPageProps>();
  const props = page.props;
  const [language, setLanguage] = useArticleLanguage();
  const articleSlug = typeof props.articleSlug === "string" ? props.articleSlug : null;
  const accessibleArticles = getAccessibleStaffArticles(readViewer(props));
  const article = articleSlug === null ? undefined : getStaffArticleBySlug(articleSlug);

  return (
    <AppLayoutERP>
      <Head title="Staff Articles - SoleSpace ERP" />
      {articleSlug === null ? (
        <ArticleHub
          articles={accessibleArticles}
          language={language}
          onLanguageChange={setLanguage}
        />
      ) : (
        <ArticleDetail
          article={article}
          accessibleArticles={accessibleArticles}
          language={language}
          onLanguageChange={setLanguage}
        />
      )}
    </AppLayoutERP>
  );
}
