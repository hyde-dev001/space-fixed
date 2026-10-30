import { describe, expect, it } from "vitest";

import { readArticleViewer } from "../articleViewer";

describe("readArticleViewer", () => {
  it("reads the account access fields used by article filtering", () => {
    expect(readArticleViewer({
      articleAudience: "shop-owner",
      auth: {
        permissions: ["access-owner-dashboard"],
        user: {
          role: "SHOP OWNER",
          roles: ["Shop Owner"],
          shop_owner: {
            business_type: "both",
            registration_type: "company",
          },
        },
        shop_owner: {
          business_type: "retail",
          registration_type: "individual",
        },
        erpActor: { type: "shop_owner", ownerMode: true },
      },
    }, "shop-owner")).toEqual({
      permissions: ["access-owner-dashboard"],
      roles: ["Shop Owner"],
      legacyRole: "SHOP OWNER",
      businessType: "retail",
      registrationType: "individual",
      ownerMode: true,
    });
  });

  it("normalizes malformed Inertia props to safe empty values", () => {
    expect(readArticleViewer({ auth: { permissions: "not-an-array", user: null } }, "staff")).toEqual({
      permissions: [],
      roles: [],
      legacyRole: null,
      businessType: null,
      registrationType: null,
      ownerMode: false,
    });
  });
});
