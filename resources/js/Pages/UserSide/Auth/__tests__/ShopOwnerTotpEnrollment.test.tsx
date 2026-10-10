import React from "react";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

const mocks = vi.hoisted(() => ({ axiosPost: vi.fn() }));

vi.mock("axios", () => ({
    default: {
        post: mocks.axiosPost,
        isAxiosError: () => false,
    },
}));

vi.mock("@inertiajs/react", () => ({
    Head: () => null,
    Link: ({ children, ...props }: React.PropsWithChildren<Record<string, unknown>>) => <a {...props}>{children}</a>,
}));

vi.mock("ziggy-js", () => ({
    route: (name: string) => `/${name}`,
}));

import ShopOwnerTotpEnrollment from "../ShopOwnerTotpEnrollment";

describe("ShopOwnerTotpEnrollment", () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it("shows authenticator setup details without email or resend controls", () => {
        render(
            <ShopOwnerTotpEnrollment
                qr_code="data:image/svg+xml;base64,qr"
                manual_key="ABCDEFGHIJKLMNOP"
                expires_at={1_000}
            />,
        );

        expect(screen.getByAltText("Authenticator app QR code")).toBeInTheDocument();
        expect(screen.getByText("ABCDEFGHIJKLMNOP")).toBeInTheDocument();
        expect(screen.getByLabelText("Six-digit verification code")).toBeInTheDocument();
        expect(screen.queryByText(/email|resend/i)).not.toBeInTheDocument();
    });

    it("submits the enrollment code and shows returned recovery codes once", async () => {
        mocks.axiosPost.mockResolvedValueOnce({ data: { recovery_codes: ["ABCD-1234-EF56-7890-ABCD"] } });

        render(
            <ShopOwnerTotpEnrollment
                qr_code="data:image/svg+xml;base64,qr"
                manual_key="ABCDEFGHIJKLMNOP"
                expires_at={1_000}
            />,
        );

        fireEvent.change(screen.getByLabelText("Six-digit verification code"), { target: { value: "123456" } });
        fireEvent.click(screen.getByRole("button", { name: "Verify authenticator" }));

        await waitFor(() => expect(mocks.axiosPost).toHaveBeenCalledWith(
            "/shop-owner.two-factor.enroll.verify",
            { code: "123456" },
            expect.objectContaining({ headers: { Accept: "application/json" } }),
        ));
        expect(await screen.findByText("ABCD-1234-EF56-7890-ABCD")).toBeInTheDocument();
        expect(screen.getByRole("link", { name: "Continue to SoleSpace" })).toHaveAttribute("href", "/shop-owner.dashboard");
        expect(screen.queryByRole("button", { name: "Verify authenticator" })).not.toBeInTheDocument();
    });
});
