import axios from "axios";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { purchaseOrderApi } from "../purchaseOrderApi";
import { supplierApi } from "../supplierApi";

vi.mock("axios", () => ({ default: { get: vi.fn(), put: vi.fn() } }));

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

	it("uses the supplier payment-profile endpoints", async () => {
		vi.mocked(axios.get).mockResolvedValue({ data: { data: { id: 8, status: "verified" } } });
		vi.mocked(axios.put).mockResolvedValue({ data: { data: { id: 8, status: "unverified" } } });

		expect(await supplierApi.getPaymentProfile(7)).toEqual({ id: 8, status: "verified" });
		expect(await supplierApi.upsertPaymentProfile(7, {
			destination_type: "bank_account",
			bank_name: "Test Bank",
			bank_code: "TBK",
			account_name: "Supplier",
			account_number: "1234567890",
		})).toEqual({ id: 8, status: "unverified" });

		expect(axios.get).toHaveBeenCalledWith("/api/erp/procurement/suppliers/7/payment-profile");
		expect(axios.put).toHaveBeenCalledWith("/api/erp/procurement/suppliers/7/payment-profile", expect.any(Object));
	});
});
