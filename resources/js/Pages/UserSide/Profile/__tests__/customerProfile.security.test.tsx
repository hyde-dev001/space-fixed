import { fireEvent, render, screen, within } from "@testing-library/react";
import type { ReactNode } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

const { usePageMock, routerPostMock } = vi.hoisted(() => ({
  usePageMock: vi.fn(),
  routerPostMock: vi.fn(),
}));

vi.mock("@inertiajs/react", () => ({
  Head: () => null,
  Link: ({ children, href }: { children: ReactNode; href?: string }) => (
    <a href={href}>{children}</a>
  ),
  usePage: () => usePageMock(),
  router: {
    post: routerPostMock,
  },
}));

vi.mock("../../Shared/Navigation", () => ({
  default: () => <div>Navigation</div>,
}));

vi.mock("../../Shared/UserModal", () => ({
  default: {
    fire: vi.fn(),
  },
}));

vi.mock("../../../../hooks/useBadgeCounts", () => ({
  useBadgeCounts: () => ({
    chatIconCount: 0,
  }),
}));

import CustomerProfile from "../customerProfile";

describe("customer profile security card", () => {
  beforeEach(() => {
    usePageMock.mockReset();
    routerPostMock.mockReset();
    (globalThis as { route?: (name: string) => string }).route = (name: string) => `/${name}`;

    usePageMock.mockReturnValue({
      url: "/customer-profile",
      props: {
        user: {
          id: 1,
          first_name: "Test",
          last_name: "Customer",
          suffix: "Jr.",
          name: "Test Customer",
          email: "customer@example.com",
          phone: null,
          address: null,
          profile_photo_url: null,
        },
        auth: {
          user: {
            id: 1,
            shop_owner_id: null,
          },
        },
        identity_verification: {
          status: "pending_review",
          current: {
            id: 1,
            document_type: "student_id",
            screening_status: "manual_review_required",
            review_status: "pending",
            front_url: null,
            back_url: null,
          },
          can_resubmit: false,
          history: [],
        },
        security: {
          totp_enabled: false,
          activity: [],
        },
      },
    });
  });

  it("shows Change Password only after entering personal info edit mode", () => {
    render(<CustomerProfile />);

    expect(screen.queryByText("Change Password")).not.toBeInTheDocument();

    fireEvent.click(screen.getAllByRole("button", { name: "Edit" })[0]);

    expect(screen.getAllByText("Change Password").length).toBeGreaterThan(0);
    expect(screen.getAllByText("At least 12 characters").length).toBeGreaterThan(0);
    expect(screen.getAllByText("One special character").length).toBeGreaterThan(0);
  });

  it("shows customer security controls without Active Sessions", () => {
    render(<CustomerProfile />);

    expect(screen.getByText("Two-Factor Authentication")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Enable Two-Factor Authentication" })).toBeInTheDocument();
    expect(screen.queryByText("Active Sessions")).not.toBeInTheDocument();
    expect(screen.queryByText("Log Out Other Sessions")).not.toBeInTheDocument();
  });

  it("renders the suffix returned by the customer profile", () => {
    render(<CustomerProfile />);

    expect(screen.getAllByText("Suffix").length).toBeGreaterThan(0);
    expect(screen.getAllByText("Jr.").length).toBeGreaterThan(0);
  });

  it("keeps Change Password inside each Personal Information section", () => {
    render(<CustomerProfile />);
    fireEvent.click(screen.getAllByRole("button", { name: "Edit" })[0]);

    screen.getAllByTestId("personal-information-section").forEach((section) => {
      expect(within(section).getByText("Change Password")).toBeInTheDocument();
    });
  });

  it("uses the neutral status background for Pending Review", () => {
    render(<CustomerProfile />);

    screen.getAllByText("Pending Review").forEach((badge) => {
      expect(badge).toHaveClass("bg-gray-100");
    });
  });
});
