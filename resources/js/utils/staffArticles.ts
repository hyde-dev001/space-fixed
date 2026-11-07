import {
  isRegularStaffViewer,
} from "../data/staffArticleAccess";
import {
  STAFF_ARTICLE_CATALOG,
  STAFF_ARTICLES,
} from "../data/staffArticles";
import type {
  ArticleCategorySummary,
  ArticleGuide,
  ArticleLanguage,
  ArticleViewer,
} from "../data/articleGuides";
import {
  getArticleCategories,
  getArticleBySlug,
  isArticleAccessible,
  resolveRelatedArticles,
  searchArticles,
} from "./articleGuides";
import type {
  ArticleLanguage as StaffArticleLanguage,
  StaffArticle,
  StaffArticleCategory,
} from "../data/staffArticles";
import type { StaffArticleViewer } from "../data/staffArticleAccess";

export type StaffArticleCategorySummary = StaffArticleCategory & {
  count: number;
};

const staffCatalog = (articles: readonly StaffArticle[]) => ({
  ...STAFF_ARTICLE_CATALOG,
  articles,
});

const toArticleViewer = (viewer: StaffArticleViewer): ArticleViewer => ({
  permissions: viewer.permissions,
  roles: viewer.roles,
  legacyRole: viewer.legacyRole,
  businessType: viewer.businessType,
});

export const isStaffArticleAccessible = (
  article: StaffArticle,
  viewer: StaffArticleViewer,
): boolean => (
  isRegularStaffViewer(viewer)
  && isArticleAccessible(article, toArticleViewer(viewer))
);
export const getAccessibleStaffArticles = (
  viewer: StaffArticleViewer,
  catalog: readonly StaffArticle[] = STAFF_ARTICLES,
): StaffArticle[] => catalog.filter((article) => isStaffArticleAccessible(article, viewer));

export const getStaffArticleBySlug = (
  slug: string | null | undefined,
  catalog: readonly StaffArticle[] = STAFF_ARTICLES,
): StaffArticle | undefined => (
  getArticleBySlug(staffCatalog(catalog), slug) as StaffArticle | undefined
);

export const searchStaffArticles = (
  articles: readonly StaffArticle[],
  query: string,
  language: StaffArticleLanguage,
): StaffArticle[] => (
  searchArticles(articles as readonly ArticleGuide[], query, language) as StaffArticle[]
);

export const getStaffArticleCategories = (
  articles: readonly StaffArticle[],
): StaffArticleCategorySummary[] => (
  getArticleCategories(STAFF_ARTICLE_CATALOG, articles as readonly ArticleGuide[]) as ArticleCategorySummary[]
).map((category) => category as StaffArticleCategorySummary);

export const resolveRelatedStaffArticles = (
  article: StaffArticle,
  accessibleArticles: readonly StaffArticle[],
  language: ArticleLanguage,
): Array<{ article: StaffArticle; label: string }> => (
  resolveRelatedArticles(
    article as ArticleGuide,
    accessibleArticles as readonly ArticleGuide[],
    language,
  ) as Array<{ article: StaffArticle; label: string }>
);
