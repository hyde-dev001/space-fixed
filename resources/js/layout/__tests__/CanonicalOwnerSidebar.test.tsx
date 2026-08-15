import React from "react";
import { fireEvent, render, screen, within } from "@testing-library/react";
import { beforeEach, expect, it, vi } from "vitest";
import CanonicalOwnerSidebar from "../CanonicalOwnerSidebar";
import type { OwnerShellMetadata } from "../../types/ownerShell";

const state = vi.hoisted(() => ({
  url: "/shop-owner/home",
}));

vi.mock("@inertiajs/react", () => ({
  usePage: () => ({ url: state.url, props: { auth: {} } }),
  Link: ({ href, children, ...props }: React.PropsWithChildren<{ href: string }>) => (
    <a href={href} {...props}>{children}</a>
  ),
}));

vi.mock("../../context/SidebarContext", () => ({
  useSidebar: () => ({
    isExpanded: true,
    isMobileOpen: false,
    isHovered: false,
    setIsHovered: vi.fn(),
  }),
}));

const item = (overrides: Partial<OwnerShellMetadata["groups"][number]["items"][number]>) => ({
  key: "retail",
  label: "Retail",
  canonical_url: "/shop-owner/operate/retail",
  available: true,
  unavailable_reason: null,
  management_url: null,
  active_matching: ["/shop-owner/operate/retail", "/shop-owner/erp/retail*"],
  ...overrides,
});

const metadata = (overrides: Partial<OwnerShellMetadata> = {}): OwnerShellMetadata => ({
  presentation: "canonical",
  selection_reason: "shop_allowlisted",
  context: "individual",
  groups: [
    {
      key: "home",
      label: "Home",
      order: 0,
      default_expanded: true,
      items: [item({ key: "home", label: "Home", canonical_url: "/shop-owner/home", active_matching: ["/shop-owner/home", "/shop-owner/dashboard"] })],
    },
    {
      key: "operate",
      label: "Operate",
      order: 10,
      default_expanded: true,
      items: [item({})],
    },
    {
      key: "oversee",
      label: "Oversee",
      order: 20,
      default_expanded: false,
      items: [item({ key: "finance", label: "Finance", canonical_url: "/shop-owner/oversee/finance", active_matching: ["/shop-owner/oversee/finance"] })],
    },
    {
      key: "empty",
      label: "Empty",
      order: 25,
      default_expanded: true,
      items: [],
    },
    {
      key: "reports",
      label: "Reports & Audit",
      order: 30,
      default_expanded: true,
      items: [
        item({ key: "reports", label: "Reports", canonical_url: "/shop-owner/reports", active_matching: ["/shop-owner/reports"] }),
        item({ key: "audit", label: "Audit", canonical_url: "/shop-owner/audit", active_matching: ["/shop-owner/audit"] }),
      ],
    },
    {
      key: "settings",
      label: "Business Settings",
      order: 40,
      default_expanded: true,
      items: [
        item({ key: "settings.profile", label: "Profile", canonical_url: "/shop-owner/settings/profile", active_matching: ["/shop-owner/settings/profile"] }),
      ],
    },
  ],
  compatibility: {
    show_erp_fallback: true,
    erp_workspace_url: "/shop-owner/erp/workspace",
    fallback_url: "/shop-owner/erp/fallback?reason=user_preference&source=home",
  },
  ...overrides,
});

beforeEach(() => {
  state.url = "/shop-owner/home";
});

it("orders and expands the primary group for an individual owner", () => {
  render(<CanonicalOwnerSidebar metadata={metadata()} />);

  const groups = screen.getAllByTestId(/^canonical-owner-group-/);
  expect(groups.map((group) => group.getAttribute("data-group-key"))).toEqual([
    "home",
    "operate",
    "oversee",
    "reports",
    "settings",
  ]);
  expect(screen.getByTestId("canonical-owner-group-operate")).toHaveAttribute("aria-expanded", "true");
  expect(screen.getByTestId("canonical-owner-group-oversee")).toHaveAttribute("aria-expanded", "false");
  expect(screen.getByRole("link", { name: "Retail" })).toBeVisible();
  expect(screen.queryByRole("link", { name: "Finance" })).not.toBeInTheDocument();
});

it("puts Oversee first and expanded for a company owner", () => {
  const companyMetadata = metadata({
    context: "company",
    groups: metadata().groups.map((group) => group.key === "operate"
      ? { ...group, order: 20, default_expanded: false }
      : group.key === "oversee"
        ? { ...group, order: 10, default_expanded: true }
        : group),
  });

  render(<CanonicalOwnerSidebar metadata={companyMetadata} />);

  const groups = screen.getAllByTestId(/^canonical-owner-group-/);
  expect(groups.map((group) => group.getAttribute("data-group-key"))).toEqual([
    "home",
    "oversee",
    "operate",
    "reports",
    "settings",
  ]);
  expect(screen.getByTestId("canonical-owner-group-oversee")).toHaveAttribute("aria-expanded", "true");
  expect(screen.getByRole("link", { name: "Finance" })).toBeVisible();
});

it("omits empty groups and explains unavailable items with a management destination", () => {
  const disabledMetadata = metadata({
    groups: metadata().groups.map((group) => group.key === "operate"
      ? {
          ...group,
          items: [item({
            available: false,
            unavailable_reason: "This module is disabled for the shop.",
            management_url: "/shop-owner/settings/modules-team",
          })],
        }
      : group),
  });

  render(<CanonicalOwnerSidebar metadata={disabledMetadata} />);

  expect(screen.queryByTestId("canonical-owner-group-empty")).not.toBeInTheDocument();
  const operate = screen.getByTestId("canonical-owner-group-operate").parentElement;
  expect(operate).not.toBeNull();
  expect(within(operate as HTMLElement).getByText("This module is disabled for the shop.")).toBeInTheDocument();
  expect(within(operate as HTMLElement).getByRole("link", { name: /manage in settings/i })).toHaveAttribute(
    "href",
    "/shop-owner/settings/modules-team",
  );
  expect(within(operate as HTMLElement).queryByRole("link", { name: "Retail" })).not.toBeInTheDocument();
});

it("uses canonical URLs for Home, Reports, Audit, and Settings", () => {
  render(<CanonicalOwnerSidebar metadata={metadata()} />);

  expect(screen.getByRole("link", { name: "Home" })).toHaveAttribute("href", "/shop-owner/home");
  expect(screen.getByRole("link", { name: "Reports" })).toHaveAttribute("href", "/shop-owner/reports");
  expect(screen.getByRole("link", { name: "Audit" })).toHaveAttribute("href", "/shop-owner/audit");
  expect(screen.getByRole("link", { name: "Profile" })).toHaveAttribute("href", "/shop-owner/settings/profile");
});

it("matches one canonical item when a compatibility URL is active", () => {
  state.url = "/shop-owner/erp/retail/orders?status=open";
  render(<CanonicalOwnerSidebar metadata={metadata()} />);

  const activeLinks = screen.getAllByRole("link").filter((link) => link.getAttribute("aria-current") === "page");
  expect(activeLinks).toHaveLength(1);
  expect(activeLinks[0]).toHaveTextContent("Retail");
  expect(activeLinks[0]).toHaveClass("menu-item-active");
});

it("keeps the ERP fallback outside primary navigation", () => {
  render(<CanonicalOwnerSidebar metadata={metadata()} />);

  const primary = screen.getByTestId("canonical-owner-primary-navigation");
  const compatibility = screen.getByTestId("canonical-owner-compatibility");
  expect(within(primary).queryByRole("link", { name: /open existing erp workspace/i })).not.toBeInTheDocument();
  expect(within(compatibility).getByRole("link", { name: /open existing erp workspace/i })).toHaveAttribute(
    "href",
    "/shop-owner/erp/fallback?reason=user_preference&source=home",
  );
});

it("reads presentation metadata directly without needing client authorization inputs", () => {
  render(<CanonicalOwnerSidebar metadata={metadata()} />);

  fireEvent.click(screen.getByRole("button", { name: "Oversee" }));
  expect(screen.getByRole("link", { name: "Finance" })).toBeVisible();
});
