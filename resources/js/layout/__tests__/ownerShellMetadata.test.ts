import { expect, it } from "vitest";
import { readCanonicalOwnerShell } from "../ownerShellMetadata";

const item = (overrides: Record<string, unknown> = {}) => ({
  key: "home",
  label: "Home",
  canonical_url: "/shop-owner/home",
  available: true,
  unavailable_reason: null,
  management_url: null,
  active_matching: ["/shop-owner/home", "/shop-owner/dashboard"],
  children: [],
  ...overrides,
});

it("accepts server-provided groups with empty children arrays", () => {
  const metadata = readCanonicalOwnerShell({
    presentation: "canonical",
    selection_reason: "always_on",
    context: "company",
    groups: [{
      key: "home",
      label: "Home",
      order: 0,
      default_expanded: true,
      items: [item({
        children: [item({
          key: "product-management",
          label: "Product Management",
          canonical_url: "/shop-owner/erp/retail/products",
          active_matching: ["/shop-owner/erp/retail/products*"],
        })],
      })],
    }],
  });

  expect(metadata).not.toBeNull();
  expect(metadata?.groups[0]?.items[0]?.children?.[0]?.label).toBe("Product Management");
});
