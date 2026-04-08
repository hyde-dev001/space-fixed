import React from "react";
import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import PointOfSalePage from "../POS";
import {
	getAllowedPosModes,
	normalizePosBusinessType,
	resolveInitialPosMode,
} from "../posBusinessType";

const mockAxiosGet = vi.fn();
const mockAxiosPost = vi.fn();
const mockAxiosPatch = vi.fn();

let mockPageProps: any = {};

vi.mock("axios", () => ({
	default: {
		get: (...args: any[]) => mockAxiosGet(...args),
		post: (...args: any[]) => mockAxiosPost(...args),
		patch: (...args: any[]) => mockAxiosPatch(...args),
	},
}));

vi.mock("sweetalert2", () => ({
	default: {
		fire: vi.fn().mockResolvedValue({ isConfirmed: true }),
	},
}));

vi.mock("../../../../layout/AppLayout_ERP", () => ({
	default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock("../../Repairs/posPaymentValidation", () => ({
	computeCanPay: vi.fn().mockReturnValue(true),
	getPhoneDisplayForReceipt: vi.fn().mockImplementation((_: string, phone: string) => phone),
}));

vi.mock("../../../services/repairPosHistoryApi", () => ({
	repairPosHistoryApi: {
		listTransactions: vi.fn().mockResolvedValue({ data: { data: { data: [] } } }),
		requestRefund: vi.fn().mockResolvedValue({ data: { refund_id: 1, data: { status: "requested" } } }),
	},
}));

vi.mock("../../../utils/repairPricing", () => ({
	buildRepairBreakdown: vi.fn().mockReturnValue({
		netSubtotal: 0,
		vatAmount: 0,
		grandTotal: 0,
	}),
}));

vi.mock("@inertiajs/react", () => ({
	Head: ({ title }: { title: string }) => <div data-testid="head-title">{title}</div>,
	usePage: () => ({ props: mockPageProps }),
}));

describe("POS business-type mode gating", () => {
	it("normalizes both variants to both", () => {
		expect(normalizePosBusinessType("both (retail & repair)")).toBe("both");
	});

	it("returns allowed mode set for each business type", () => {
		expect(getAllowedPosModes("retail")).toEqual(["retail"]);
		expect(getAllowedPosModes("repair")).toEqual(["repair"]);
		expect(getAllowedPosModes("both")).toEqual(["repair", "retail"]);
	});

	it("falls back to allowed mode when query mode is forbidden", () => {
		expect(resolveInitialPosMode("retail", "repair")).toBe("retail");
		expect(resolveInitialPosMode("repair", "retail")).toBe("repair");
		expect(resolveInitialPosMode("both", "retail")).toBe("retail");
	});

	beforeEach(() => {
		mockAxiosGet.mockReset();
		mockAxiosPost.mockReset();
		mockAxiosPatch.mockReset();

		mockAxiosGet.mockResolvedValue({ data: { data: [] } });
		mockAxiosPost.mockResolvedValue({ data: {} });
		mockAxiosPatch.mockResolvedValue({ data: {} });
	});

	it("shows only Retail mode for retail business type", () => {
		mockPageProps = {
			auth: {
				user: {
					name: "Cashier",
					shop_owner: {
						business_type: "retail",
					},
				},
			},
		};

		render(<PointOfSalePage />);

		expect(screen.getByText("Retail POS")).toBeInTheDocument();
		expect(screen.queryByText("Repair POS")).not.toBeInTheDocument();
	});

	it("shows only Repair mode for repair business type", () => {
		mockPageProps = {
			auth: {
				user: {
					name: "Cashier",
					shop_owner: {
						business_type: "repair",
					},
				},
			},
		};

		render(<PointOfSalePage />);

		expect(screen.getByText("Repair POS")).toBeInTheDocument();
		expect(screen.queryByText("Retail POS")).not.toBeInTheDocument();
	});

	it("shows both mode switch for both business type", () => {
		mockPageProps = {
			auth: {
				user: {
					name: "Cashier",
					shop_owner: {
						business_type: "both",
					},
				},
			},
		};

		render(<PointOfSalePage />);

		expect(screen.getByRole("tab", { name: "Repair" })).toBeInTheDocument();
		expect(screen.getByRole("tab", { name: "Retail" })).toBeInTheDocument();
	});
});
