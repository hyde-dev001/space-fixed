import { describe, expect, it } from "vitest";
import { Ziggy } from "../ziggy";

describe("employee security route manifest", () => {
    it("contains the routes used by the profile security controls", () => {
        expect(Ziggy.routes).toMatchObject({
            "erp.security.activity": expect.objectContaining({
                uri: "erp/security/activity",
            }),
            "erp.security.sessions.index": expect.objectContaining({
                uri: "erp/security/sessions",
            }),
            "erp.security.totp.setup": expect.objectContaining({
                uri: "erp/security/totp/setup",
            }),
            "customer.security.activity": expect.objectContaining({
                uri: "customer-profile/security/activity",
            }),
            "customer.security.totp.setup": expect.objectContaining({
                uri: "customer-profile/security/totp/setup",
            }),
            "customer.security.totp.verify": expect.objectContaining({
                uri: "customer-profile/security/totp/verify",
            }),
            "customer.mfa.challenge": expect.objectContaining({
                uri: "customer/mfa/challenge",
            }),
            "shop-owner.security.totp.setup": expect.objectContaining({
                uri: "shop-owner/security/totp/setup",
            }),
            "shop-owner.security.totp.verify": expect.objectContaining({
                uri: "shop-owner/security/totp/verify",
            }),
            "shop-owner.security.totp.recovery.regenerate": expect.objectContaining({
                uri: "shop-owner/security/totp/recovery-codes/regenerate",
            }),
            "shop-owner.security.totp.disable": expect.objectContaining({
                uri: "shop-owner/security/totp/disable",
            }),
            "shop-owner.two-factor.challenge": expect.objectContaining({
                uri: "shop-owner/two-factor",
            }),
            "shop-owner.two-factor.verify": expect.objectContaining({
                uri: "shop-owner/two-factor/verify",
            }),
        });

        expect(Ziggy.routes).not.toHaveProperty("customer.security.sessions.index");
        expect(Ziggy.routes).not.toHaveProperty("customer.security.sessions.logout-others");
        expect(Ziggy.routes).not.toHaveProperty("shop-owner.two-factor.resend");
    });
});
