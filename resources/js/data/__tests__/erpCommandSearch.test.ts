import { describe, expect, it } from "vitest";

import managerCatalog from "../articleCatalogs/manager";
import staffCatalog from "../articleCatalogs/staff";
import {
  getAccessibleErpSearchPages,
  resolveErpSearchScope,
  searchErpCommands,
} from "../erpCommandSearch";
import type { ErpSearchViewer } from "../erpCommandSearch";

const staffProps = {
  auth: {
    permissions: [
      "access-staff-dashboard",
      "access-staff-job-orders",
      "access-product-management",
      "access-product-upload-staff",
      "access-shoe-pricing",
      "access-staff-time",
      "access-view-payslip",
    ],
    user: {
      role: "STAFF",
      roles: ["Staff"],
      shop_owner: { business_type: "retail" },
    },
  },
};

const staffViewer: ErpSearchViewer = {
  permissions: staffProps.auth.permissions,
  roles: ["Staff"],
  legacyRole: "STAFF",
  businessType: "retail",
  registrationType: null,
  ownerMode: false,
};

describe("account-scoped ERP command search", () => {
  it("resolves a Staff account to the Staff audience", () => {
    expect(resolveErpSearchScope("/erp/staff/dashboard", staffProps)).toBe("staff");
  });

  it("keeps Staff page suggestions inside the Staff audience", () => {
    const pages = getAccessibleErpSearchPages("staff", staffViewer);

    expect(pages.some((page) => page.label === "Retail Job Orders")).toBe(true);
    expect(pages.some((page) => page.label === "Manager Dashboard")).toBe(false);
    expect(pages.every((page) => page.scope === "staff")).toBe(true);
  });

  it("returns the Staff article when the query is the article type", () => {
    const results = searchErpCommands({
      scope: "staff",
      pages: getAccessibleErpSearchPages("staff", staffViewer),
      catalog: staffCatalog,
      query: "article",
      language: "en",
      basePath: "/erp/articles",
      viewer: staffViewer,
    });

    expect(results.some((result) => (
      result.kind === "article"
      && result.label === "Staff pages and access"
      && result.href === "/erp/articles/staff-workspace-permissions"
    ))).toBe(true);
  });

  it("does not return a catalog whose audience does not match the active scope", () => {
    const results = searchErpCommands({
      scope: "staff",
      pages: [],
      catalog: managerCatalog,
      query: "dashboard",
      language: "en",
      basePath: "/erp/articles",
      viewer: staffViewer,
    });

    expect(results).toEqual([]);
  });

  it("ranks recommended matching articles before other matching articles", () => {
    const results = searchErpCommands({
      scope: "staff",
      pages: [],
      catalog: staffCatalog,
      query: "access",
      language: "en",
      basePath: "/erp/articles",
      viewer: staffViewer,
    }).filter((result) => result.kind === "article");

    expect(results[0]?.recommended).toBe(true);
  });
});
