import React from "react";
import { render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

const mocks = vi.hoisted(() => ({
  props: {} as Record<string, unknown>,
  routerPut: vi.fn(),
  axiosGet: vi.fn(),
  axiosPost: vi.fn(),
  axiosPut: vi.fn(),
  axiosPatch: vi.fn(),
  axiosDelete: vi.fn(),
}));

vi.mock("@inertiajs/react", () => ({
  Head: () => null,
  router: { get: vi.fn(), put: mocks.routerPut },
  usePage: () => ({ props: mocks.props }),
}));

vi.mock("axios", () => ({
  default: {
    get: mocks.axiosGet,
    post: mocks.axiosPost,
    put: mocks.axiosPut,
    patch: mocks.axiosPatch,
    delete: mocks.axiosDelete,
  },
}));

vi.mock("../components/BusinessScalingSettings", () => ({
  default: () => <div>Business scaling content</div>,
}));

vi.mock("../components/BusinessDocumentCompliance", () => ({
  default: () => <div>Document compliance content</div>,
}));

vi.mock("../../../UserSide/Shared/UserModal", () => ({
  default: () => null,
}));

vi.mock("leaflet", () => ({
  Icon: { Default: { prototype: {}, mergeOptions: vi.fn() } },
  map: () => ({ setView: vi.fn().mockReturnThis(), invalidateSize: vi.fn(), on: vi.fn() }),
  tileLayer: () => ({ addTo: vi.fn() }),
  marker: () => ({
    addTo: vi.fn().mockReturnThis(),
    on: vi.fn(),
    getLatLng: () => ({ lat: 14.5995, lng: 120.9842 }),
    setLatLng: vi.fn(),
  }),
  circle: () => ({
    addTo: vi.fn().mockReturnThis(),
    setLatLng: vi.fn(),
    setRadius: vi.fn(),
  }),
}));

import ShopSetting from "../shopSetting";

const approvalPages = {
  refund_approval: { enabled: true },
  price_approval: { enabled: true },
  payslip_approval: { enabled: true },
  salary_adjustment_approval: { enabled: true },
  purchase_request_approval: { enabled: true },
  expense_approval: { enabled: true },
  repair_reject_approval: { enabled: true },
};

beforeEach(() => {
  vi.clearAllMocks();
  mocks.axiosGet.mockResolvedValue({ data: { data: { default_sections: {}, active: null, draft: null } } });
  mocks.axiosPost.mockResolvedValue({ data: {} });
  mocks.axiosPut.mockResolvedValue({ data: {} });
  mocks.axiosPatch.mockResolvedValue({ data: {} });
  mocks.axiosDelete.mockResolvedValue({ data: {} });
  Object.defineProperty(window, "scrollTo", { configurable: true, value: vi.fn() });
  HTMLElement.prototype.scrollIntoView = vi.fn();
  mocks.props = {
    shop_settings: {
      registration_type: "company",
      business_type: "both",
      can_manage_staff: true,
      max_locations: null,
      business_name: "Test Shop",
      approval_pages: approvalPages,
      business_scaling: {},
      pay_cycle: "monthly",
      pay_day_first: 15,
      pay_day_second: 30,
      required_documents: [],
      document_compliance: [],
      repair_payment_policy: "deposit_50",
      repair_workload_limit: 20,
      order_refund_deadline_days: 7,
      two_factor_email_enabled: false,
      has_paymongo_key: false,
      attendance_geofence_enabled: false,
      shop_latitude: null,
      shop_longitude: null,
      shop_address: null,
      shop_geofence_radius: 100,
      premium: {
        eligible: true,
        status: null,
        has_active: false,
        auto_renew: false,
        auto_renew_status: null,
        plan_name: null,
        plan_code: null,
        showroom_slot_limit: null,
        starts_at: null,
        ends_at: null,
      },
    },
    initialSection: "payments-approvals",
  };
});

describe("approval workflow settings", () => {
  it("renders seven binary controls without amount inputs", async () => {
    render(<ShopSetting />);

    await waitFor(() => {
      for (const label of [
        "Refund Approval",
        "Price Approvals",
        "Payslip Approval",
        "Salary Adjustment Approval",
        "Purchase Request Approval",
        "Expense Approvals",
        "Repair Reject Approval",
      ]) {
        expect(screen.getByRole("button", { name: new RegExp(label, "i") })).toBeInTheDocument();
        expect(screen.getByText(label)).toBeInTheDocument();
      }

      expect(screen.queryByRole("spinbutton", { name: /limit in PHP/i })).not.toBeInTheDocument();
      expect(screen.getByText(
        "Changes apply to newly submitted requests. In-progress approvals keep their current workflow.",
      )).toBeInTheDocument();
    });
  });
});
