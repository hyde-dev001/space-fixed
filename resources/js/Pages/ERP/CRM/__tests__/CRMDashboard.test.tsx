import React from "react";
import { render, screen } from "@testing-library/react";
import { expect, it, vi } from "vitest";
import CRMDashboard from "../CRMDashboard";

const state = vi.hoisted(() => ({
  props: {
    initialStats: {
      activeCustomers: 1,
      openConversations: 1,
      pendingReviews: 0,
      averageRating: 5,
    },
    initialEngagement: [],
    initialInteractions: [{
      conversation_id: 1,
      customer_name: "Customer One",
      customer_email: "customer@example.com",
      last_message: "**Order:** ORD-1",
      last_message_at: "2 hours ago",
      status: "open",
      priority: "normal",
    }],
    auth: { erpActor: { ownerMode: true } },
    erpCapabilities: {},
  },
}));

vi.mock("@inertiajs/react", () => ({
  usePage: () => ({ props: state.props }),
  Head: ({ children }: React.PropsWithChildren) => <>{children}</>,
}));

vi.mock("react-apexcharts", () => ({
  default: () => <div data-testid="crm-chart" />,
}));

vi.mock("../../../../layout/AppLayout_ERP", () => ({
  default: ({ children }: React.PropsWithChildren) => <div>{children}</div>,
}));

vi.mock("@/utils/erpCapabilities", () => ({
  erpUrl: () => null,
}));

it("shows human-readable interaction timestamps without rendering Invalid Date", () => {
  render(<CRMDashboard />);

  expect(screen.getByText("2 hours ago")).toBeInTheDocument();
  expect(screen.getByText("Order: ORD-1")).toBeInTheDocument();
  expect(screen.queryByText("Invalid Date")).not.toBeInTheDocument();
  expect(screen.getAllByText("0%")[0].closest("div.rounded-full")).toHaveClass(
    "bg-gray-100",
    "text-gray-900",
    "dark:bg-green-900/30",
  );
});
