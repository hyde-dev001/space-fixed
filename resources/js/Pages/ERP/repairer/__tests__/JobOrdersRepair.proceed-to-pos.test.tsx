import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const currentDir = dirname(fileURLToPath(import.meta.url));
const sourcePath = resolve(currentDir, "../JobOrdersRepair.tsx");

describe("JobOrdersRepair POS CTA", () => {
  it("does not include Proceed to POS action or repairer POS redirect", () => {
    const source = readFileSync(sourcePath, "utf-8");

    expect(source).not.toContain("/erp/repairer/point-of-sale");
    expect(source).not.toContain('aria-label="Proceed to POS"');
  });
});
