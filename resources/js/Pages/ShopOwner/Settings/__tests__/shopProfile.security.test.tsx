import { render, screen } from "@testing-library/react";
import type { ReactNode } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

const { usePageMock, routerPostMock } = vi.hoisted(() => ({
  usePageMock: vi.fn(),
  routerPostMock: vi.fn(),
}));

vi.mock("@inertiajs/react", () => ({
  Head: () => null,
  usePage: () => usePageMock(),
  router: {
    post: routerPostMock,
  },
}));

vi.mock("../../../../layout/AppLayout_shopOwner", () => ({
  default: ({ children }: { children: ReactNode }) => <div>{children}</div>,
}));

import ShopProfile from "../shopProfile";

describe("shop owner profile security card", () => {
  beforeEach(() => {
    usePageMock.mockReset();
    routerPostMock.mockReset();

    usePageMock.mockReturnValue({
      props: {
        shop_owner: {
          id: 1,
          first_name: "Shop",
          last_name: "Owner",
          name: "Shop Owner",
          business_name: "Test Shop",
          email: "owner@example.com",
          phone: "09123456789",
          business_address: "Address",
        },
      },
    });
  });

  it("renders change password section", () => {
    render(<ShopProfile />);

    expect(screen.getAllByText(/change password/i).length).toBeGreaterThan(0);
  });
});
