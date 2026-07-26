import React from "react";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import JobOrdersRepair from "../JobOrdersRepair";

const mocks = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  remove: vi.fn(),
  swal: vi.fn(),
  fetch: vi.fn(),
  repair: {} as Record<string, any>,
}));

vi.mock("@inertiajs/react", () => ({
  Head: () => null,
  usePage: () => ({
    props: {
      auth: { shop_owner: { registration_type: "business" } },
      repair_workload_limit: 20,
    },
  }),
}));
vi.mock("axios", () => ({
  default: {
    get: mocks.get,
    post: mocks.post,
    delete: mocks.remove,
    isAxiosError: () => false,
  },
}));
vi.mock("sweetalert2", () => ({
  default: { fire: mocks.swal },
}));
vi.mock("../../../../../layout/AppLayout_shopOwner", () => ({
  default: ({ children }: React.PropsWithChildren) => <>{children}</>,
}));

const repair = (
  intakeDeliveryMethod: string,
  canConfirmReceipt = false,
  overrides: Record<string, unknown> = {},
) => ({
  id: 77,
  request_id: "REP-77",
  customer_name: "Rina Santos",
  email: "rina@example.test",
  phone: "09171234567",
  shoe_type: "Sneakers",
  services: [{ name: "Deep Clean", price: 500 }],
  final_total: 500,
  status: "pending",
  payment_status: "completed",
  created_at: "2026-07-20T08:00:00.000Z",
  intake_delivery_method: intakeDeliveryMethod,
  return_delivery_method: "customer_pickup",
  intake_address: {
    address_line: "Canonical intake address",
    barangay: "Barangay 1",
    city: "Makati",
  },
  pickup_address: {
    address_line: "Legacy pickup address",
  },
  intake_handoff: {
    shipment_id: 401,
    shipment_status: "active",
    leg_id: 902,
    leg_status: canConfirmReceipt ? "delivered" : "awaiting_proof_approval",
    proof_status: canConfirmReceipt ? "approved" : "pending",
    can_confirm_receipt: canConfirmReceipt,
    blocked_reason: canConfirmReceipt
      ? null
      : "Delivery proof must be approved before receipt can be confirmed.",
    scheduled_delivery_date: "2026-07-27",
    delivery_window: "morning",
    events: [
      { id: 1, label: "Rider assigned", occurred_at: "2026-07-26T08:00:00.000Z" },
      { id: 2, label: "Shoes picked up", occurred_at: "2026-07-26T09:00:00.000Z" },
    ],
  },
  ...overrides,
});

beforeEach(() => {
  vi.clearAllMocks();
  Object.defineProperty(window, "localStorage", {
    configurable: true,
    value: { getItem: vi.fn(() => null), setItem: vi.fn() },
  });
  mocks.repair = repair("shop_pickup");
  mocks.swal.mockResolvedValue({ isConfirmed: true });
  mocks.fetch.mockResolvedValue({
    ok: true,
    json: async () => ({ success: true }),
  });
  vi.stubGlobal("fetch", mocks.fetch);
  mocks.post.mockResolvedValue({ data: { success: true } });
  mocks.remove.mockResolvedValue({ data: { success: true } });
  mocks.get.mockImplementation(async (url: string) => {
    if (url === "/api/shop-owner/repairs") {
      return { data: { success: true, data: [mocks.repair] } };
    }
    if (url === "/api/shop-owner/repairs/77/materials") {
      return {
        data: {
          success: true,
          data: { repair_id: 77, repair_status: "pending", usages: [], plan_items: [], materials: [] },
        },
      };
    }
    throw new Error(`Unexpected GET ${url}`);
  });
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const openDetails = async () => {
  fireEvent.click(await screen.findByRole("button", { name: /^Pending \(1\)$/ }));
  await screen.findByText("Rina Santos");
  fireEvent.click(screen.getByTitle("View details"));
  await screen.findByRole("heading", { name: "Repair Service Details" });
};

describe("ShopOwner JobOrdersRepair intake logistics", () => {
  it.each([
    ["walk_in", "Customer drop-off"],
    ["customer_delivery", "Customer-arranged courier"],
    ["shop_pickup", "Shop rider pickup"],
  ])('labels %s as "%s"', async (method, label) => {
    mocks.repair = repair(method);

    render(<JobOrdersRepair />);
    await openDetails();

    expect(screen.getAllByText(label).length).toBeGreaterThan(0);
  });

  it("shows only server-provided shop pickup logistics and blocks receipt with the server reason", async () => {
    render(<JobOrdersRepair />);
    await openDetails();

    expect(screen.getByText("Canonical intake address")).toBeInTheDocument();
    expect(screen.queryByText("Legacy pickup address")).not.toBeInTheDocument();
    expect(screen.getByText("Rider assigned")).toBeInTheDocument();
    expect(screen.getByText("Shoes picked up")).toBeInTheDocument();
    expect(screen.getByText("Delivery proof must be approved before receipt can be confirmed.")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Confirm physical receipt" })).toBeDisabled();
    expect(screen.queryByRole("button", { name: "Edit intake delivery method" })).not.toBeInTheDocument();
    expect(
      screen.queryByText(/contact (?:a )?(?:delivery service|carrier|rider)|manually (?:enter|add) (?:carrier|rider)/i),
    ).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: "Refresh delivery status" }));
    await waitFor(() => {
      expect(mocks.get.mock.calls.filter(([url]) => url === "/api/shop-owner/repairs")).toHaveLength(2);
    });
  });

  it.each([
    ["owner_approved", repair("shop_pickup", true, { status: "owner_approved" })],
    ["pending without a handoff", repair("shop_pickup", true, { intake_handoff: null })],
  ])("hides receipt actions for %s", async (_caseName, repairRecord) => {
    mocks.repair = repairRecord;

    render(<JobOrdersRepair />);
    fireEvent.click(await screen.findByRole("button", { name: /^Pending \(1\)$/ }));
    await screen.findByText("Rina Santos");

    expect(screen.queryByRole("button", { name: /physical receipt|mark as received/i })).not.toBeInTheDocument();
  });

  it("confirms an approved handoff, refetches, and refreshes the active modal", async () => {
    mocks.repair = repair("shop_pickup", true);
    mocks.fetch.mockImplementation(async () => {
      mocks.repair = {
        ...mocks.repair,
        status: "received",
        intake_handoff: {
          ...mocks.repair.intake_handoff,
          events: [{ id: 3, label: "Receipt confirmed by server" }],
        },
      };
      return {
        ok: true,
        json: async () => ({ success: true }),
      };
    });

    render(<JobOrdersRepair />);
    await openDetails();

    const confirmReceipt = screen.getByRole("button", { name: "Confirm physical receipt" });
    expect(confirmReceipt).toBeEnabled();
    fireEvent.click(confirmReceipt);

    await waitFor(() => expect(mocks.fetch).toHaveBeenCalledWith(
      "/api/shop-owner/repairs/77/mark-received",
      expect.objectContaining({ method: "POST" }),
    ));
    await waitFor(() => {
      expect(mocks.get.mock.calls.filter(([url]) => url === "/api/shop-owner/repairs")).toHaveLength(2);
    });
    expect(screen.getByRole("heading", { name: "Repair Service Details" })).toBeInTheDocument();
    expect(await screen.findByText("Receipt confirmed by server")).toBeInTheDocument();
  });

  it("does not offer manual shipping for automatic shop rider returns", async () => {
    mocks.repair = repair("shop_pickup", false, {
      status: "ready_for_pickup",
      return_delivery_method: "shop_delivery",
      intake_handoff: null,
    });

    render(<JobOrdersRepair />);
    fireEvent.click(await screen.findByRole("button", { name: /^Ready for Pickup \(1\)$/ }));
    await screen.findByText("Rina Santos");

    expect(screen.queryByRole("button", { name: "Ship this repair order" })).not.toBeInTheDocument();
  });

  it("shows customer courier tracking read-only and records the return handoff", async () => {
    mocks.repair = repair("customer_delivery", false, {
      status: "ready_for_pickup",
      return_delivery_method: "customer_pickup",
      intake_handoff: null,
      return_handoff: {
        method: "customer_pickup",
        can_release: true,
        can_confirm_receipt: false,
        action_label: "Confirm courier handoff",
        blocked_reason: null,
        external_tracking: {
          carrier: "Lalamove",
          tracking_number: "RETURN-123",
          tracking_url: "https://tracker.example/RETURN-123",
        },
        events: [],
      },
    });

    render(<JobOrdersRepair />);
    fireEvent.click(await screen.findByRole("button", { name: /^Ready for Pickup \(1\)$/ }));
    await screen.findByText("Rina Santos");

    expect(screen.queryByRole("button", { name: "Ship this repair order" })).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Confirm courier handoff" })).toBeEnabled();

    fireEvent.click(screen.getByTitle("View details"));
    await screen.findByRole("heading", { name: "Repair Service Details" });
    expect(screen.getByText("Customer courier tracking")).toBeInTheDocument();
    expect(screen.getByText("Lalamove")).toBeInTheDocument();
    expect(screen.getByText("RETURN-123")).toBeInTheDocument();
    expect(screen.queryByLabelText(/carrier|tracking number/i)).not.toBeInTheDocument();

    fireEvent.click(screen.getAllByRole("button", { name: "Confirm courier handoff" })[0]);
    await waitFor(() => expect(mocks.post).toHaveBeenCalledWith(
      "/api/shop-owner/repairs/77/activate-pickup",
    ));
  });
});
