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
  pageState.props.auth.permissions = [...fullPermissions];
  pageState.props.auth.user.role = "STAFF";
  pageState.props.auth.user.roles = ["Staff"];
  pageState.props.auth.user.shop_owner = { business_type: "retail" };
  pageState.props.auth.shop_owner = { business_type: "retail" };
  localStorage.clear();
  window.history.replaceState({}, "", "/erp/articles");
});

describe("Staff Articles page", () => {
  it("renders the searchable hub, recommendations, categories, and results", () => {
    render(<ArticlesIndex />);

    expect(screen.getByRole("heading", { name: /staff articles/i })).toBeInTheDocument();
    expect(screen.getByRole("searchbox", { name: /search staff articles/i })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /getting started/i })).toBeInTheDocument();
    expect(screen.getAllByText(/staff pages and access/i).length).toBeGreaterThan(0);
    expect(screen.getAllByText(/using the staff dashboard/i).length).toBeGreaterThan(0);
    expect(screen.getByText(/32 articles/i)).toBeInTheDocument();
  });

  it("supports Tagalog search and persists the language choice", () => {
    render(<ArticlesIndex />);

    fireEvent.click(screen.getByRole("button", { name: /tagalog/i }));

    expect(localStorage.getItem("solespace:staff-articles:language")).toBe("tl");
    expect(screen.getByRole("heading", { name: /mga artikulo para sa staff/i })).toBeInTheDocument();

    fireEvent.change(screen.getByRole("searchbox", { name: /maghanap/i }), {
      target: { value: "tinanggihan" },
    });

    expect(screen.getByText(/resulta ng price request/i)).toBeInTheDocument();
  });

  it("shows a no-results reset state and preserves search/filter state in the URL", () => {
    render(<ArticlesIndex />);

    fireEvent.change(screen.getByRole("searchbox", { name: /search staff articles/i }), {
      target: { value: "no matching article" },
    });
    fireEvent.click(screen.getByRole("button", { name: /orders & returns/i }));

    expect(screen.getByText(/no staff articles match/i)).toBeInTheDocument();
    expect(window.location.search).toContain("q=no+matching+article");
    expect(window.location.search).toContain("category=orders");

    fireEvent.click(screen.getByRole("button", { name: /clear search and filters/i }));

    expect(screen.getByRole("searchbox", { name: /search staff articles/i })).toHaveValue("");
    expect(window.location.search).toBe("");
    expect(screen.getAllByText(/staff pages and access/i).length).toBeGreaterThan(0);
  });

  it("renders valid details, an invalid slug, and an inaccessible known slug", () => {
    const { rerender } = render(<ArticlesIndex />);

    pageState.props.articleSlug = "daily-attendance-workflow";
    pageState.url = "/erp/articles/daily-attendance-workflow";
    rerender(<ArticlesIndex />);
    expect(screen.getByRole("heading", { name: /daily attendance workflow/i })).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: /what happens next/i })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /back to all staff articles/i })).toHaveAttribute(
      "href",
      "/erp/articles",
    );

    pageState.props.articleSlug = "does-not-exist";
    pageState.url = "/erp/articles/does-not-exist";
    rerender(<ArticlesIndex />);
    expect(screen.getByRole("heading", { name: /article not found/i })).toBeInTheDocument();

    pageState.props.articleSlug = "creating-product-from-inventory";
    pageState.props.auth.permissions = ["access-staff-job-orders"];
    pageState.url = "/erp/articles/creating-product-from-inventory";
    rerender(<ArticlesIndex />);
    expect(screen.getByRole("heading", { name: /article unavailable/i })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /back to all staff articles/i })).toHaveAttribute(
      "href",
      "/erp/articles",
    );
  });

  it("hydrates query and category state from the browser URL without a network search", () => {
    window.history.replaceState({}, "", "/erp/articles?q=refund&category=orders");
    render(<ArticlesIndex />);

    expect(screen.getByRole("searchbox", { name: /search staff articles/i })).toHaveValue("refund");
    expect(screen.getByRole("button", { name: /orders & returns/i })).toHaveAttribute(
      "aria-pressed",
      "true",
    );
  });
});
