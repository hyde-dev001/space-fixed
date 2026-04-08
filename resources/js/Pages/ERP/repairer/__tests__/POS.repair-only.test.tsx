import { render, screen } from "@testing-library/react";
import type { ReactNode } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

const usePageMock = vi.fn();
const { axiosGetMock, axiosPostMock, axiosPatchMock } = vi.hoisted(() => ({
	axiosGetMock: vi.fn(),
	axiosPostMock: vi.fn(),
	axiosPatchMock: vi.fn(),
}));

vi.mock("axios", () => ({
  default: {
    get: axiosGetMock,
    post: axiosPostMock,
    patch: axiosPatchMock,
  },
}));

vi.mock("@inertiajs/react", () => ({
  Link: ({ children, href }: { children: ReactNode; href?: string }) => <a href={href || "#"}>{children}</a>,
  Head: () => null,
  usePage: () => usePageMock(),
}));

vi.mock("../../../../layout/AppLayout_ERP", () => ({
  default: ({ children }: { children: ReactNode }) => <div>{children}</div>,
}));

vi.mock("sweetalert2", () => ({
  default: {
    fire: vi.fn(),
  },
}));

import PointOfSalePage from "../POS";

describe("Repairer POS page", () => {
  beforeEach(() => {
    const storageMock = {
      getItem: vi.fn(() => null),
      setItem: vi.fn(),
      removeItem: vi.fn(),
      clear: vi.fn(),
    };

    Object.defineProperty(window, "localStorage", {
      value: storageMock,
      writable: true,
    });
    Object.defineProperty(globalThis, "localStorage", {
      value: storageMock,
      writable: true,
    });

    axiosGetMock.mockReset();
    axiosGetMock.mockResolvedValue({ data: { data: [] } });

    usePageMock.mockReturnValue({
      props: {
        auth: {
          user: {
            name: "Repair Cashier",
            shop_owner: {
              business_type: "retail",
            },
          },
        },
      },
    });
  });

  it("never renders retail UI blocks", () => {
    render(<PointOfSalePage />);

    expect(screen.getByText("Point of Sale")).toBeInTheDocument();
    expect(screen.getByText("Repair Service Catalog")).toBeInTheDocument();
    expect(screen.queryByText("Retail Catalog")).not.toBeInTheDocument();
    expect(screen.queryByRole("tab", { name: /retail/i })).not.toBeInTheDocument();
  });
});
