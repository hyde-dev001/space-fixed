import axios from "axios";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { purchaseOrderApi } from "../purchaseOrderApi";
import { supplierApi } from "../supplierApi";

vi.mock("axios", () => ({ default: { get: vi.fn(), put: vi.fn(), post: vi.fn() } }));

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

	it("uses multipart receipt payloads when defect evidence is attached", async () => {
		const proof = new File(["proof"], "defect.jpg", { type: "image/jpeg" });
		vi.mocked(axios.post).mockResolvedValue({ data: { data: { id: 12 } } });

		await purchaseOrderApi.receive(7, {
			idempotency_key: "receipt-1",
			items: [{
				purchase_order_item_id: 8,
				received_quantity: 2,
				defective_quantity: 1,
				reason_category: "damaged",
				inventory_notes: "Damaged in transit.",
				defect_evidence: [proof],
			}],
		});

		const formData = vi.mocked(axios.post).mock.calls[0][1] as FormData;
		expect(formData).toBeInstanceOf(FormData);
		expect(formData.get("items[0][reason_category]")).toBe("damaged");
		expect(formData.get("items[0][inventory_notes]")).toBe("Damaged in transit.");
		const evidence = formData.get("items[0][defect_evidence][]") as File;
		expect(evidence.name).toBe(proof.name);
		expect(evidence.type).toBe(proof.type);
	});

	it("includes replacement linkage in multipart receipt payloads", async () => {
		const proof = new File(["proof"], "replacement-defect.jpg", { type: "image/jpeg" });
		vi.mocked(axios.post).mockResolvedValue({ data: { data: { id: 13 } } });

		await purchaseOrderApi.receive(7, {
			idempotency_key: "replacement-1",
			items: [{
				purchase_order_item_id: 8,
				replacement_for_adjustment_id: 90,
				received_quantity: 1,
				defective_quantity: 1,
				reason_category: "damaged",
				inventory_notes: "Replacement was damaged.",
				defect_evidence: [proof],
			}],
		});

		const formData = vi.mocked(axios.post).mock.calls[0][1] as FormData;
		expect(formData.get("items[0][replacement_for_adjustment_id]")).toBe("90");
	});
});
