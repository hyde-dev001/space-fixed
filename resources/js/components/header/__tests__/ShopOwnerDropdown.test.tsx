import React from "react";
import { fireEvent, render, screen, within } from "@testing-library/react";
import { beforeEach, expect, it, vi } from "vitest";
import ShopOwnerDropdown from "../ShopOwnerDropdown";

const state = vi.hoisted(() => ({
  props: {} as Record<string, unknown>,
  router: {
    visit: vi.fn(),
    post: vi.fn(),
  },
}));

vi.mock("@inertiajs/react", () => ({
  usePage: () => ({ props: state.props }),
  router: state.router,
}));

vi.mock("sweetalert2", () => ({
  default: {
    fire: vi.fn(),
  },
}));

beforeEach(() => {
  state.props = {
    auth: {
      shop_owner: {
        business_name: "Urban Kicks Store",
        name: "Store Owner",
        email: "owner@example.com",
        profile_photo: "profile-photos/urban-kicks.jpg",
      },
    },
  };
  state.router.visit.mockReset();
  state.router.post.mockReset();
});

it("uses the shop profile photo in an icon-only trigger and keeps identity in the menu", () => {
  render(<ShopOwnerDropdown urls={{ profile: "/shop-profile", settings: "/settings" }} />);

  const trigger = screen.getByRole("button", { name: "Open account menu for Urban Kicks Store" });
  expect(trigger).toHaveAttribute("data-testid", "shop-owner-account-trigger");
  expect(trigger.querySelector("img")).toHaveAttribute("src", "/storage/profile-photos/urban-kicks.jpg");
  expect(trigger.querySelector("img")).not.toHaveClass("dark:hidden");
  expect(within(trigger).getByText("Urban Kicks Store").parentElement).toHaveClass("hidden", "dark:block");
  expect(trigger).toHaveClass("h-10", "w-10", "dark:bg-transparent");

  fireEvent.click(trigger);

  expect(screen.getAllByText("Urban Kicks Store")).toHaveLength(2);
  expect(screen.getByText("owner@example.com")).toBeInTheDocument();
  expect(screen.getByText("Shop Profile")).toBeInTheDocument();
  expect(screen.getByText("Business Settings")).toBeInTheDocument();
  expect(screen.getByText("Sign Out")).toBeInTheDocument();
  expect(trigger).toHaveAttribute("aria-expanded", "true");
});

it("uses a high-contrast outline for the ERP account trigger", () => {
  render(<ShopOwnerDropdown businessStyle />);

  expect(screen.getByTestId("shop-owner-account-trigger")).toHaveClass(
    "border-2",
    "border-gray-300",
    "dark:border-gray-500",
  );
});

it("falls back to the neutral profile icon when the shop photo cannot load", () => {
  render(<ShopOwnerDropdown />);

  const image = screen.getByRole("img", { name: "Urban Kicks Store profile photo" });
  fireEvent.error(image);

  expect(screen.queryByRole("img", { name: "Urban Kicks Store profile photo" })).not.toBeInTheDocument();
  expect(screen.getByTestId("shop-owner-avatar-fallback")).toHaveClass(
    "bg-gray-100",
    "text-gray-900",
    "dark:bg-purple-900",
    "dark:text-purple-300",
  );
});

it("keeps the compact ERP account menu photo-backed in both themes", () => {
  render(<ShopOwnerDropdown inline businessStyle />);

  const image = screen.getByRole("img", { name: "Urban Kicks Store profile photo" });
  expect(image).toHaveAttribute("src", "/storage/profile-photos/urban-kicks.jpg");
  expect(image).not.toHaveClass("dark:hidden");
  expect(image.parentElement).toHaveClass("border-2", "border-gray-300", "dark:border-gray-500");
  expect(screen.getByText("Shop Owner")).toHaveClass("bg-gray-100", "text-gray-900", "dark:bg-purple-900");
});
