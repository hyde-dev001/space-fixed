import { describe, expect, it } from "vitest";

import {
  STAFF_ARTICLES,
  STAFF_ARTICLE_CATEGORIES,
} from "../staffArticles";
import {
  getAccessibleStaffArticles,
  getStaffArticleBySlug,
  getStaffArticleCategories,
  searchStaffArticles,
} from "../../utils/staffArticles";
import { isRegularStaffViewer } from "../staffArticleAccess";
import type { StaffArticleViewer } from "../staffArticleAccess";

const completeRetailStaffViewer: StaffArticleViewer = {
  permissions: [
    "access-staff-dashboard",
    "access-staff-job-orders",
    "access-product-management",
    "access-product-upload-staff",
    "access-shoe-pricing",
    "access-staff-time-in",
    "access-staff-leave",
    "access-color-variant-manager",
    "access-staff-customers",
    "access-notification-center",
    "access-profile",
  ],
  roles: ["Staff"],
  legacyRole: "STAFF",
  businessType: "retail",
};

describe("Staff Articles catalog", () => {
  it("contains the 32 approved articles with stable ordered slugs", () => {
    expect(STAFF_ARTICLES).toHaveLength(32);
    expect(new Set(STAFF_ARTICLES.map((article) => article.slug)).size).toBe(32);
    expect(STAFF_ARTICLES.map((article) => article.order)).toEqual(
      Array.from({ length: 32 }, (_, index) => index + 1),
    );
    expect(new Set(STAFF_ARTICLES.map((article) => article.category))).toEqual(
      new Set(STAFF_ARTICLE_CATEGORIES.map((category) => category.key)),
    );
  });

  it("keeps bilingual text structure in parity without screenshots", () => {
    for (const article of STAFF_ARTICLES) {
      const english = article.translations.en;
      const tagalog = article.translations.tl;

      expect(english.title.trim()).not.toBe("");
      expect(tagalog.title.trim()).not.toBe("");
      expect(english.summary.trim()).not.toBe("");
      expect(tagalog.summary.trim()).not.toBe("");
      expect(article.access.anyOfPermissions.length).toBeGreaterThan(0);
      expect(article.access.allowedBusinessTypes).toEqual(
        expect.arrayContaining(["retail", "both"]),
      );
      expect(article.sourceCoverage.routes.length).toBeGreaterThan(0);
      expect(article.sourceCoverage.pages.length).toBeGreaterThan(0);
      expect(article.sourceCoverage.permissions.length).toBeGreaterThan(0);
      expect(article.sourceCoverage.tests.length).toBeGreaterThan(0);

      expect(english.prerequisites.map(({ id }) => id)).toEqual(
        tagalog.prerequisites.map(({ id }) => id),
      );
      expect(english.steps.map(({ id }) => id)).toEqual(
        tagalog.steps.map(({ id }) => id),
      );
      expect(english.workflow.map(({ id }) => id)).toEqual(
        tagalog.workflow.map(({ id }) => id),
      );
      expect(english.outcomes.map(({ id }) => id)).toEqual(
        tagalog.outcomes.map(({ id }) => id),
      );
      expect(english.errors.map(({ id }) => id)).toEqual(
        tagalog.errors.map(({ id }) => id),
      );
      expect(english.related.map(({ slug }) => slug)).toEqual(
        tagalog.related.map(({ slug }) => slug),
      );

      expect(english.steps.length).toBeGreaterThanOrEqual(3);
      expect(english.outcomes.length).toBeGreaterThan(0);
      expect(english.errors.length).toBeGreaterThan(0);
      expect(english.workflow.length).toBeGreaterThan(0);
      expect(english.steps.every((step) => !("screenshotId" in step))).toBe(true);
      expect("screenshots" in article).toBe(false);
    }
  });

  it("keeps related links resolvable inside the Staff catalog", () => {
    for (const article of STAFF_ARTICLES) {
      for (const related of article.translations.en.related) {
        expect(getStaffArticleBySlug(related.slug)).toBeDefined();
      }
    }
  });

  it("filters the catalog by regular Staff role, permission, and retail business context", () => {
    const accessible = getAccessibleStaffArticles(completeRetailStaffViewer);

    expect(accessible).toHaveLength(32);
    expect(isRegularStaffViewer(completeRetailStaffViewer)).toBe(true);
    expect(
      getAccessibleStaffArticles({
        ...completeRetailStaffViewer,
        businessType: "repair",
      }),
    ).toHaveLength(0);
    expect(
      getAccessibleStaffArticles({
        ...completeRetailStaffViewer,
        roles: ["Cashier"],
        legacyRole: "STAFF",
      }),
    ).toHaveLength(0);
    expect(
      getAccessibleStaffArticles({
        ...completeRetailStaffViewer,
        roles: ["Inventory"],
        legacyRole: "STAFF",
      }),
    ).toHaveLength(0);
    expect(
      getAccessibleStaffArticles({
        ...completeRetailStaffViewer,
        permissions: ["access-staff-dashboard"],
      }).every((article) => article.access.anyOfPermissions.includes("access-staff-dashboard")),
    ).toBe(true);

    const productArticle = getStaffArticleBySlug("creating-product-from-inventory");
    expect(productArticle).toBeDefined();
    const jobOrdersOnly = getAccessibleStaffArticles({
      ...completeRetailStaffViewer,
      permissions: ["access-staff-job-orders"],
    });
    expect(jobOrdersOnly).not.toContain(productArticle);
  });

  it("searches localized copy, keywords, statuses, and rejection terms only after filtering", () => {
    const accessible = getAccessibleStaffArticles(completeRetailStaffViewer);
    const englishStatusResults = searchStaffArticles(accessible, "under review", "en");
    const tagalogResults = searchStaffArticles(accessible, "tinanggihan", "tl");
    const refundResults = searchStaffArticles(accessible, "refund", "en");

    expect(englishStatusResults.map((article) => article.slug)).toContain(
      "price-request-outcomes",
    );
    expect(tagalogResults.length).toBeGreaterThan(0);
    expect(refundResults.map((article) => article.slug)).toEqual(
      expect.arrayContaining([
        "review-customer-refund-request",
        "refund-return-statuses",
      ]),
    );

    const jobOrdersOnly = getAccessibleStaffArticles({
      ...completeRetailStaffViewer,
      permissions: ["access-staff-job-orders"],
    });
    expect(
      searchStaffArticles(jobOrdersOnly, "product", "en").every((article) =>
        article.access.anyOfPermissions.includes("access-staff-job-orders"),
      ),
    ).toBe(true);
  });

  it("removes categories that become empty after access filtering", () => {
    const jobOrdersOnly = getAccessibleStaffArticles({
      ...completeRetailStaffViewer,
      permissions: ["access-staff-job-orders"],
    });
    const categories = getStaffArticleCategories(jobOrdersOnly);

    expect(categories.length).toBeGreaterThan(0);
    expect(categories.every((category) => category.count > 0)).toBe(true);
    expect(categories.map((category) => category.key)).not.toContain("products");
  });
});
