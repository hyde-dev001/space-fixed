import React from "react";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import ErpCommandSearch from "../ErpCommandSearch";

const state = vi.hoisted(() => ({
  url: "/erp/staff/dashboard",
  props: {
    auth: {
      permissions: [
        "access-staff-dashboard",
        "access-staff-job-orders",
        "access-product-management",
        "access-product-upload-staff",
        "access-shoe-pricing",
        "access-staff-time",
        "access-view-payslip",
      ],
      user: {
        role: "STAFF",
        roles: ["Staff"],
        shop_owner: { business_type: "retail" },
      },
    },
  } as Record<string, unknown>,
}));

vi.mock("@inertiajs/react", () => ({
  Link: ({ href, children, ...props }: React.PropsWithChildren<{ href: string }>) => (
    <a href={href} {...props}>{children}</a>
  ),
  usePage: () => state,
}));

const ownerShell = {
  groups: [
    {
      key: "retail",
      label: "Retail",
      items: [
        {
          key: "orders",
          label: "Job Orders",
          canonical_url: "/shop-owner/erp/orders",
          available: true,
          unavailable_reason: null,
          management_url: null,
          active_matching: ["/shop-owner/erp/orders"],
        },
        {
          key: "hidden",
          label: "Hidden Module",
          canonical_url: "/shop-owner/erp/hidden",
          available: false,
          unavailable_reason: "not_available",
          management_url: "/shop-owner/settings",
          active_matching: ["/shop-owner/erp/hidden"],
        },
      ],
    },
  ],
};

beforeEach(() => {
  state.url = "/erp/staff/dashboard";
  state.props = {
    auth: {
      permissions: [
        "access-staff-dashboard",
        "access-staff-job-orders",
        "access-product-management",
        "access-product-upload-staff",
        "access-shoe-pricing",
        "access-staff-time",
        "access-view-payslip",
      ],
      user: {
        role: "STAFF",
        roles: ["Staff"],
        shop_owner: { business_type: "retail" },
      },
    },
  };
});

describe("ErpCommandSearch", () => {
  it("shows the scoped Staff article suggestion", async () => {
    render(<ErpCommandSearch />);

    const input = screen.getByRole("combobox", { name: /search or type command/i });
    fireEvent.change(input, { target: { value: "article" } });

    const suggestion = await screen.findByRole("option", { name: /staff pages and access/i });
    expect(suggestion).toHaveAttribute("data-kind", "article");
    expect(suggestion.querySelector("a")).toHaveAttribute(
      "href",
      "/erp/articles/staff-workspace-permissions",
    );
  });

  it("keeps Manager results out of a Staff-scoped search", async () => {
    render(<ErpCommandSearch />);

    fireEvent.change(screen.getByRole("combobox", { name: /search or type command/i }), {
      target: { value: "manager dashboard" },
    });

    await waitFor(() => {
      expect(screen.queryByRole("option", { name: /manager dashboard/i })).not.toBeInTheDocument();
      expect(screen.queryByText(/manager articles/i)).not.toBeInTheDocument();
    });
  });

  it("supports arrow navigation and Escape", async () => {
    render(<ErpCommandSearch />);
    const input = screen.getByRole("combobox", { name: /search or type command/i });

    fireEvent.change(input, { target: { value: "attendance" } });
    await screen.findByRole("listbox");
    fireEvent.keyDown(input, { key: "ArrowDown" });

    expect(input).toHaveAttribute("aria-activedescendant");
    fireEvent.keyDown(input, { key: "Escape" });

    expect(screen.queryByRole("listbox")).not.toBeInTheDocument();
    expect(input).toHaveFocus();
  });

  it("shows available owner-shell pages and hides unavailable ones", async () => {
    state.url = "/shop-owner/erp/orders";
    state.props = {
      ownerShell,
      auth: {
        erpActor: { type: "shop_owner", ownerMode: true },
        shop_owner: { business_type: "retail", registration_type: "individual" },
      },
    };

    render(<ErpCommandSearch />);
    fireEvent.change(screen.getByRole("combobox", { name: /search or type command/i }), {
      target: { value: "job orders" },
    });

    expect(await screen.findByRole("option", { name: /job orders/i })).toBeInTheDocument();
    expect(screen.queryByText(/hidden module/i)).not.toBeInTheDocument();
  });
});
