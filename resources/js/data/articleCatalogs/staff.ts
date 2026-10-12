import type { ArticleCatalog } from "../articleGuides";
import {
  STAFF_ARTICLES,
  STAFF_ARTICLE_CATEGORIES,
} from "../staffArticles";

const staffArticleCatalog: ArticleCatalog = {
  audience: "staff",
  label: { en: "Staff", tl: "Staff" },
  title: { en: "Staff Articles", tl: "Mga artikulo para sa Staff" },
  intro: {
    en: "Simple guides for the retail Staff workspace.",
    tl: "Mga simpleng guide para sa retail Staff workspace.",
  },
  categories: STAFF_ARTICLE_CATEGORIES,
  articles: STAFF_ARTICLES,
};

export default staffArticleCatalog;
