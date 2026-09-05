import type {
  ArticleCatalog,
  ArticleCategory,
  ArticleGuide,
  ArticleLanguage,
  ArticleViewer,
} from "../data/articleGuides";
import type { ArticleAudience } from "../data/articleAudience";

export type ArticleCategorySummary = ArticleCategory & { count: number };

const normalizeSearchText = (value: unknown): string => (
  String(value ?? "")
    .trim()
    .toLocaleLowerCase()
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
);

const normalizeRole = (value: unknown): string => (
  String(value ?? "")
    .trim()
    .toLocaleUpperCase()
    .replace(/_/g, " ")
);

const normalizeBusinessType = (value: unknown): "retail" | "repair" | "both" | "" => {
  const normalized = String(value ?? "").trim().toLocaleLowerCase();

  if (normalized.includes("both")) return "both";
  if (normalized === "retail" || normalized === "repair") return normalized;

  return "";
};

const normalizeRegistrationType = (value: unknown): "company" | "individual" | "" => {
  const normalized = String(value ?? "").trim().toLocaleLowerCase();

  return normalized === "company" || normalized === "individual" ? normalized : "";
};

const viewerRoles = (viewer: ArticleViewer): string[] => [
  ...(viewer.roles ?? []),
  viewer.legacyRole ?? "",
].map(normalizeRole).filter(Boolean);

const hasAudienceIdentity = (
  article: ArticleGuide,
  viewer: ArticleViewer,
): boolean => {
  if (article.access.ownerOnly && viewer.ownerMode !== true) return false;

  const roles = viewerRoles(viewer);
  const roleAllowed = (article.access.allowedRoles ?? []).some((role) => (
    roles.includes(normalizeRole(role))
  ));
  const permissionAllowed = article.access.anyOfPermissions.length > 0
    && article.access.anyOfPermissions.some((permission) => viewer.permissions.includes(permission));
  const allowedRoles = article.access.allowedRoles ?? [];
  const identityAllowed = allowedRoles.length === 0
    ? permissionAllowed || article.access.anyOfPermissions.length === 0
    : roleAllowed || permissionAllowed;

  if (!identityAllowed) {
    return false;
  }

  const businessType = normalizeBusinessType(viewer.businessType);
  if (article.access.allowedBusinessTypes?.length
    && !article.access.allowedBusinessTypes.includes(businessType)) {
    return false;
  }

  const registrationType = normalizeRegistrationType(viewer.registrationType);
  if (article.access.allowedRegistrationTypes?.length
    && !article.access.allowedRegistrationTypes.includes(registrationType)) {
    return false;
  }

  return true;
};

export const isArticleAccessible = (
  article: ArticleGuide,
  viewer: ArticleViewer,
): boolean => hasAudienceIdentity(article, viewer);

export const getAccessibleArticles = (
  catalog: ArticleCatalog,
  viewer: ArticleViewer,
): ArticleGuide[] => catalog.articles.filter((article) => isArticleAccessible(article, viewer));

export const getArticleBySlug = (
  catalog: ArticleCatalog,
  slug: string | null | undefined,
): ArticleGuide | undefined => {
  const normalizedSlug = String(slug ?? "").trim().toLocaleLowerCase();

  return catalog.articles.find((article) => article.slug === normalizedSlug);
};

const getSearchFields = (article: ArticleGuide, language: ArticleLanguage): string[] => {
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

export const searchArticles = (
  articles: readonly ArticleGuide[],
  query: string,
  language: ArticleLanguage,
): ArticleGuide[] => {
  const normalizedQuery = normalizeSearchText(query);

  if (!normalizedQuery) return [...articles];

  const terms = normalizedQuery.split(/\s+/).filter(Boolean);

  return articles.filter((article) => {
    const searchText = normalizeSearchText(getSearchFields(article, language).join(" "));

    return terms.every((term) => searchText.includes(term));
  });
};

export const getArticleCategories = (
  catalog: ArticleCatalog,
  articles: readonly ArticleGuide[],
): ArticleCategorySummary[] => catalog.categories.flatMap((category) => {
  const count = articles.filter((article) => article.category === category.key).length;

  return count > 0 ? [{ ...category, count }] : [];
});

export const resolveRelatedArticles = (
  article: ArticleGuide,
  accessibleArticles: readonly ArticleGuide[],
  language: ArticleLanguage,
): Array<{ article: ArticleGuide; label: string }> => {
  const accessibleBySlug = new Map(accessibleArticles.map((item) => [item.slug, item]));

  return article.translations[language].related.flatMap((related) => {
    const relatedArticle = accessibleBySlug.get(related.slug);

    return relatedArticle ? [{ article: relatedArticle, label: related.label }] : [];
  });
};

export const articleAudienceForCatalog = (catalog: ArticleCatalog): ArticleAudience | null => {
  const value = catalog.audience;

  return [
    "staff",
    "manager",
    "finance",
    "hr",
    "crm",
    "cashier",
    "repairer",
    "inventory",
    "procurement",
    "logistics-dispatcher",
    "shop-owner",
  ].includes(value as ArticleAudience)
    ? value as ArticleAudience
    : null;
};
