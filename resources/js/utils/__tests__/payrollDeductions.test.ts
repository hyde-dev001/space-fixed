import { describe, expect, it } from "vitest";
import {
  deductionPercentageOfGross,
  formatDeductionPercentage,
} from "../payrollDeductions";

describe("payroll deduction percentages", () => {
  it("calculates an effective deduction percentage from gross pay", () => {
    expect(deductionPercentageOfGross(720, 16117.8)).toBe(4.47);
    expect(formatDeductionPercentage("720.00", "16117.80")).toBe("4.47%");
  });

  it("uses the absolute deduction amount for serialized negative values", () => {
    expect(formatDeductionPercentage(-402.95, 16117.8)).toBe("2.50%");
  });

  it("returns zero instead of dividing by zero", () => {
    expect(deductionPercentageOfGross(100, 0)).toBe(0);
    expect(formatDeductionPercentage(100, undefined)).toBe("0.00%");
  });
});
