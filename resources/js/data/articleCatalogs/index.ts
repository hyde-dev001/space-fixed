import type { ArticleAudience } from "../articleAudience";
import type { ArticleCatalog } from "../articleGuides";

type ArticleCatalogModule = { default: ArticleCatalog };

const loaders: Record<ArticleAudience, () => Promise<ArticleCatalogModule>> = {
  staff: () => import("./staff"),
  manager: () => import("./manager"),
  finance: () => import("./finance"),
  hr: () => import("./hr"),
  crm: () => import("./crm"),
  cashier: () => import("./cashier"),
  repairer: () => import("./repairer"),
  inventory: () => import("./inventory"),
  procurement: () => import("./procurement"),
  "logistics-dispatcher": () => import("./logisticsDispatcher"),
  "shop-owner": () => import("./shopOwner"),
};

export const loadArticleCatalog = async (audience: ArticleAudience): Promise<ArticleCatalog> => {
  const module = await loaders[audience]();

  if (module.default.audience !== audience) {
    throw new Error(`Article catalog audience mismatch for ${audience}.`);
  }

  return module.default;
};

export const articleCatalogLoaders = loaders;
