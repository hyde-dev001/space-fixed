import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import PasswordRequirements from "../PasswordRequirements";

describe("PasswordRequirements", () => {
  it("shows all requirements and marks a strong password complete", () => {
    const { rerender } = render(<PasswordRequirements password="" />);

    expect(screen.getByText("At least 8 characters")).toBeInTheDocument();
    expect(screen.getByText("One uppercase letter (A-Z)")).toBeInTheDocument();
    expect(screen.getByText("One lowercase letter (a-z)")).toBeInTheDocument();
    expect(screen.getByText("One number (0-9)")).toBeInTheDocument();
    expect(screen.getByText("One special character")).toBeInTheDocument();
    expect(screen.getAllByRole("listitem").every((item) => item.dataset.met === "false")).toBe(true);

    rerender(<PasswordRequirements password="StrongPass1!" />);

    expect(screen.getAllByRole("listitem").every((item) => item.dataset.met === "true")).toBe(true);
  });
});
