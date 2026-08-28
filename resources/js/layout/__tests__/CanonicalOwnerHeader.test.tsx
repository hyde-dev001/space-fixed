import React from "react";
import { render, screen } from "@testing-library/react";
import { expect, it, vi } from "vitest";
import CanonicalOwnerHeader from "../CanonicalOwnerHeader";

vi.mock("@inertiajs/react", () => ({
  usePage: () => ({
    props: {
      auth: {
        erpActor: {
          type: "shop_owner",
          ownerMode: true,
          name: "Urban Kicks Store",
        },
      },
      erpUrls: {},
    },
  }),
  Link: ({ href, children, ...props }: React.PropsWithChildren<{ href: string }>) => (
    <a href={href} {...props}>{children}</a>
  ),
}));

vi.mock("ziggy-js", () => ({
  route: () => "/shop-owner/settings/profile",
}));

vi.mock("../../context/SidebarContext", () => ({
  useSidebar: () => ({
    isExpanded: true,
    isMobileOpen: false,
    toggleSidebar: vi.fn(),
    toggleMobileSidebar: vi.fn(),
  }),
}));

vi.mock("../../components/common/NotificationBell", () => ({
  default: () => <button type="button" aria-label="Notifications" />,
}));

vi.mock("../../components/common/ThemeToggleButton", () => ({
  ThemeToggleButton: () => <button type="button" aria-label="Toggle theme" />,
}));

vi.mock("../../components/header/ShopOwnerDropdown", () => ({
  default: () => <div data-testid="shop-owner-dropdown" />,
}));

it("hides the duplicate SoleSpace wordmark in Light Mode while retaining the Dark Mode brand hook", () => {
  render(<CanonicalOwnerHeader menuButtonRef={{ current: null }} />);

  expect(screen.getByLabelText("Search")).toBeInTheDocument();
  expect(screen.getByRole("link", { name: "SoleSpace" })).toHaveClass("hidden", "dark:inline-flex");
});
