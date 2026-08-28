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
          city_state: "Bacoor, Cavite",
          country: "Philippines",
          profile_photo: "profile-photos/shop-owner.jpg",
          cover_photo: "cover-photos/shop-cover.jpg",
        },
      },
    });
  });

  it("renders change password section", () => {
    render(<ShopProfile />);

    expect(screen.getAllByText(/change password/i).length).toBeGreaterThan(0);
  });

  it("renders the responsive profile shell with monochrome photo actions", () => {
    render(<ShopProfile />);

    expect(screen.getByRole("heading", { name: "Shop Profile" })).toBeInTheDocument();
    expect(screen.getByRole("img", { name: "Test Shop profile photo" })).toHaveAttribute(
      "src",
      "/storage/profile-photos/shop-owner.jpg"
    );
    expect(screen.getByRole("img", { name: "Test Shop cover photo" })).toHaveAttribute(
      "src",
      "/storage/cover-photos/shop-cover.jpg"
    );
    expect(screen.getByRole("button", { name: "Edit profile" })).toHaveClass("bg-black");
    expect(screen.getByRole("button", { name: "Upload cover photo" })).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: "Personal information" })).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: "Operating hours" })).toBeInTheDocument();
    expect(screen.getByLabelText("Current password")).toBeInTheDocument();
  });
});
