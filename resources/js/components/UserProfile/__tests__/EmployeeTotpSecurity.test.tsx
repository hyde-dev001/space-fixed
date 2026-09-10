import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

const { axiosPostMock } = vi.hoisted(() => ({
    axiosPostMock: vi.fn(),
}));

vi.mock("axios", () => ({
    default: {
        post: axiosPostMock,
        get: vi.fn(),
        isAxiosError: () => false,
    },
}));

import EmployeeTotpSecurity from "../EmployeeTotpSecurity";

describe("EmployeeTotpSecurity", () => {
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
});
