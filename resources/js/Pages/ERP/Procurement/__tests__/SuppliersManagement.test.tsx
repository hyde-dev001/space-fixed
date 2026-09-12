import React from "react";
import { fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import SuppliersManagement from "../SuppliersManagement";

const mocks = vi.hoisted(() => ({
	getAll: vi.fn(),
}));

vi.mock("@inertiajs/react", () => ({
	Head: ({ children }: React.PropsWithChildren) => <>{children}</>,
	usePage: () => ({
		props: {
			initialData: { data: [] },
			auth: { erpActor: { ownerMode: false } },
			erpCapabilities: {},
		},
	}),
}));

vi.mock("@/layout/AppLayout_ERP", () => ({
	default: ({ children }: React.PropsWithChildren) => <>{children}</>,
}));

vi.mock("@/services/procurementApi", () => ({
	supplierApi: { getAll: mocks.getAll },
}));

vi.mock("@/utils/erpCapabilities", () => ({ erpUrl: () => null }));
vi.mock("sweetalert2", () => ({ default: { fire: vi.fn() } }));

describe("SuppliersManagement payment-term fields", () => {
	beforeEach(() => {
		mocks.getAll.mockResolvedValue({ data: [] });
	});

	it("exposes supplier profile fields and only supported payment terms", async () => {
		render(<SuppliersManagement />);

		fireEvent.click(await screen.findByRole("button", { name: "+ Add Supplier" }));

		expect(screen.getAllByText("City").length).toBeGreaterThan(0);
		expect(screen.getAllByText("Country").length).toBeGreaterThan(0);
		expect(screen.getAllByText("Lead Time (days)").length).toBeGreaterThan(0);
		expect(screen.getAllByText("Products Supplied").length).toBeGreaterThan(0);
		expect(screen.getAllByRole("option", { name: "COD" }).length).toBeGreaterThan(0);
		expect(screen.getAllByRole("option", { name: "Net 60" }).length).toBeGreaterThan(0);
		expect(screen.queryByRole("option", { name: "Net 90" })).not.toBeInTheDocument();
	});
});
