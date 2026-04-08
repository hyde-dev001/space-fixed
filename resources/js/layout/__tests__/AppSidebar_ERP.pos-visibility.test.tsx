import { render, screen } from "@testing-library/react";
import type { ReactNode } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

const usePageMock = vi.fn();

vi.mock("@inertiajs/react", () => ({
	Link: ({ children, href }: { children: ReactNode; href?: string }) => <a href={href || "#"}>{children}</a>,
	usePage: () => usePageMock(),
}));

vi.mock("ziggy-js", () => ({
	route: (name: string) => `/${name}`,
}));

vi.mock("../../context/SidebarContext", () => ({
	useSidebar: () => ({
		isExpanded: true,
		isMobileOpen: false,
		isHovered: false,
		setIsHovered: vi.fn(),
		openSubmenu: null,
		toggleSubmenu: vi.fn(),
		setOpenSubmenu: vi.fn(),
	}),
}));

import AppSidebar_ERP from "../AppSidebar_ERP";

const renderSidebar = (opts: { businessType: string; permissions: string[] }) => {
	usePageMock.mockReturnValue({
		url: "/erp/staff/dashboard",
		props: {
			auth: {
				user: {
					role: "STAFF",
					roles: ["STAFF"],
					shop_owner: {
						business_type: opts.businessType,
					},
				},
				permissions: opts.permissions,
			},
		},
	});

	return render(<AppSidebar_ERP />);
};

describe("AppSidebar_ERP POS visibility", () => {
	beforeEach(() => {
		const storageMock = {
			getItem: vi.fn(() => null),
			setItem: vi.fn(),
			removeItem: vi.fn(),
			clear: vi.fn(),
		};

		Object.defineProperty(window, "localStorage", {
			value: storageMock,
			writable: true,
		});
		Object.defineProperty(globalThis, "localStorage", {
			value: storageMock,
			writable: true,
		});

		usePageMock.mockReset();
	});

	it("hides repairer POS item for staff retail-only context", () => {
		renderSidebar({ businessType: "retail", permissions: ["access-repairer-dashboard"] });
		expect(screen.queryByText("Point of Sale")).not.toBeInTheDocument();
	});

	it("shows staff retail POS item for both business type when staff permission exists", () => {
		renderSidebar({ businessType: "both", permissions: ["access-staff-job-orders"] });
		expect(screen.getByText("Retail Point of Sale")).toBeInTheDocument();
	});
});
