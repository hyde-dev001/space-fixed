import React from "react";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, expect, it, vi } from "vitest";
import type { OwnerShellMetadata } from "../../types/ownerShell";
import CanonicalOwnerLayout from "../CanonicalOwnerLayout";
import AppLayoutShopOwner from "../AppLayout_shopOwner";
import AppLayoutERP from "../AppLayout_ERP";

const state = vi.hoisted(() => ({
  ownerShell: null as OwnerShellMetadata | null,
  auth: {} as Record<string, unknown>,
  activeModule: null as null | Record<string, unknown>,
  url: "/shop-owner/home",
}));

const routeMock = vi.hoisted(() => vi.fn((name: string) => (
  name === "shop-owner.shell.settings.profile" ? "/shop-owner/settings/profile" : `/${name}`
)));

vi.mock("@inertiajs/react", () => ({
  usePage: () => ({
    url: state.url,
    props: { auth: state.auth, ownerShell: state.ownerShell, activeModule: state.activeModule },
  }),
  Link: ({ href, children, ...props }: React.PropsWithChildren<{ href: string }>) => (
    <a href={href} {...props}>{children}</a>
  ),
}));

vi.mock("ziggy-js", () => ({ route: routeMock }));

vi.mock("../CanonicalOwnerSidebar", () => ({
  default: () => <aside data-testid="canonical-owner-sidebar" />,
}));

vi.mock("../AppSidebar_shopOwner", () => ({ default: () => <aside data-testid="existing-owner-sidebar" /> }));
vi.mock("../AppHeader_shopOwner", () => ({ default: () => <header data-testid="existing-owner-header" /> }));
vi.mock("../AppSidebar_ERP", () => ({ default: () => <aside data-testid="existing-erp-sidebar" /> }));
vi.mock("../AppHeader_ERP", () => ({ default: () => <header data-testid="existing-erp-header" /> }));

vi.mock("../../components/common/NotificationBell", () => ({
  default: () => <button type="button" aria-label="Notifications">Notifications</button>,
}));

vi.mock("../../components/common/ThemeToggleButton", () => ({
  ThemeToggleButton: () => <button type="button" aria-label="Toggle theme">Theme</button>,
}));

vi.mock("../../components/header/ShopOwnerDropdown", () => ({
  default: ({ urls }: { urls?: { profile?: string; settings?: string } }) => (
    <div
      data-testid="shop-owner-dropdown"
      data-profile-url={urls?.profile}
      data-settings-url={urls?.settings}
    />
  ),
}));

const metadata: OwnerShellMetadata = {
  presentation: "canonical",
  selection_reason: "shop_allowlisted",
  context: "individual",
  groups: [{
    key: "home",
    label: "Home",
    order: 0,
    default_expanded: true,
    items: [{
      key: "home",
      label: "Home",
      canonical_url: "/shop-owner/home",
      available: true,
      unavailable_reason: null,
      management_url: null,
      active_matching: ["/shop-owner/home"],
    }],
  }],
};

beforeEach(() => {
  state.ownerShell = metadata;
  state.auth = {};
  state.activeModule = null;
  state.url = "/shop-owner/home";
  Object.defineProperty(window, "innerWidth", {
    configurable: true,
    writable: true,
    value: 500,
  });
  window.route = (name: string) => name === "landing" ? "/" : `/${name}`;
});

afterEach(() => {
  state.ownerShell = null;
  state.auth = {};
  state.activeModule = null;
  state.url = "/shop-owner/home";
});

it("restores focus to the mobile menu trigger after the canonical drawer closes", async () => {
  render(
    <CanonicalOwnerLayout metadata={metadata}>
      <div>canonical content</div>
    </CanonicalOwnerLayout>,
  );

  const trigger = screen.getByRole("button", { name: "Toggle Sidebar" });
  fireEvent.click(trigger);

  const backdrop = await screen.findByTestId("sidebar-backdrop");
  expect(backdrop).toHaveClass("motion-reduce:transition-none");
  fireEvent.click(backdrop);

  await waitFor(() => expect(trigger).toHaveFocus());
});

it("keeps the canonical frame and reduced-motion transition hooks present", () => {
  render(
    <CanonicalOwnerLayout metadata={metadata}>
      <div>canonical content</div>
    </CanonicalOwnerLayout>,
  );

  expect(screen.getByTestId("canonical-owner-frame")).toHaveClass("motion-reduce:transition-none");
  expect(screen.getByTestId("canonical-owner-frame")).toHaveClass("erp-theme", "canonical-owner-theme");
  expect(screen.getByTestId("canonical-owner-sidebar")).toBeInTheDocument();
  expect(screen.getByText("canonical content")).toBeInTheDocument();
});

it("selects the canonical frame for a direct Shop Owner page", () => {
  state.auth = { shop_owner: { id: 1 } };
  render(<AppLayoutShopOwner><div>owner page</div></AppLayoutShopOwner>);

  expect(screen.getByTestId("canonical-owner-frame")).toContainElement(screen.getByText("owner page"));
  expect(screen.queryByTestId("existing-owner-sidebar")).not.toBeInTheDocument();
});

it("keeps canonical shell branding and profile links on canonical destinations", () => {
  state.auth = { shop_owner: { id: 1 } };
  render(<AppLayoutShopOwner><div>owner page</div></AppLayoutShopOwner>);

  expect(screen.getAllByRole("link", { name: "SoleSpace" })).toHaveLength(1);
  expect(screen.getAllByRole("link", { name: "SoleSpace" }).every((link) => (
    link.getAttribute("href") === "/shop-owner/home"
  ))).toBe(true);
  expect(screen.getByTestId("shop-owner-dropdown")).toHaveAttribute(
    "data-profile-url",
    "/shop-owner/settings/profile",
  );
  expect(screen.getByTestId("shop-owner-dropdown")).toHaveAttribute(
    "data-settings-url",
    "/shop-owner/settings/profile",
  );
  expect(routeMock).toHaveBeenCalledWith("shop-owner.shell.settings.profile");
});

it("keeps the existing Shop Owner frame when canonical metadata is absent", () => {
  state.ownerShell = null;
  state.auth = { shop_owner: { id: 1 } };
  render(<AppLayoutShopOwner><div>owner page</div></AppLayoutShopOwner>);

  expect(screen.getByTestId("existing-owner-sidebar")).toBeInTheDocument();
  expect(screen.queryByTestId("canonical-owner-frame")).not.toBeInTheDocument();
});

it("selects the canonical frame for owner-mode ERP pages", () => {
  state.auth = { erpActor: { type: "shop_owner", ownerMode: true } };
  render(<AppLayoutERP><div>owner ERP page</div></AppLayoutERP>);

  expect(screen.getByTestId("canonical-owner-frame")).toContainElement(screen.getByText("owner ERP page"));
  expect(screen.queryByTestId("existing-erp-sidebar")).not.toBeInTheDocument();
});

it("renders local tabs from the server-provided active module for owner ERP pages", () => {
  state.auth = { erpActor: { type: "shop_owner", ownerMode: true } };
  state.url = "/shop-owner/erp/crm/customers";
  state.activeModule = {
    label: "Customers",
    overview: { label: "Dashboard", url: "/shop-owner/operate/customers" },
    pages: [{ label: "Customers", url: "/shop-owner/erp/crm/customers" }],
  };

  render(<AppLayoutERP><div>owner ERP page</div></AppLayoutERP>);

  expect(screen.getByRole("navigation", { name: "Customers navigation" })).toBeInTheDocument();
  expect(screen.getByRole("link", { name: "Customers" })).toHaveAttribute("aria-current", "page");
});

it("keeps the existing ERP frame for owner-mode pages on existing presentation", () => {
  state.ownerShell = null;
  state.auth = { erpActor: { type: "shop_owner", ownerMode: true } };
  render(<AppLayoutERP><div>owner ERP page</div></AppLayoutERP>);

  expect(screen.getByTestId("existing-erp-sidebar")).toBeInTheDocument();
  expect(screen.queryByTestId("canonical-owner-frame")).not.toBeInTheDocument();
});

it("never selects the owner frame for employee ERP pages", () => {
  state.auth = { erpActor: { type: "employee", ownerMode: false } };
  render(<AppLayoutERP><div>employee ERP page</div></AppLayoutERP>);

  expect(screen.getByTestId("existing-erp-sidebar")).toBeInTheDocument();
  expect(screen.queryByTestId("canonical-owner-frame")).not.toBeInTheDocument();
});

it("does not render owner local tabs for employee ERP pages with the same module payload", () => {
  state.auth = { erpActor: { type: "employee", ownerMode: false } };
  state.url = "/shop-owner/erp/crm/customers";
  state.activeModule = {
    label: "Customers",
    overview: { label: "Dashboard", url: "/shop-owner/operate/customers" },
    pages: [{ label: "Customers", url: "/shop-owner/erp/crm/customers" }],
  };

  render(<AppLayoutERP><div>employee ERP page</div></AppLayoutERP>);

  expect(screen.queryByRole("navigation", { name: "Customers navigation" })).not.toBeInTheDocument();
});

it("keeps the existing frame when canonical metadata is incomplete", () => {
  state.ownerShell = {
    presentation: "canonical",
    selection_reason: "shop_allowlisted",
    context: "company",
    groups: [],
  };
  state.auth = { shop_owner: { id: 1 } };
  render(<AppLayoutShopOwner><div>owner page</div></AppLayoutShopOwner>);

  expect(screen.getByTestId("existing-owner-sidebar")).toBeInTheDocument();
  expect(screen.queryByTestId("canonical-owner-frame")).not.toBeInTheDocument();
});
