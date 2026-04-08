import { describe, expect, it } from "vitest";
import { resolveAllowedModes } from "../posModeResolver";

describe("resolveAllowedModes", () => {
  it("returns both modes for business type both", () => {
    expect(resolveAllowedModes("both")).toEqual(["repair", "retail"]);
  });

  it("returns retail mode only for retail business", () => {
    expect(resolveAllowedModes("retail")).toEqual(["retail"]);
  });

  it("returns repair mode only for repair business", () => {
    expect(resolveAllowedModes("repair")).toEqual(["repair"]);
  });
});
