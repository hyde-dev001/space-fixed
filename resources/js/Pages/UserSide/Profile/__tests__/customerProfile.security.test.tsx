import { render, screen } from "@testing-library/react";
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

    usePageMock.mockReturnValue({
      url: "/customer-profile",
      props: {
        user: {
          id: 1,
          first_name: "Test",
          last_name: "Customer",
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
      },
    });
  });

  it("shows Change Password even when personal info is not in edit mode", () => {
    render(<CustomerProfile />);

    expect(screen.getAllByText(/change password/i).length).toBeGreaterThan(0);
  });
});
