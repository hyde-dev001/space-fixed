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
        });
    });
});
