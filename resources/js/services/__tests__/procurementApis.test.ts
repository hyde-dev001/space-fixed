import axios from "axios";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { purchaseOrderApi } from "../purchaseOrderApi";
import { supplierApi } from "../supplierApi";

vi.mock("axios", () => ({ default: { get: vi.fn() } }));

describe("procurement list API contracts", () => {
	beforeEach(() => vi.clearAllMocks());

	it("keeps the Laravel paginator for purchase order lists", async () => {
		const paginator = { current_page: 1, data: [{ id: 1 }], last_page: 1, per_page: 20, total: 1 };
		vi.mocked(axios.get).mockResolvedValue({ data: paginator });

		expect(await purchaseOrderApi.getAll()).toEqual(paginator);
	});

	it("keeps the Laravel paginator for supplier lists", async () => {
		const paginator = { current_page: 1, data: [{ id: 1 }], last_page: 1, per_page: 20, total: 1 };
		vi.mocked(axios.get).mockResolvedValue({ data: paginator });

		expect(await supplierApi.getAll()).toEqual(paginator);
	});
});
