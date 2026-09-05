import { describe, expect, it } from "vitest";

import {
  ARTICLE_AUDIENCES,
  ARTICLE_AUDIENCE_CONFIG,
  loadArticleCatalog,
} from "../articleAudience";
import type { ArticleStep } from "../articleGuides";
import { isArticleAccessible } from "../../utils/articleGuides";

describe("shared article guide contract", () => {
  it("lists only the requested audiences with distinct routes", () => {
    expect(ARTICLE_AUDIENCES).toEqual([
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
    ]);

    const basePaths = ARTICLE_AUDIENCES.map((audience) => ARTICLE_AUDIENCE_CONFIG[audience].basePath);

    expect(new Set(basePaths).size).toBe(ARTICLE_AUDIENCES.length);
    expect(ARTICLE_AUDIENCE_CONFIG.staff.basePath).toBe("/erp/articles");
    expect(basePaths.some((path) => path.toLowerCase().includes("rider"))).toBe(false);
  });

  it("keeps steps text-only", () => {
    const step: ArticleStep = {
      id: "open-page",
      title: "Open the page",
      body: "Use the menu to open the page.",
    };

    expect("screenshotId" in step).toBe(false);
    expect("screenshots" in step).toBe(false);
  });

  it("loads the catalog for the requested audience only", async () => {
    const catalog = await loadArticleCatalog("staff");

    expect(catalog.audience).toBe("staff");
  });

  it("keeps every audience catalog complete and internally linked", async () => {
    const catalogs = await Promise.all(ARTICLE_AUDIENCES.map((audience) => loadArticleCatalog(audience)));

    catalogs.forEach((catalog, index) => {
      expect(catalog.audience).toBe(ARTICLE_AUDIENCES[index]);
      expect(catalog.articles.length).toBeGreaterThan(0);

      const slugs = new Set(catalog.articles.map((article) => article.slug));

      catalog.articles.forEach((article) => {
        expect(article.translations.en.steps.length).toBeGreaterThanOrEqual(3);
        expect(article.translations.tl.steps.length).toBe(article.translations.en.steps.length);
        expect(article.translations.en.related.every((item) => slugs.has(item.slug)), `${catalog.audience}/${article.slug} English related link`).toBe(true);
        expect(article.translations.tl.related.every((item) => slugs.has(item.slug)), `${catalog.audience}/${article.slug} Tagalog related link`).toBe(true);
      });
    });
  });

  it("does not expose role-only guides without the matching role", async () => {
    const catalog = await loadArticleCatalog("manager");
    const managerArticle = catalog.articles[0];

    expect(isArticleAccessible(managerArticle, { permissions: [], roles: [] })).toBe(false);
    expect(isArticleAccessible(managerArticle, { permissions: [], roles: ["Manager"] })).toBe(true);
  });
});
