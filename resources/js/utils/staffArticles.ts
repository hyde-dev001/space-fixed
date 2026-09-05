import {
  isRegularStaffViewer,
  normalizeStaffArticleBusinessType,
} from "../data/staffArticleAccess";
import {
  STAFF_ARTICLE_CATEGORIES,
  STAFF_ARTICLES,
} from "../data/staffArticles";
import type {
  ArticleLanguage,
  StaffArticle,
  StaffArticleCategory,
} from "../data/staffArticles";
import type { StaffArticleViewer } from "../data/staffArticleAccess";

export type StaffArticleCategorySummary = StaffArticleCategory & {
  count: number;
};

const normalizeSearchText = (value: unknown): string =>
  String(value ?? "")
    .trim()
    .toLocaleLowerCase()
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "");

export const isStaffArticleAccessible = (
  article: StaffArticle,
  viewer: StaffArticleViewer,
): boolean => {
  if (!isRegularStaffViewer(viewer)) return false;

  const businessType = normalizeStaffArticleBusinessType(viewer.businessType);
  if (businessType !== "retail" && businessType !== "both") return false;

  const hasArticlePermission = article.access.anyOfPermissions.some((permission) =>
    viewer.permissions.includes(permission),
  );

  return (
    hasArticlePermission &&
    article.access.allowedBusinessTypes.includes(businessType)
  );
};

export const getAccessibleStaffArticles = (
  viewer: StaffArticleViewer,
  catalog: readonly StaffArticle[] = STAFF_ARTICLES,
): StaffArticle[] => catalog.filter((article) => isStaffArticleAccessible(article, viewer));

export const getStaffArticleBySlug = (
  slug: string | null | undefined,
  catalog: readonly StaffArticle[] = STAFF_ARTICLES,
): StaffArticle | undefined => {
  const normalizedSlug = String(slug ?? "").trim().toLocaleLowerCase();

  return catalog.find((article) => article.slug === normalizedSlug);
};

const getSearchFields = (
  article: StaffArticle,
  language: ArticleLanguage,
): string[] => {
  const copy = article.translations[language];

  return [
    copy.title,
    copy.question,
    copy.summary,
    copy.audience,
    ...copy.keywords,
    ...copy.prerequisites.flatMap(({ title, body }) => [title, body]),
    ...copy.workflow.flatMap(({ title, body, status, owner }) => [title, body, status, owner]),
    ...copy.steps.flatMap(({ title, body }) => [title, body]),
    ...copy.outcomes.flatMap(({ title, body, owner, customerView }) => [title, body, owner, customerView]),
    ...copy.errors.flatMap(({ title, body, recovery }) => [title, body, recovery]),
    ...copy.related.map(({ label }) => label),
  ];
};

export const searchStaffArticles = (
  articles: readonly StaffArticle[],
  query: string,
  language: ArticleLanguage,
): StaffArticle[] => {
  const normalizedQuery = normalizeSearchText(query);

  if (!normalizedQuery) return [...articles];

  const terms = normalizedQuery.split(/\s+/).filter(Boolean);

  return articles.filter((article) => {
    const searchText = normalizeSearchText(getSearchFields(article, language).join(" "));

    return terms.every((term) => searchText.includes(term));
  });
};

export const getStaffArticleCategories = (
  articles: readonly StaffArticle[],
): StaffArticleCategorySummary[] =>
  STAFF_ARTICLE_CATEGORIES.flatMap((category) => {
    const count = articles.filter((article) => article.category === category.key).length;

    return count > 0 ? [{ ...category, count }] : [];
  });

export const resolveRelatedStaffArticles = (
  article: StaffArticle,
  accessibleArticles: readonly StaffArticle[],
  language: ArticleLanguage,
): Array<{ article: StaffArticle; label: string }> => {
  const accessibleBySlug = new Map(accessibleArticles.map((item) => [item.slug, item]));

  return article.translations[language].related.flatMap((related) => {
    const relatedArticle = accessibleBySlug.get(related.slug);

    return relatedArticle ? [{ article: relatedArticle, label: related.label }] : [];
  });
};
