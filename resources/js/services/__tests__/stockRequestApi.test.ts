import axios from "axios";
import { describe, expect, it, vi } from "vitest";
import { stockRequestApi } from "../stockRequestApi";

vi.mock("axios", () => ({ default: { post: vi.fn() } }));

describe("stockRequestApi", () => {
	it("returns the updated stock request from an approval response", async () => {
		const updatedRequest = { id: 1, status: "accepted" };
		vi.mocked(axios.post).mockResolvedValueOnce({ data: { message: "Approved", stock_request: updatedRequest } });

		await expect(stockRequestApi.approve(1)).resolves.toBe(updatedRequest);
	});
});
