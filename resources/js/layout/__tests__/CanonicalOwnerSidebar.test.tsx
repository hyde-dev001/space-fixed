import React from "react";
import { fireEvent, render, screen, within } from "@testing-library/react";
import { beforeEach, expect, it, vi } from "vitest";
import CanonicalOwnerSidebar from "../CanonicalOwnerSidebar";
import type { OwnerShellMetadata } from "../../types/ownerShell";

const state = vi.hoisted(() => ({
  url: "/shop-owner/home",
  isExpanded: true,
  isMobileOpen: false,
  isHovered: false,
}));

vi.mock("@inertiajs/react", () => ({
  usePage: () => ({ url: state.url, props: { auth: {} } }),
  Link: ({ href, children, ...props }: React.PropsWithChildren<{ href: string }>) => (
    <a href={href} {...props}>{children}</a>
  ),
}));

vi.mock("../../context/SidebarContext", () => ({
  useSidebar: () => ({
    isExpanded: state.isExpanded,
    isMobileOpen: state.isMobileOpen,
    isHovered: state.isHovered,
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
      key: "action-center",
      label: "Approval Center",
      order: 5,
      default_expanded: true,
      items: [item({ key: "action-center", label: "Approval Center", canonical_url: "/shop-owner/action-center", active_matching: ["/shop-owner/action-center"] })],
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
  ...overrides,
});

beforeEach(() => {
  state.url = "/shop-owner/home";
  state.isExpanded = true;
  state.isMobileOpen = false;
  state.isHovered = false;
  window.localStorage.clear();
});

it("orders and expands the primary group for an individual owner", () => {
  render(<CanonicalOwnerSidebar metadata={metadata()} />);

  const groups = screen.getAllByRole("button").filter((button) => button.hasAttribute("data-group-key"));
  expect(groups.map((group) => group.getAttribute("data-group-key"))).toEqual([
    "home",
    "action-center",
    "operate",
    "oversee",
    "reports",
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

  const groups = screen.getAllByRole("button").filter((button) => button.hasAttribute("data-group-key"));
  expect(groups.map((group) => group.getAttribute("data-group-key"))).toEqual([
    "home",
    "action-center",
    "oversee",
    "operate",
    "reports",
  ]);
  expect(screen.getByTestId("canonical-owner-group-oversee")).toHaveAttribute("aria-expanded", "true");
  expect(screen.getByRole("link", { name: "Finance" })).toBeVisible();
});

it("omits disabled modules and their empty groups from the sidebar", () => {
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
  expect(screen.queryByTestId("canonical-owner-group-operate")).not.toBeInTheDocument();
  expect(screen.queryByText("This module is disabled for the shop.")).not.toBeInTheDocument();
  expect(screen.queryByRole("link", { name: /manage in settings/i })).not.toBeInTheDocument();
});

it("uses canonical URLs for Home, Reports, Audit, and Settings", () => {
  render(<CanonicalOwnerSidebar metadata={metadata()} />);

  expect(screen.getByRole("link", { name: "SoleSpace" }))
    .toHaveAttribute("href", "/shop-owner/home");
  expect(screen.getByRole("link", { name: "SoleSpace" }))
    .toHaveClass("text-[#111111]", "dark:text-blue-300");
  expect(screen.getByRole("link", { name: "Home" })).toHaveAttribute("href", "/shop-owner/home");
  expect(screen.getByRole("link", { name: "Home" }))
    .toHaveClass("bg-[#111111]", "text-white", "dark:bg-blue-500/15", "dark:text-blue-300");
  expect(screen.getByRole("link", { name: "Reports" })).toHaveAttribute("href", "/shop-owner/reports");
  expect(screen.getByRole("link", { name: "Audit" })).toHaveAttribute("href", "/shop-owner/audit");
  expect(screen.queryByRole("link", { name: "Profile" })).not.toBeInTheDocument();
});

it("keeps Approval Center separate and renders only metadata-provided module links", () => {
  render(<CanonicalOwnerSidebar metadata={metadata()} />);

  expect(screen.getByTestId("canonical-owner-group-action-center")).toBeInTheDocument();
  expect(screen.getByRole("link", { name: "Approval Center" })).toHaveAttribute(
    "href",
    "/shop-owner/action-center",
  );

  const operateItems = screen.getByRole("list", { name: "Operate" });
  expect(within(operateItems).getAllByRole("link")).toHaveLength(1);
  expect(within(operateItems).getByRole("link", { name: "Retail" })).toHaveAttribute(
    "href",
    "/shop-owner/operate/retail",
  );
});

it("matches one canonical item when a compatibility URL is active", () => {
  state.url = "/shop-owner/erp/retail/orders?status=open";
  render(<CanonicalOwnerSidebar metadata={metadata()} />);

  const activeLinks = screen.getAllByRole("link").filter((link) => link.getAttribute("aria-current") === "page");
  expect(activeLinks).toHaveLength(1);
  expect(activeLinks[0]).toHaveTextContent("Retail");
  expect(activeLinks[0]).toHaveClass("menu-item-active");
});

it("keeps an active nested page visible and expands its parent group", () => {
  state.url = "/shop-owner/erp/retail/products";
  const nestedMetadata = metadata({
    groups: metadata().groups.map((group) => group.key === "operate"
      ? {
          ...group,
          items: [item({
            children: [
              item({
                key: "job-orders-retail",
                label: "Job Orders Retail",
                canonical_url: "/shop-owner/erp/retail/orders",
                active_matching: ["/shop-owner/erp/retail/orders*"],
              }),
              item({
                key: "product-management",
                label: "Product Management",
                canonical_url: "/shop-owner/erp/retail/products",
                active_matching: ["/shop-owner/erp/retail/products*"],
              }),
            ],
          })],
        }
      : group),
  });

  render(<CanonicalOwnerSidebar metadata={nestedMetadata} />);

  expect(screen.getByRole("link", { name: "Product Management" })).toHaveAttribute("aria-current", "page");
  expect(screen.getByRole("link", { name: "Retail" })).not.toHaveAttribute("aria-current", "page");
  expect(screen.getByTestId("canonical-owner-group-operate")).toHaveAttribute("aria-expanded", "true");
});

it("keeps collapsed destinations visually identifiable with icons and labels", () => {
  state.isExpanded = false;
  render(<CanonicalOwnerSidebar metadata={metadata()} />);

  const retailLink = screen.getByRole("link", { name: "Retail" });
  expect(retailLink).toHaveAttribute("title", "Retail");
  expect(within(retailLink).getByTestId("canonical-owner-item-icon-retail")).toBeVisible();
  expect(screen.getByTestId("canonical-owner-group-operate")).toHaveAttribute("title", "Operate");
  expect(within(screen.getByTestId("canonical-owner-group-operate")).getByTestId("canonical-owner-group-icon-operate")).toBeVisible();
});

it("does not render a retired ERP Workspace compatibility link", () => {
  render(<CanonicalOwnerSidebar metadata={metadata()} />);

  const primary = screen.getByTestId("canonical-owner-primary-navigation");
  expect(within(primary).queryByRole("link", { name: /open existing erp workspace/i })).not.toBeInTheDocument();
  expect(screen.queryByTestId("canonical-owner-compatibility")).not.toBeInTheDocument();
});

it("does not render Business Settings as a primary navigation group", () => {
  render(<CanonicalOwnerSidebar metadata={metadata()} />);

  expect(screen.queryByTestId("canonical-owner-group-settings")).not.toBeInTheDocument();
  expect(screen.queryByRole("link", { name: "Profile" })).not.toBeInTheDocument();
});

it("reads presentation metadata directly without needing client authorization inputs", () => {
  render(<CanonicalOwnerSidebar metadata={metadata()} />);

  fireEvent.click(screen.getByRole("button", { name: "Oversee" }));
  expect(screen.getByRole("link", { name: "Finance" })).toBeVisible();
});

it("keeps manually expanded groups open when page metadata refreshes after navigation", () => {
  const companyMetadata = metadata({
    context: "company",
    groups: metadata().groups.map((group) => (
      group.key === "operate" || group.key === "reports"
        ? { ...group, default_expanded: false }
        : group
    )),
  });
  const { rerender } = render(<CanonicalOwnerSidebar metadata={companyMetadata} />);

  fireEvent.click(screen.getByRole("button", { name: "Operate" }));
  fireEvent.click(screen.getByRole("button", { name: "Reports & Audit" }));
  expect(screen.getByTestId("canonical-owner-group-operate")).toHaveAttribute("aria-expanded", "true");
  expect(screen.getByTestId("canonical-owner-group-reports")).toHaveAttribute("aria-expanded", "true");

  state.url = "/shop-owner/operate/retail";
  rerender(<CanonicalOwnerSidebar metadata={{ ...companyMetadata, groups: companyMetadata.groups.map((group) => ({ ...group })) }} />);

  expect(screen.getByTestId("canonical-owner-group-operate")).toHaveAttribute("aria-expanded", "true");
  expect(screen.getByTestId("canonical-owner-group-reports")).toHaveAttribute("aria-expanded", "true");
});

it("restores expanded groups when the canonical sidebar remounts after navigation", () => {
  const companyMetadata = metadata({
    context: "company",
    groups: metadata().groups.map((group) => (
      group.key === "operate" || group.key === "reports"
        ? { ...group, default_expanded: false }
        : group
    )),
  });
  const firstRender = render(<CanonicalOwnerSidebar metadata={companyMetadata} />);

  fireEvent.click(screen.getByRole("button", { name: "Operate" }));
  fireEvent.click(screen.getByRole("button", { name: "Reports & Audit" }));
  firstRender.unmount();

  state.url = "/shop-owner/reports";
  render(<CanonicalOwnerSidebar metadata={{ ...companyMetadata, groups: companyMetadata.groups.map((group) => ({ ...group })) }} />);

  expect(screen.getByTestId("canonical-owner-group-operate")).toHaveAttribute("aria-expanded", "true");
  expect(screen.getByTestId("canonical-owner-group-reports")).toHaveAttribute("aria-expanded", "true");
});
