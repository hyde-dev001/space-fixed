import { fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

const { axiosGetMock, axiosPostMock } = vi.hoisted(() => ({
    axiosGetMock: vi.fn(),
    axiosPostMock: vi.fn(),
}));

vi.mock("axios", () => ({
    default: {
        get: axiosGetMock,
        isAxiosError: () => false,
        post: axiosPostMock,
    },
}));

import EmployeeTotpSecurity from "../EmployeeTotpSecurity";

describe("EmployeeTotpSecurity", () => {
    it("renders server-provided activity labels with paginated history controls", () => {
        (globalThis as { route?: (name: string) => string }).route = (name: string) => `/${name}`;

        render(
            <EmployeeTotpSecurity
                enabled={false}
                activity={[
                    {
                        action: "employee_password_changed",
                        label: "Password changed",
                        description: "Employee changed their password.",
                        created_at: "2026-09-08T09:00:00+00:00",
                    },
                ]}
                active_sessions={[
                    {
                        device: "Chrome / Windows",
                        last_active_at: "2026-09-08T09:00:00+00:00",
                        current: true,
                    },
                ]}
            />,
        );

        expect(screen.getByText("Password changed")).toBeInTheDocument();
        expect(screen.getAllByRole("button", { name: "View all" })).toHaveLength(2);
    });

    it("shows recovery codes immediately after successful enrollment", async () => {
        axiosPostMock
            .mockResolvedValueOnce({
                data: {
                    expires_at: "2026-09-08T09:30:00+00:00",
                    manual_key: "JBSWY3DPEHPK3PXP",
                    qr_code: "data:image/png;base64,qr",
                },
            })
            .mockResolvedValueOnce({
                data: { recovery_codes: ["8F3K-9P2A", "Q9VN-3T4L"] },
            });

        render(<EmployeeTotpSecurity enabled={false} activity={[]} active_sessions={[]} />);

        fireEvent.click(screen.getByRole("button", { name: "Enable Two-Factor Authentication" }));
        fireEvent.change(await screen.findByLabelText("Confirm current password"), { target: { value: "CurrentPass1!" } });
        fireEvent.click(screen.getByRole("button", { name: "Continue" }));

        await screen.findByText("1. Scan the QR code");
        fireEvent.change(screen.getByLabelText("2. Enter the 6-digit code"), { target: { value: "123456" } });
        fireEvent.click(screen.getByRole("button", { name: "Verify and Enable" }));

        expect(await screen.findByText("Your Recovery Codes")).toBeInTheDocument();
        expect(screen.getByText("8F3K-9P2A")).toBeInTheDocument();
        expect(screen.getByText("Q9VN-3T4L")).toBeInTheDocument();
    });
});
