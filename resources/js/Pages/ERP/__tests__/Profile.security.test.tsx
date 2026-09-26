import { render, screen } from "@testing-library/react";
import type { ReactNode } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

const { useFormMock, usePageMock } = vi.hoisted(() => ({
  useFormMock: vi.fn(),
  usePageMock: vi.fn(),
}));

vi.mock("@inertiajs/react", () => ({
  Head: () => null,
  router: { visit: vi.fn() },
  useForm: useFormMock,
  usePage: () => usePageMock(),
}));

vi.mock("sweetalert2", () => ({
  default: { fire: vi.fn() },
}));

vi.mock("../../../layout/AppLayout_ERP", () => ({
  default: ({ children }: { children: ReactNode }) => <div>{children}</div>,
}));

import Profile from "../Profile";

describe("ERP profile password requirements", () => {
  beforeEach(() => {
    useFormMock.mockReset();
    usePageMock.mockReset();

    useFormMock.mockImplementation((initialData) => ({
      data: initialData,
      setData: vi.fn(),
      post: vi.fn(),
      processing: false,
      errors: {},
      reset: vi.fn(),
    }));
    usePageMock.mockReturnValue({ props: { flash: {} } });
  });

  it("renders the password requirements", () => {
    render(
      <Profile
        user={{
          id: 1,
          name: "Test Employee",
          email: "employee@example.com",
          role: "STAFF",
        }}
        requiresPasswordChange={false}
      />,
    );

    expect(screen.getByText("At least 12 characters")).toBeInTheDocument();
    expect(screen.getByText("One special character")).toBeInTheDocument();
  });

  it("hides active sessions from employee profiles", () => {
    (globalThis as { route?: (name: string) => string }).route = (name: string) => `/${name}`;

    render(
      <Profile
        user={{
          id: 1,
          name: "Test Employee",
          email: "employee@example.com",
          role: "STAFF",
        }}
        requiresPasswordChange={false}
        security={{
          is_employee: true,
          totp_enabled: false,
          activity: [],
          active_sessions: [{ device: "Opera / Windows", last_active_at: "2026-09-20T07:28:31Z", current: true }],
        }}
      />,
    );

    expect(screen.queryByText("Active Sessions")).not.toBeInTheDocument();
    expect(screen.queryByText("Log Out Other Sessions")).not.toBeInTheDocument();
  });
});
