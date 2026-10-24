import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { expect, it } from "vitest";

it("keeps the app visible while animating the shared sidebar item", () => {
  const css = readFileSync(resolve(process.cwd(), "resources/css/app.css"), "utf8");
  const rootTransition = css.match(
    /html:has\(#app \.erp-theme\)::view-transition-old\(root\),\s*html:has\(#app \.erp-theme\)::view-transition-new\(root\)\s*\{([\s\S]*?)\}/,
  )?.[1] ?? "";

  expect(rootTransition).not.toMatch(/opacity\s*:/);
  expect(css).toContain("#app .erp-theme :is(.erp-sidebar, #canonical-owner-sidebar)");
});
