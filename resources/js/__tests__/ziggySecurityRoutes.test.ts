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
        });

        expect(Ziggy.routes).not.toHaveProperty("customer.security.sessions.index");
        expect(Ziggy.routes).not.toHaveProperty("customer.security.sessions.logout-others");
    });
});
