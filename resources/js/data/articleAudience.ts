import type { ArticleCatalog, ArticleLanguage, LocalizedText } from "./articleGuides";

export const ARTICLE_AUDIENCES = [
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
] as const;

export type ArticleAudience = (typeof ARTICLE_AUDIENCES)[number];

export type ArticleAudienceConfig = {
  basePath: string;
  indexRoute: string;
  pageTitle: LocalizedText;
};

export const ARTICLE_AUDIENCE_CONFIG: Record<ArticleAudience, ArticleAudienceConfig> = {
  staff: {
    basePath: "/erp/articles",
    indexRoute: "erp.articles.index",
    pageTitle: { en: "Staff Articles - SoleSpace ERP", tl: "Staff Articles - SoleSpace ERP" },
  },
  manager: {
    basePath: "/erp/manager/articles",
    indexRoute: "erp.manager.articles.index",
    pageTitle: { en: "Manager Articles - SoleSpace ERP", tl: "Manager Articles - SoleSpace ERP" },
  },
  finance: {
    basePath: "/finance/articles",
    indexRoute: "finance.articles.index",
    pageTitle: { en: "Finance Articles - SoleSpace ERP", tl: "Finance Articles - SoleSpace ERP" },
  },
  hr: {
    basePath: "/erp/hr/articles",
    indexRoute: "erp.hr.articles.index",
    pageTitle: { en: "HR Articles - SoleSpace ERP", tl: "HR Articles - SoleSpace ERP" },
  },
  crm: {
    basePath: "/crm/articles",
    indexRoute: "crm.articles.index",
    pageTitle: { en: "CRM Articles - SoleSpace ERP", tl: "CRM Articles - SoleSpace ERP" },
  },
  cashier: {
    basePath: "/erp/cashier/articles",
    indexRoute: "erp.cashier.articles.index",
    pageTitle: { en: "Cashier Articles - SoleSpace ERP", tl: "Cashier Articles - SoleSpace ERP" },
  },
  repairer: {
    basePath: "/erp/repairer/articles",
    indexRoute: "erp.repairer.articles.index",
    pageTitle: { en: "Repairer Articles - SoleSpace ERP", tl: "Repairer Articles - SoleSpace ERP" },
  },
  inventory: {
    basePath: "/erp/inventory/articles",
    indexRoute: "erp.inventory.articles.index",
    pageTitle: { en: "Inventory Articles - SoleSpace ERP", tl: "Inventory Articles - SoleSpace ERP" },
  },
  procurement: {
    basePath: "/erp/procurement/articles",
    indexRoute: "erp.procurement.articles.index",
    pageTitle: { en: "Procurement Articles - SoleSpace ERP", tl: "Procurement Articles - SoleSpace ERP" },
  },
  "logistics-dispatcher": {
    basePath: "/erp/logistics/articles",
    indexRoute: "erp.logistics.articles.index",
    pageTitle: { en: "Logistics Dispatcher Articles - SoleSpace ERP", tl: "Logistics Dispatcher Articles - SoleSpace ERP" },
  },
  "shop-owner": {
    basePath: "/shop-owner/erp/articles",
    indexRoute: "shop-owner.erp.articles.index",
    pageTitle: { en: "Shop Owner Articles - SoleSpace ERP", tl: "Shop Owner Articles - SoleSpace ERP" },
  },
};

export const isArticleAudience = (value: unknown): value is ArticleAudience => (
  typeof value === "string" && ARTICLE_AUDIENCES.includes(value as ArticleAudience)
);

export const readArticleAudience = (value: unknown): ArticleAudience | null => (
  isArticleAudience(value) ? value : null
);

export { loadArticleCatalog } from "./articleCatalogs";

export type { ArticleCatalog, ArticleLanguage };
