import React from "react";
import { fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import ArticlesIndex from "../Index";

const fullPermissions = [
  "access-staff-dashboard",
  "access-staff-job-orders",
  "access-product-management",
  "access-product-upload-staff",
  "access-shoe-pricing",
  "access-staff-time-in",
  "access-staff-leave",
  "access-color-variant-manager",
  "access-staff-customers",
  "access-notification-center",
  "access-profile",
];

const pageState = vi.hoisted(() => ({
  url: "/erp/articles",
  props: {
    articleSlug: null as string | null,
    articleAudience: "staff" as string,
    auth: {
      permissions: [] as string[],
      user: {
        role: "STAFF",
        roles: ["Staff"],
        shop_owner: { business_type: "retail" },
      },
      shop_owner: { business_type: "retail" },
    },
  },
}));

vi.mock("@inertiajs/react", () => ({
  Head: () => null,
  Link: ({ href, children, ...props }: React.PropsWithChildren<{ href: string }>) => (
    <a href={href} {...props}>{children}</a>
  ),
  usePage: () => pageState,
}));

vi.mock("../../../../layout/AppLayout_ERP", () => ({
  default: ({ children }: React.PropsWithChildren) => <div data-testid="erp-layout">{children}</div>,
}));

beforeEach(() => {
  pageState.url = "/erp/articles";
  pageState.props.articleSlug = null;
  pageState.props.articleAudience = "staff";
  pageState.props.auth.permissions = [...fullPermissions];
  pageState.props.auth.user.role = "STAFF";
  pageState.props.auth.user.roles = ["Staff"];
  pageState.props.auth.user.shop_owner = { business_type: "retail" };
  pageState.props.auth.shop_owner = { business_type: "retail" };
  localStorage.clear();
  window.history.replaceState({}, "", "/erp/articles");
});

describe("Staff Articles page", () => {
  it("renders the hub, recommendations, categories, and results", async () => {
    render(<ArticlesIndex />);

    expect(await screen.findByRole("heading", { name: /staff articles/i })).toBeInTheDocument();
    expect(screen.queryByRole("searchbox")).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: /getting started/i })).toBeInTheDocument();
    expect(screen.getAllByText(/staff pages and access/i).length).toBeGreaterThan(0);
    expect(screen.getAllByText(/using the staff dashboard/i).length).toBeGreaterThan(0);
    expect(screen.getByText(/32 articles/i)).toBeInTheDocument();
  });

  it("supports Tagalog copy and persists the language choice", async () => {
    render(<ArticlesIndex />);

    await screen.findByRole("heading", { name: /staff articles/i });
    fireEvent.click(screen.getByRole("button", { name: /tagalog/i }));

    expect(localStorage.getItem("solespace:staff-articles:language")).toBe("tl");
    expect(await screen.findByRole("heading", { name: /mga artikulo para sa staff/i })).toBeInTheDocument();

    expect(screen.getByRole("button", { name: /pagsisimula/i })).toBeInTheDocument();
  });

  it("keeps category browsing and preserves the active category in the URL", async () => {
    render(<ArticlesIndex />);

    await screen.findByRole("heading", { name: /staff articles/i });
    fireEvent.click(screen.getByRole("button", { name: /orders & returns/i }));

    expect(await screen.findByRole("heading", { name: /orders & returns/i })).toBeInTheDocument();
    expect(window.location.search).toContain("category=orders");
    expect(screen.getAllByText(/understanding retail job orders/i).length).toBeGreaterThan(0);

    fireEvent.click(screen.getByRole("button", { name: /orders & returns/i }));

    expect(window.location.search).toBe("");
    expect(screen.getAllByText(/staff pages and access/i).length).toBeGreaterThan(0);
  });

  it("renders valid details, an invalid slug, and any article in the active catalog", async () => {
    const { rerender } = render(<ArticlesIndex />);

    await screen.findByRole("heading", { name: /staff articles/i });
    pageState.props.articleSlug = "daily-attendance-workflow";
    pageState.url = "/erp/articles/daily-attendance-workflow";
    rerender(<ArticlesIndex />);
    expect(await screen.findByRole("heading", { name: /daily attendance workflow/i })).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: /what happens next/i })).toBeInTheDocument();
    expect(screen.queryByRole("heading", { name: /who can use this/i })).not.toBeInTheDocument();
    expect(screen.queryByRole("heading", { name: /common problems and what to do/i })).not.toBeInTheDocument();
    expect(screen.getByRole("link", { name: /back to all articles/i })).toHaveAttribute(
      "href",
      "/erp/articles",
    );

    pageState.props.articleSlug = "does-not-exist";
    pageState.url = "/erp/articles/does-not-exist";
    rerender(<ArticlesIndex />);
    expect(await screen.findByRole("heading", { name: /article not found/i })).toBeInTheDocument();

    pageState.props.articleSlug = "creating-product-from-inventory";
    pageState.props.auth.permissions = ["access-product-upload-staff"];
    pageState.url = "/erp/articles/creating-product-from-inventory";
    rerender(<ArticlesIndex />);
    expect(await screen.findByRole("heading", { name: /creating a product from inventory/i })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /back to all articles/i })).toHaveAttribute(
      "href",
      "/erp/articles",
    );
  });

  it("hydrates category state from the browser URL", async () => {
    window.history.replaceState({}, "", "/erp/articles?category=orders");
    render(<ArticlesIndex />);

    expect(await screen.findByRole("heading", { name: /orders & returns/i })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /orders & returns/i })).toHaveAttribute(
      "aria-pressed",
      "true",
    );
  });

  it("keeps each account on its own article catalog", async () => {
    const { rerender } = render(<ArticlesIndex />);

    await screen.findByRole("heading", { name: /staff articles/i });

    pageState.props.articleAudience = "manager";
    pageState.props.auth.permissions = ["access-manager-dashboard"];
    pageState.props.auth.user.role = "MANAGER";
    pageState.props.auth.user.roles = ["Manager"];
    pageState.url = "/erp/manager/articles";
    window.history.replaceState({}, "", "/erp/manager/articles");
    rerender(<ArticlesIndex />);

    expect(await screen.findByRole("heading", { name: /manager articles/i })).toBeInTheDocument();
    expect(screen.queryByRole("heading", { name: /staff articles/i })).not.toBeInTheDocument();
    expect(screen.queryByRole("searchbox")).not.toBeInTheDocument();
    expect(screen.getAllByText(/use the manager dashboard/i).length).toBeGreaterThan(0);
    expect(screen.getAllByRole("link").some((link) => (
      link.getAttribute("href")?.startsWith("/erp/manager/articles/") ?? false
    ))).toBe(true);
  });

  it("does not expose a Repairer catalog article without its feature permission", async () => {
    pageState.props.articleAudience = "repairer";
    pageState.props.auth.permissions = [];
    pageState.props.auth.user.role = "STAFF";
    pageState.props.auth.user.roles = [];
    pageState.props.auth.user.shop_owner = { business_type: "repair" };
    pageState.props.auth.shop_owner = { business_type: "repair" };
    pageState.url = "/erp/repairer/articles";
    window.history.replaceState({}, "", "/erp/repairer/articles");

    render(<ArticlesIndex />);

    expect(await screen.findByRole("heading", { name: /repairer articles/i })).toBeInTheDocument();
    expect(screen.getByText(/0 articles/i)).toBeInTheDocument();
    expect(screen.queryByText(/use the repair dashboard/i)).not.toBeInTheDocument();
  });

  it("filters specialized articles by the active feature permission", async () => {
    pageState.props.articleAudience = "finance";
    pageState.props.auth.permissions = ["access-finance-dashboard"];
    pageState.props.auth.user.role = "FINANCE";
    pageState.props.auth.user.roles = ["Finance"];
    pageState.url = "/finance/articles";
    window.history.replaceState({}, "", "/finance/articles");

    render(<ArticlesIndex />);

    expect(await screen.findByRole("heading", { name: /finance articles/i })).toBeInTheDocument();
    expect(screen.getByText(/1 article/i)).toBeInTheDocument();
    expect(screen.getAllByText(/use the finance dashboard/i).length).toBeGreaterThan(0);
    expect(screen.queryByText(/audit/i)).not.toBeInTheDocument();
  });
});
