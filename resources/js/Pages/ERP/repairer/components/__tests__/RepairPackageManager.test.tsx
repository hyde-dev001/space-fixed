import React from "react";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const mocks = vi.hoisted(() => ({
  get: vi.fn(),
  fire: vi.fn(),
}));

vi.mock("axios", () => ({
  default: { get: mocks.get },
}));

vi.mock("sweetalert2", () => ({
  default: { fire: mocks.fire },
}));

import RepairPackageManager from "../RepairPackageManager";

beforeEach(() => {
  vi.clearAllMocks();
  mocks.get.mockImplementation(async (url: string) => {
    if (url === "/api/repair-packages") {
      return { data: { success: true, data: [] } };
    }

    if (url === "/api/repair-services") {
      return {
        data: {
          success: true,
          data: [
            {
              id: 1,
              name: "Sole replacement",
              category: "Repair",
              price: "300",
              duration: "1 to 2 hours",
              status: "Active",
            },
            {
              id: 2,
              name: "Leather conditioning",
              category: "Care",
              price: "180",
              duration: "30 minutes",
              status: "Active",
            },
          ],
        },
      };
    }

    if (url === "/api/repairer/materials") {
      return { data: { success: true, data: [] } };
    }

    if (url === "/api/repair-packages/analytics") {
      return { data: { success: true, data: null } };
    }

    throw new Error(`Unexpected GET ${url}`);
  });
});

afterEach(() => {
  cleanup();
});

describe("RepairPackageManager service selection", () => {
  it("keeps a selected service card readable instead of using a dark theme state", async () => {
    render(<RepairPackageManager />);

    fireEvent.click(await screen.findByRole("button", { name: /Add Package/i }));

    const firstService = screen.getByText("Sole replacement");
    const serviceCard = firstService.closest("label");
    expect(serviceCard).not.toBeNull();

    fireEvent.click(screen.getAllByRole("checkbox")[0]);

    await waitFor(() => {
      expect(serviceCard).toHaveClass("bg-gray-100");
    });
    expect(serviceCard).not.toHaveClass("bg-blue-50");
    expect(firstService).toHaveClass("text-gray-900");
  });
});
