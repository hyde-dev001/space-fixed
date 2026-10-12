import React from "react";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const mocks = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  fire: vi.fn(),
}));

vi.mock("axios", () => ({
  default: { get: mocks.get, post: mocks.post },
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
      return {
        data: {
          success: true,
          data: [{ id: 9, name: "Cleaning solution", available_quantity: 20 }],
        },
      };
    }

    if (url === "/api/repair-packages/analytics") {
      return { data: { success: true, data: null } };
    }

    throw new Error(`Unexpected GET ${url}`);
  });

  mocks.post.mockResolvedValue({
    data: { success: true, data: [] },
  });
});

afterEach(() => {
  cleanup();
});

describe("RepairPackageManager service selection", () => {
  it("uses a duration estimate instead of package start and end dates", async () => {
    render(<RepairPackageManager />);

    fireEvent.click(await screen.findByRole("button", { name: /Add Package/i }));

    expect(screen.getByText("Duration Estimate *")).toBeInTheDocument();
    expect(screen.getByTitle("Minimum duration")).toHaveAttribute("type", "number");
    expect(screen.getByTitle("Maximum duration (optional)")).toHaveAttribute("type", "number");
    expect(screen.getByTitle("Select duration unit")).toBeInTheDocument();
    expect(screen.queryByText("Starts At (optional)")).not.toBeInTheDocument();
    expect(screen.queryByText("Ends At (optional)")).not.toBeInTheDocument();
  });

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

  it("submits the optional package image as multipart form data", async () => {
    render(<RepairPackageManager />);

    fireEvent.click(await screen.findByRole("button", { name: /Add Package/i }));
    fireEvent.change(screen.getByPlaceholderText("e.g. Full Restore Bundle"), {
      target: { value: "Image package" },
    });
    fireEvent.change(screen.getByPlaceholderText("e.g. 948.00"), {
      target: { value: "900" },
    });
    fireEvent.change(screen.getByTitle("Minimum duration"), { target: { value: "2" } });
    fireEvent.click(screen.getAllByRole("checkbox")[0]);
    fireEvent.click(screen.getAllByRole("checkbox")[1]);
    fireEvent.click(screen.getByRole("button", { name: /Add Material/i }));
    fireEvent.change(screen.getByTitle("Select inventory material"), { target: { value: "9" } });

    const image = new File(["package image"], "package.jpg", { type: "image/jpeg" });
    fireEvent.change(screen.getByLabelText(/Package image/i), { target: { files: [image] } });
    fireEvent.click(screen.getByRole("button", { name: "Create Package" }));

    await waitFor(() => expect(mocks.post).toHaveBeenCalledWith(
      "/api/repair-packages",
      expect.any(FormData),
    ));

    const payload = mocks.post.mock.calls[0][1] as FormData;
    expect(payload.get("image")).toBe(image);
    expect(payload.get("name")).toBe("Image package");
  });
});
