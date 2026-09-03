import React from "react";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import type { ReactNode } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

const mocks = vi.hoisted(() => ({
  props: {} as Record<string, unknown>,
  routerGet: vi.fn(),
  routerPut: vi.fn(),
  axiosGet: vi.fn(),
  axiosPost: vi.fn(),
  axiosPut: vi.fn(),
  axiosPatch: vi.fn(),
  axiosDelete: vi.fn(),
}));

vi.mock("@inertiajs/react", () => ({
  Head: () => null,
  router: {
    get: mocks.routerGet,
    put: mocks.routerPut,
  },
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
  Icon: {
    Default: {
      prototype: {},
      mergeOptions: vi.fn(),
    },
  },
  map: () => {
    const map = {
      setView: vi.fn(),
      invalidateSize: vi.fn(),
      on: vi.fn(),
    };
    map.setView.mockReturnValue(map);
    return map;
  },
  tileLayer: () => ({ addTo: vi.fn() }),
  marker: () => {
    const marker = {
      addTo: vi.fn(),
      on: vi.fn(),
      getLatLng: () => ({ lat: 14.5995, lng: 120.9842 }),
      setLatLng: vi.fn(),
    };
    marker.addTo.mockReturnValue(marker);
    return marker;
  },
  circle: () => {
    const circle = {
      addTo: vi.fn(),
      setLatLng: vi.fn(),
      setRadius: vi.fn(),
    };
    circle.addTo.mockReturnValue(circle);
    return circle;
  },
}));

import ShopSetting from "../shopSetting";

const sectionLabels = {
  profile: "Profile",
  "modules-team": "Modules & Team",
  "payments-approvals": "Payments & Approvals",
  operations: "Operations",
  "policies-compliance": "Policies & Compliance",
  subscription: "Subscription",
} as const;

const sectionKeys = Object.keys(sectionLabels) as Array<keyof typeof sectionLabels>;

const baseShopSettings = {
  registration_type: "company",
  business_type: "both",
  can_manage_staff: true,
  max_locations: null,
  business_name: "Test Shop",
  approval_pages: {
    refund_approval: { enabled: false },
    price_approval: { enabled: false },
    payslip_approval: { enabled: false },
    salary_adjustment_approval: { enabled: false },
    purchase_request_approval: { enabled: false },
    expense_approval: { enabled: false },
    repair_reject_approval: { enabled: false },
  },
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
};

function renderSettings(initialSection: unknown = "profile") {
  mocks.props = {
    shop_settings: baseShopSettings,
    initialSection,
  };

  return render(<ShopSetting />);
}

describe("canonical settings sections", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mocks.axiosGet.mockResolvedValue({ data: { data: { default_sections: {}, active: null, draft: null } } });
    mocks.axiosPost.mockResolvedValue({ data: {} });
    mocks.axiosPut.mockResolvedValue({ data: {} });
    mocks.axiosPatch.mockResolvedValue({ data: {} });
    mocks.axiosDelete.mockResolvedValue({ data: {} });
    Object.defineProperty(window, "scrollTo", { configurable: true, value: vi.fn() });
    HTMLElement.prototype.scrollIntoView = vi.fn();
  });

  it("renders one accessible link for each bounded settings section", async () => {
    renderSettings("operations");

    const navigation = screen.getByRole("navigation", { name: "Settings sections" });
    expect(navigation).toBeInTheDocument();

    for (const sectionKey of sectionKeys) {
      const link = screen.getByRole("link", { name: sectionLabels[sectionKey] });
      expect(link).toHaveAttribute("href", `#settings-section-${sectionKey}`);
    }

    await waitFor(() => expect(screen.getByRole("link", { name: /operations/i })).toHaveAttribute("aria-current", "page"));
    expect(document.activeElement).toBe(document.getElementById("settings-section-operations"));
  });

  it("moves focus and active state when a user selects another section", async () => {
    renderSettings("profile");

    const operationsLink = screen.getByRole("link", { name: /operations/i });
    fireEvent.click(operationsLink);

    await waitFor(() => expect(document.activeElement).toBe(document.getElementById("settings-section-operations")));
    expect(operationsLink).toHaveAttribute("aria-current", "page");
    expect(screen.getByRole("link", { name: /^Profile$/i })).not.toHaveAttribute("aria-current", "page");
  });

  it("falls back to Profile for an invalid server value without exposing a dynamic destination", async () => {
    renderSettings("not-a-section");

    await waitFor(() => expect(screen.getByRole("link", { name: /^Profile$/i })).toHaveAttribute("aria-current", "page"));
    expect(screen.queryByRole("link", { name: /not-a-section/i })).not.toBeInTheDocument();
    expect(document.getElementById("settings-section-not-a-section")).toBeNull();
  });

  it("exposes a desktop settings shell without replacing the base/tablet layout contract", () => {
    renderSettings("profile");

    const shell = screen.getByTestId("settings-desktop-shell");
    const navigation = screen.getByRole("navigation", { name: "Settings sections" });
    const content = screen.getByTestId("settings-content");

    expect(shell).toHaveClass("contents", "xl:grid", "xl:grid-cols-[220px_minmax(0,1fr)]", "xl:gap-10");
    expect(navigation).toHaveClass("mb-6", "rounded-2xl", "border", "bg-white", "p-2", "shadow-sm");
    expect(navigation).toHaveClass("xl:sticky", "xl:top-6", "xl:mb-0", "xl:shadow-none");
    expect(content).toHaveClass("grid", "grid-cols-1", "gap-6", "lg:grid-cols-12");
    expect(content).toHaveClass("xl:flex", "xl:min-w-0", "xl:flex-col", "xl:gap-6");

    expect(document.getElementById("settings-section-profile")).toHaveClass("xl:order-1");
    expect(document.getElementById("settings-section-modules-team")).toHaveClass("xl:order-2");
    expect(document.getElementById("settings-section-policies-compliance")).toHaveClass("xl:order-10");
    expect(document.getElementById("settings-section-operations")).toHaveClass("xl:order-8");
    expect(document.getElementById("settings-section-payments-approvals")).toHaveClass("xl:order-4");
  });
});
