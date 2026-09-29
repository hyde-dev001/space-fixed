import { describe, expect, it } from "vitest";
import { isModalDraftSourceAvailable, scopedModalDraftKey } from "../modalDraft";

describe("modal draft scoping", () => {
	it("separates drafts by shop and user", () => {
		expect(scopedModalDraftKey("purchase-request", 10, 20)).toBe("purchase-request:10:20");
		expect(scopedModalDraftKey("purchase-request", 10, 20)).not.toBe(scopedModalDraftKey("purchase-request", 11, 20));
		expect(scopedModalDraftKey("purchase-request", 10, 20)).not.toBe(scopedModalDraftKey("purchase-request", 10, 21));
	});

	it("rejects a restored source outside the current shop list", () => {
		expect(isModalDraftSourceAvailable("7", [7, 8])).toBe(true);
		expect(isModalDraftSourceAvailable("9", [7, 8])).toBe(false);
		expect(isModalDraftSourceAvailable(undefined, [7, 8])).toBe(false);
	});
});
