import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const sourcePath = resolve(
  process.cwd(),
  "resources/js/Pages/ShopOwner/Repairs/service management/uploadService.tsx",
);

describe("Shop Owner repair service materials contract", () => {
  it("loads existing repair materials from the canonical owner endpoint", () => {
    const source = readFileSync(sourcePath, "utf8");

    expect(source).toContain("axios.get('/api/shop-owner/repair-materials'");
    expect(source).not.toContain("axios.get('/api/shop-owner/inventory/items'");
  });
});
