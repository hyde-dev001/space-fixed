import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

const { axiosGetMock, axiosPostMock } = vi.hoisted(() => ({
    axiosGetMock: vi.fn(),
    axiosPostMock: vi.fn(),
}));

vi.mock("axios", () => ({
    default: {
        post: axiosPostMock,
        get: axiosGetMock,
        isAxiosError: () => false,
    },
}));

import EmployeeTotpSecurity from "../EmployeeTotpSecurity";

describe("EmployeeTotpSecurity", () => {
    beforeEach(() => {
        axiosGetMock.mockReset();
        axiosPostMock.mockReset();
    });

    it("renders the MFA setup and displays recovery codes after enrollment", async () => {
        (globalThis as { route?: (name: string) => string }).route = (name: string) => `/${name}`;
        axiosPostMock
            .mockResolvedValueOnce({ data: { expires_at: "2026-09-08T09:30:00+00:00", manual_key: "JBSWY3DPEHPK3PXP", qr_code: "data:image/png;base64,qr" } })
            .mockResolvedValueOnce({ data: { recovery_codes: ["8F3K-9P2A"] } });

        render(<EmployeeTotpSecurity enabled={false} activity={[]} active_sessions={[]} />);

        fireEvent.click(screen.getByRole("button", { name: "Enable Two-Factor Authentication" }));
        fireEvent.change(await screen.findByLabelText("Confirm current password"), { target: { value: "CurrentPass1!" } });
        fireEvent.click(screen.getByRole("button", { name: "Continue" }));
        await screen.findByText("1. Scan the QR code");
        fireEvent.change(screen.getByLabelText("2. Enter the 6-digit code"), { target: { value: "123456" } });
        fireEvent.click(screen.getByRole("button", { name: "Verify and Enable" }));

        expect(await screen.findByText("Your Recovery Codes")).toBeInTheDocument();
        expect(screen.getByText("8F3K-9P2A")).toBeInTheDocument();
    });

    it("uses customer endpoints and omits active sessions when configured", async () => {
        (globalThis as { route?: (name: string) => string }).route = (name: string) => `/${name}`;
        axiosPostMock.mockResolvedValueOnce({ data: { expires_at: "2026-09-08T09:30:00+00:00", manual_key: "JBSWY3DPEHPK3PXP", qr_code: "data:image/png;base64,qr" } });

        render(
            <EmployeeTotpSecurity
                enabled={false}
                showSessions={false}
                routes={{
                    setup: "/customer/setup",
                    verify: "/customer/verify",
                    recovery: "/customer/recovery",
                    disable: "/customer/disable",
                    activity: "/customer/activity",
                }}
            />,
        );

        fireEvent.click(screen.getByRole("button", { name: "Enable Two-Factor Authentication" }));
        fireEvent.change(await screen.findByLabelText("Confirm current password"), { target: { value: "CurrentPass1!" } });
        fireEvent.click(screen.getByRole("button", { name: "Continue" }));

        await screen.findByText("1. Scan the QR code");
        expect(axiosPostMock).toHaveBeenCalledWith("/customer/setup", { current_password: "CurrentPass1!" });
        expect(screen.queryByText("Active Sessions")).not.toBeInTheDocument();
    });

    it("loads customer security activity from the configured endpoint", async () => {
        axiosGetMock.mockResolvedValueOnce({
            data: {
                data: [{
                    action: "customer_login_succeeded",
                    label: "Successful login",
                    description: "Customer signed in successfully.",
                    created_at: "2026-09-10T10:00:00+08:00",
                }],
                meta: { current_page: 1, last_page: 1, per_page: 10, total: 1 },
            },
        });

        render(
            <EmployeeTotpSecurity
                enabled={false}
                showSessions={false}
                activity={[]}
                routes={{
                    setup: "/customer/setup",
                    verify: "/customer/verify",
                    recovery: "/customer/recovery",
                    disable: "/customer/disable",
                    activity: "/customer/activity",
                }}
            />,
        );

        fireEvent.click(screen.getByRole("button", { name: "View all" }));

        await waitFor(() => expect(axiosGetMock).toHaveBeenCalledWith(
            "/customer/activity",
            { params: { page: 1, per_page: 10 } },
        ));
        expect(await screen.findByText("Customer signed in successfully.")).toBeInTheDocument();
    });
    it('can omit the activity card', () => {
        render(<EmployeeTotpSecurity enabled={false} showSessions={false} showActivity={false} routes={{ setup: '/shop-owner/setup', verify: '/shop-owner/verify', recovery: '/shop-owner/recovery', disable: '/shop-owner/disable', activity: '/shop-owner/activity' }} />);

        expect(screen.queryByText('Recent Security Activity')).not.toBeInTheDocument();
    });
});
