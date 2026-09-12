import { render, screen, waitFor } from "@testing-library/react";
import axios from "axios";
import { beforeEach, describe, expect, it, vi } from "vitest";
import UploadStockMaterial from "../uploadStockMaterial";

vi.mock("axios", () => ({
  default: {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
  },
}));

vi.mock("sweetalert2", () => ({
  default: {
    fire: vi.fn(),
  },
}));

vi.mock("@inertiajs/react", () => ({
  Head: () => null,
  usePage: () => ({ props: { erpMode: true } }),
}));

vi.mock("../../../../../layout/AppLayout_ERP", () => ({
  default: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

vi.mock("../../../../../layout/AppLayout_shopOwner", () => ({
  default: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

const material = (id: number, name: string) => ({
  id,
  name,
  sku: `MAT-${id}`,
  available_quantity: 10,
  unit: "pcs",
  reorder_level: 10,
  reorder_quantity: 50,
  notes: null,
  created_at: "2026-08-29T00:00:00.000000Z",
});

describe("individual repair material inventory loading", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("loads every paginated material page", async () => {
    vi.mocked(axios.get).mockImplementation(async (_url, config) => {
      const page = Number(config?.params?.page ?? 1);

      return {
        data: {
          data: page === 1 ? [material(1, "First Material")] : [material(2, "Second Material")],
          current_page: page,
          last_page: 2,
        },
      } as never;
    });

    render(<UploadStockMaterial />);

    await waitFor(() => expect(screen.getByText("Second Material")).toBeInTheDocument());

    expect(screen.getByText("First Material")).toBeInTheDocument();
    expect(axios.get).toHaveBeenCalledTimes(2);
    expect(axios.get).toHaveBeenNthCalledWith(
      2,
      "/api/shop-owner/inventory/items",
      expect.objectContaining({
        params: expect.objectContaining({
          category: "repair_materials",
          page: 2,
        }),
      }),
    );
  });
});
