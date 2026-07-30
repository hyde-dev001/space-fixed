import React from "react";
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const mocks = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  patch: vi.fn(),
  swal: vi.fn(),
  fetch: vi.fn(),
  repair: {} as Record<string, any>,
}));

vi.mock("@inertiajs/react", () => ({
  Head: () => null,
  Link: ({ href, children, ...props }: React.PropsWithChildren<{ href: string }>) => (
    <a href={href} {...props}>{children}</a>
  ),
}));
vi.mock("@/Pages/UserSide/Shared/Navigation", () => ({
  default: () => null,
}));
vi.mock("@/Pages/UserSide/Shared/UserModal", () => ({
  default: { fire: mocks.swal, showValidationMessage: vi.fn() },
}));
vi.mock("axios", () => ({
  default: {
    get: mocks.get,
    post: mocks.post,
    patch: mocks.patch,
  },
}));
vi.mock("@/components/address/CustomerAddressManager", () => ({
  default: ({
    onSelect,
    disabled,
    title,
  }: {
    onSelect: (address: Record<string, unknown>) => void;
    disabled?: boolean;
    title?: string;
  }) => (
    <div>
      <p>{title}</p>
      <button
        type="button"
        disabled={disabled}
        onClick={() => onSelect({
          id: 42,
          name: "Rina Santos",
          phone: "09171234567",
          address_line: "42 New Return Street",
          barangay: "Poblacion",
          city: "Makati",
          province: "Metro Manila",
          region: "NCR",
          postal_code: "1200",
          latitude: 14.56,
          longitude: 121.02,
          delivery_instructions: "Lobby",
          is_default: false,
        })}
      >
        {title === "Saved pickup address" ? "Use saved pickup address" : "Use saved return address"}
      </button>
    </div>
  ),
}));

import MyRepairs from "../myRepairs";

const repair = (overrides: Record<string, unknown> = {}) => ({
  id: 77,
  order_number: "REP-77",
  repair_type: "Deep Clean",
  services: [{ id: 1, name: "Deep Clean", price: 1500 }],
  description: "Scuffed sneakers",
  status: "ready_for_pickup",
  total_amount: 1500,
  grand_total: 1500,
  total_paid_amount: 1000,
  display_total_paid_amount: 1000,
  payment_status: "paid",
  payment_policy: "deposit_50",
  created_at: "2026-07-20T08:00:00.000Z",
  shop_id: 9,
  shop_owner_id: 9,
  shop_name: "SoleSpace Makati",
  shop_address: "9 Repair Avenue",
  intake_delivery_method: "shop_pickup",
  intake_delivery_fee: 250,
  intake_address: {
    address_id: 5,
    address_line: "5 Intake Street",
    barangay: "Bel-Air",
    city: "Makati",
    province: "Metro Manila",
    region: "NCR",
    postal_code: "1209",
    latitude: 14.55,
    longitude: 121.03,
    version: "intake-v1",
  },
  return_delivery_method: "shop_delivery",
  return_address: {
    address_id: 5,
    address_line: "5 Intake Street",
    barangay: "Bel-Air",
    city: "Makati",
    province: "Metro Manila",
    region: "NCR",
    postal_code: "1209",
    latitude: 14.55,
    longitude: 121.03,
    version: "return-v1",
  },
  same_as_intake_address: true,
  return_delivery_fee: 135,
  return_logistics_quote: {
    available: true,
    reason: null,
    distance_km: 7.25,
    coverage_radius_km: 15,
    fee: 135,
    address_version: "return-v1",
    method: "shop_delivery",
  },
  intake_logistics_locked_at: "2026-07-21T08:00:00.000Z",
  return_logistics_locked_at: null,
  return_address_confirmed_at: null,
  return_address_confirmed_version: null,
  logistics_shipments: [
    { id: 22, purpose: "repair_return" },
    { id: 11, purpose: "repair_pickup" },
  ],
  ...overrides,
});

const trackingShipment = (id: number, purpose: string, message: string) => ({
  id,
  purpose,
  status: "active",
  source_type: "repair_request",
  legs: [],
  events: [{
    id: id * 10,
    event_type: "status_updated",
    message,
    created_at: "2026-07-26T08:00:00.000Z",
  }],
});

beforeEach(() => {
  vi.clearAllMocks();
  window.history.replaceState({}, "", "/my-repairs?tab=ready_for_pickup");
  const storage = {
    getItem: vi.fn(() => null),
    setItem: vi.fn(),
    removeItem: vi.fn(),
    clear: vi.fn(),
  };
  Object.defineProperty(window, "localStorage", { configurable: true, value: storage });
  Object.defineProperty(window, "sessionStorage", { configurable: true, value: { ...storage } });

  mocks.repair = repair();
  mocks.swal.mockResolvedValue({ isConfirmed: false });
  mocks.patch.mockResolvedValue({
    data: {
      success: true,
      message: "Delivery plan updated.",
      return_delivery_method: "customer_pickup",
      return_address: { address_id: 42, version: "return-v2" },
      return_delivery_fee: 0,
      return_logistics_quote: null,
      same_as_intake_address: false,
    },
  });
  mocks.post.mockResolvedValue({
    data: {
      success: true,
      message: "Return delivery confirmed.",
      return_address_confirmed_at: "2026-07-26T09:00:00.000Z",
      return_address_confirmed_version: "return-v2",
      shipment_id: null,
    },
  });
  mocks.get.mockImplementation(async (url: string) => {
    if (url === "/api/customer/repairs") {
      return { data: { success: true, data: [mocks.repair] } };
    }
    if (url === "/api/customer/conversations/shops") {
      return { data: [] };
    }
    if (url === "/api/repair-pos/refunds/mine") {
      return { data: { data: [] } };
    }
    if (url === "/api/customer/repairs/77/warranty-claims/latest") {
      return { data: { data: null } };
    }
    if (url === "/tracking/shipments/11") {
      return { data: { props: { shipment: trackingShipment(11, "repair_pickup", "Shoes collected for intake") } } };
    }
    if (url === "/tracking/shipments/22") {
      return { data: { props: { shipment: trackingShipment(22, "repair_return", "Return rider dispatched") } } };
    }
    throw new Error(`Unexpected GET ${url}`);
  });
  mocks.fetch.mockResolvedValue({
    ok: true,
    json: async () => ({
      available: true,
      reason: null,
      distance_km: 4.5,
      coverage_radius_km: 15,
      fee: 90,
    }),
  });
  vi.stubGlobal("fetch", mocks.fetch);
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const renderReadyRepair = async () => {
  render(<MyRepairs />);
  await waitFor(() => expect(mocks.get).toHaveBeenCalledWith(
    "/api/customer/repairs",
    expect.anything(),
  ));
  const readyTabs = await screen.findAllByRole("button", { name: /Ready for Pickup/i });
  fireEvent.click(readyTabs[0]);
  await screen.findAllByText("Scuffed sneakers");
};

describe("MyRepairs loading performance", () => {
  it("shows the primary repair list without waiting for optional metadata", async () => {
    mocks.get.mockImplementation(async (url: string) => {
      if (url === "/api/customer/repairs") {
        return { data: { success: true, data: [mocks.repair] } };
      }
      if (url === "/api/customer/conversations/shops") {
        return new Promise(() => {});
      }
      throw new Error(`Unexpected GET ${url}`);
    });

    render(<MyRepairs />);

    expect((await screen.findAllByText("Scuffed sneakers")).length).toBeGreaterThan(0);
    expect(screen.queryByText("Loading your repairs...")).not.toBeInTheDocument();
  });

  it("finishes payment confirmation without waiting for optional metadata", async () => {
    window.history.replaceState({}, "", "/my-repairs?paymongo_success=1&pending_repair_id=77");
    Object.defineProperty(window, "sessionStorage", {
      configurable: true,
      value: {
        getItem: vi.fn((key: string) => key === "pendingRepairId" ? "77" : null),
        setItem: vi.fn(),
        removeItem: vi.fn(),
        clear: vi.fn(),
      },
    });
    mocks.get.mockImplementation(async (url: string) => {
      if (url === "/api/customer/repairs") {
        return { data: { success: true, data: [mocks.repair] } };
      }
      if (url === "/api/customer/conversations/shops") {
        return new Promise(() => {});
      }
      throw new Error(`Unexpected GET ${url}`);
    });
    mocks.fetch.mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ success: true, payment_verified: true }),
    });

    render(<MyRepairs />);

    await waitFor(() => expect(mocks.swal).toHaveBeenCalledWith(
      expect.objectContaining({ title: "Payment Confirmed!" }),
    ));
    expect(mocks.get).toHaveBeenCalledWith(
      "/api/customer/repairs",
      { params: undefined },
    );
  });
});

describe("MyRepairs intake payment", () => {
  it("hides payment until the repairer activates it", async () => {
    mocks.repair = repair({
      status: "repairer_accepted",
      payment_status: "pending",
      payment_enabled: false,
      conversation_id: 15,
      logistics_shipments: [],
      intake_logistics_locked_at: null,
    });

    render(<MyRepairs />);
    const pendingTabs = await screen.findAllByRole("button", { name: /Pending/i });
    fireEvent.click(pendingTabs[0]);

    expect(screen.queryByRole("button", { name: "PAY NOW" })).not.toBeInTheDocument();
  });

  it("lets an accepted shop-pickup customer pay online", async () => {
    mocks.repair = repair({
      status: "repairer_accepted",
      payment_status: "pending",
      payment_enabled: true,
      conversation_id: 15,
      logistics_shipments: [],
      intake_logistics_locked_at: null,
    });

    render(<MyRepairs />);
    const pendingTabs = await screen.findAllByRole("button", { name: /Pending/i });
    fireEvent.click(pendingTabs[0]);

    expect(await screen.findByRole("button", { name: "PAY NOW" })).toBeEnabled();
  });
});

describe("MyRepairs return logistics", () => {
  it("shows the server return plan, exact amount, and purpose-specific tracking events", async () => {
    await renderReadyRepair();

    expect(screen.getByRole("heading", { name: "Return delivery plan" })).toBeInTheDocument();
    expect(screen.getByRole("radio", { name: /Customer pickup at shop/i })).toBeEnabled();
    expect(screen.getByRole("radio", { name: /Customer-arranged courier/i })).toBeEnabled();
    expect(screen.getByRole("radio", { name: /Shop rider delivery/i })).toBeEnabled();
    expect(screen.getByRole("checkbox", { name: "Same as intake address" })).toBeChecked();
    expect(screen.getByText("Within coverage")).toBeInTheDocument();
    expect(screen.getByText("Distance: 7.25 km")).toBeInTheDocument();
    expect(screen.getByText("Accepted return fee: ₱135")).toBeInTheDocument();
    expect(screen.getByText("Final amount: ₱885")).toBeInTheDocument();

    const intakeTimeline = await screen.findByRole("region", { name: "Intake pickup tracking" });
    const returnTimeline = screen.getByRole("region", { name: "Return delivery tracking" });
    expect(within(intakeTimeline).getByText("Shoes collected for intake")).toBeInTheDocument();
    expect(within(intakeTimeline).queryByText("Return rider dispatched")).not.toBeInTheDocument();
    expect(within(returnTimeline).getByText("Return rider dispatched")).toBeInTheDocument();
    expect(within(returnTimeline).queryByText("Shoes collected for intake")).not.toBeInTheDocument();
    expect(screen.queryByText(/Shipping Business|Rider Name|Tracking Number/i)).not.toBeInTheDocument();
    const trackingRequest = mocks.get.mock.calls.find(([url]) => url === "/tracking/shipments/11");
    expect(trackingRequest?.[1]?.headers).toEqual({ Accept: "application/json" });
  });

  it("updates the plan with a saved address before confirming it", async () => {
    mocks.patch.mockImplementationOnce(async () => {
      const data = {
        success: true,
        message: "Delivery plan updated.",
        return_delivery_method: "customer_pickup",
        return_address: { address_id: 42, version: "return-v2" },
        return_delivery_fee: 0,
        return_logistics_quote: null,
        same_as_intake_address: false,
      };
      mocks.repair = { ...mocks.repair, ...data };
      return { data };
    });

    await renderReadyRepair();

    fireEvent.click(screen.getByRole("checkbox", { name: "Same as intake address" }));
    fireEvent.click(await screen.findByRole("button", { name: "Use saved return address" }));
    fireEvent.click(screen.getByRole("radio", { name: /Customer-arranged courier/i }));
    expect(screen.queryByText("Accepted return fee: ₱135")).not.toBeInTheDocument();
    expect(screen.getByText("Estimated return fee: ₱0")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Save & review delivery plan" }));

    await waitFor(() => expect(mocks.patch).toHaveBeenCalledWith(
      "/api/customer/repairs/77/delivery-method",
      {
        return_delivery_method: "customer_pickup",
        return_address_id: 42,
        same_as_intake_address: false,
      },
    ));
    expect(mocks.post).not.toHaveBeenCalled();
    expect(await screen.findByText("Delivery plan updated. Review the new fee, then confirm.")).toBeInTheDocument();
    expect(screen.getByText("Accepted return fee: ₱0")).toBeInTheDocument();

    const confirmButton = screen.getByRole("button", { name: "Confirm address & delivery" });
    expect(confirmButton).toBeEnabled();
    fireEvent.click(confirmButton);
    await waitFor(() => expect(mocks.post).toHaveBeenCalledWith(
      "/api/customer/repairs/77/confirm-return-address",
    ));
    expect(await screen.findByText("Return delivery confirmed.")).toBeInTheDocument();
  });

  it("does not infer a lock from payment status and displays server errors inline", async () => {
    mocks.repair = repair({ payment_status: "completed", return_logistics_locked_at: null });
    mocks.patch.mockRejectedValue({
      response: { data: { message: "The return delivery plan changed. Refresh and try again." } },
    });

    await renderReadyRepair();

    expect(screen.getByRole("radio", { name: /Shop rider delivery/i })).toBeEnabled();
    fireEvent.click(screen.getByRole("radio", { name: /Customer-arranged courier/i }));
    fireEvent.click(screen.getByRole("button", { name: "Save & review delivery plan" }));

    expect(await screen.findByRole("alert")).toHaveTextContent(
      "The return delivery plan changed. Refresh and try again.",
    );
  });

  it("lets the customer save third-party return tracking until the shop records handoff", async () => {
    mocks.repair = repair({
      return_delivery_method: "customer_pickup",
      return_delivery_fee: 0,
      return_logistics_quote: null,
      same_as_intake_address: false,
    });
    mocks.post.mockResolvedValueOnce({
      data: { success: true, message: "Tracking details saved." },
    });

    await renderReadyRepair();

    expect(screen.getByRole("heading", { name: "Return courier tracking" })).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText("Return carrier"), { target: { value: "Lalamove" } });
    fireEvent.change(screen.getByLabelText("Return tracking number"), { target: { value: "RETURN-123" } });
    fireEvent.change(screen.getByLabelText("Return tracking link"), {
      target: { value: "https://tracker.example/RETURN-123" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Save return tracking" }));

    await waitFor(() => expect(mocks.post).toHaveBeenCalledWith(
      "/api/customer/repairs/77/external-tracking",
      {
        leg: "return",
        carrier: "Lalamove",
        tracking_number: "RETURN-123",
        tracking_url: "https://tracker.example/RETURN-123",
      },
    ));
    expect(await screen.findByText("Tracking details saved.")).toBeInTheDocument();
  });

  it("keeps an ordinary paid locked return plan read-only before handoff", async () => {
    mocks.repair = repair({
      return_delivery_method: "customer_pickup",
      return_logistics_locked_at: "2026-07-26T10:00:00.000Z",
      pickup_enabled: false,
    });

    await renderReadyRepair();

    const tracking = screen.getByRole("region", { name: "Return courier tracking" });
    expect(within(tracking).getByText("Locked after handoff")).toBeInTheDocument();
    expect(within(tracking).queryByLabelText("Return carrier")).not.toBeInTheDocument();
    expect(within(tracking).queryByRole("button", { name: "Save return tracking" })).not.toBeInTheDocument();
  });

  it("shows locked customer tracking read-only and uses an explicit receipt confirmation label", async () => {
    mocks.repair = repair({
      status: "ready_for_pickup",
      return_delivery_method: "customer_pickup",
      return_logistics_locked_at: "2026-07-26T10:00:00.000Z",
      pickup_enabled: true,
      return_address: {
        address_id: 5,
        version: "return-v1",
        external_tracking: {
          carrier: "Lalamove",
          tracking_number: "RETURN-123",
          tracking_url: "https://tracker.example/RETURN-123",
        },
      },
    });

    await renderReadyRepair();

    const tracking = screen.getByRole("region", { name: "Return courier tracking" });
    expect(within(tracking).getByText("Lalamove")).toBeInTheDocument();
    expect(within(tracking).getByText("RETURN-123")).toBeInTheDocument();
    expect(within(tracking).queryByRole("button", { name: "Save return tracking" })).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Confirm I received my repaired shoes" })).toBeEnabled();
  });

  it("locks only the return plan controls when the server provides the matching lock", async () => {
    mocks.repair = repair({
      payment_status: "pending",
      same_as_intake_address: false,
      return_logistics_locked_at: "2026-07-26T10:00:00.000Z",
    });

    await renderReadyRepair();

    expect(screen.getByRole("checkbox", { name: "Same as intake address" })).toBeDisabled();
    expect(screen.getByRole("radio", { name: /Customer pickup at shop/i })).toBeDisabled();
    expect(screen.getByRole("radio", { name: /Customer-arranged courier/i })).toBeDisabled();
    expect(screen.getByRole("radio", { name: /Shop rider delivery/i })).toBeDisabled();
    expect(screen.getByRole("button", { name: "Use saved return address" })).toBeDisabled();
  });

  it("lets the customer schedule re-delivery from the returned-to-shop state", async () => {
    mocks.repair = repair({
      payment_status: "completed",
      return_recovery: {
        code: "returned_to_shop_awaiting_arrangement",
        label: "Returned to shop—awaiting customer arrangement",
        state: "awaiting_arrangement",
      },
      redelivery_payment_due: false,
    });

    await renderReadyRepair();

    expect(screen.getByRole("heading", {
      name: "Returned to shop—awaiting customer arrangement",
    })).toBeInTheDocument();
    expect(screen.getByText(/choose re-delivery or free shop pickup/i)).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText("Re-delivery date"), {
      target: { value: "2026-08-02" },
    });
    fireEvent.change(screen.getByLabelText("Delivery window"), {
      target: { value: "afternoon" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Schedule re-delivery" }));

    await waitFor(() => expect(mocks.post).toHaveBeenCalledWith(
      "/api/customer/repairs/77/return-recovery",
      {
        action: "schedule_redelivery",
        scheduled_delivery_date: "2026-08-02",
        delivery_window: "afternoon",
      },
    ));
    expect(screen.queryByRole("heading", { name: "Return delivery plan" })).not.toBeInTheDocument();
    expect(screen.queryByRole("region", { name: "Return delivery tracking" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Pay new delivery fee" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Awaiting handoff" })).not.toBeInTheDocument();
    expect(mocks.get).not.toHaveBeenCalledWith("/tracking/shipments/22", expect.anything());
  });

  it("lets the customer choose free shop pickup from the returned-to-shop state", async () => {
    mocks.repair = repair({
      payment_status: "completed",
      return_recovery: {
        code: "returned_to_shop_awaiting_arrangement",
        label: "Returned to shop—awaiting customer arrangement",
        state: "awaiting_arrangement",
      },
      redelivery_payment_due: false,
    });

    await renderReadyRepair();

    fireEvent.click(screen.getByRole("button", { name: "Pick up at shop" }));

    await waitFor(() => expect(mocks.post).toHaveBeenCalledWith(
      "/api/customer/repairs/77/return-recovery",
      { action: "shop_pickup" },
    ));
  });

  it("shows only the new delivery fee payment after redelivery is scheduled", async () => {
    mocks.repair = repair({
      payment_status: "completed",
      payment_enabled: true,
      return_recovery: {
        code: "returned_to_shop_awaiting_arrangement",
        label: "Returned to shop—awaiting customer arrangement",
        state: "awaiting_payment",
      },
      redelivery_payment_due: true,
    });

    await renderReadyRepair();

    const recovery = screen.getByRole("region", { name: "Repair return recovery" });
    expect(within(recovery).getByText(/confirm your return address, then pay the new delivery fee/i)).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: "Return delivery plan" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Pay new delivery fee" })).toBeEnabled();
    expect(screen.queryByRole("region", { name: "Return delivery tracking" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Awaiting handoff" })).not.toBeInTheDocument();
  });

  it("shows free shop pickup recovery without delivery payment", async () => {
    mocks.repair = repair({
      payment_status: "completed",
      return_delivery_method: "walk_in",
      return_delivery_fee: 0,
      return_logistics_quote: null,
      return_recovery: {
        code: "returned_to_shop_awaiting_arrangement",
        label: "Returned to shop—awaiting customer arrangement",
        state: "shop_pickup",
      },
      redelivery_payment_due: false,
    });

    await renderReadyRepair();

    expect(screen.getByRole("heading", { name: "Ready for pickup at shop" })).toBeInTheDocument();
    const recovery = screen.getByRole("region", { name: "Repair return recovery" });
    expect(within(recovery).getByText("SoleSpace Makati")).toBeInTheDocument();
    expect(within(recovery).getByText("9 Repair Avenue")).toBeInTheDocument();
    expect(screen.queryByRole("heading", { name: "Return delivery plan" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Pay new delivery fee" })).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Awaiting handoff" })).toBeDisabled();
  });
});

describe("MyRepairs warranty logistics", () => {
  describe.each([
    {
      marker: "is_warranty_job",
      warranty: { is_warranty_job: true, billing_mode: "warranty" },
    },
    {
      marker: "warranty_no_charge billing mode",
      warranty: { is_warranty_job: false, billing_mode: "warranty_no_charge" },
    },
  ])("forged payment payload with $marker", ({ warranty }) => {
    it.each(["repairer_accepted", "pending", "ready_for_pickup"])(
      "hides payment status and actions while %s",
      async (status) => {
        mocks.repair = repair({
          ...warranty,
          status,
          payment_status: "pending",
          payment_enabled: true,
          conversation_id: 15,
          logistics_shipments: [],
        });

        render(<MyRepairs />);
        const tabName = status === "ready_for_pickup" ? /Ready for Pickup/i : /Pending/i;
        const tabs = await screen.findAllByRole("button", { name: tabName });
        fireEvent.click(tabs[0]);
        await screen.findAllByText("Scuffed sneakers");

        expect(screen.queryAllByText("PAY NOW")).toHaveLength(0);
        expect(screen.queryByRole("button", { name: "PAY NOW" })).not.toBeInTheDocument();
      },
    );

    it("explains that warranty service and shop-owned shipping are covered", async () => {
      mocks.repair = repair({
        ...warranty,
        status: "repairer_accepted",
        payment_status: "pending",
        payment_enabled: true,
        conversation_id: 15,
        logistics_shipments: [],
      });

      render(<MyRepairs />);
      const pendingTabs = await screen.findAllByRole("button", { name: /Pending/i });
      fireEvent.click(pendingTabs[0]);

      expect(await screen.findByText(
        "Warranty service and shop-owned shipping are covered by the shop.",
      )).toBeInTheDocument();
    });

    it("keeps sponsored customer-delivery tracking editable until staff receipt", async () => {
      const sponsoredIntake = {
        ...warranty,
        status: "repairer_accepted",
        payment_status: "completed",
        payment_enabled: false,
        intake_delivery_method: "customer_delivery",
        intake_logistics_locked_at: "2026-07-26T10:00:00.000Z",
        received_at: null,
        logistics_shipments: [],
      };
      mocks.repair = repair(sponsoredIntake);
      mocks.post.mockResolvedValueOnce({
        data: { success: true, message: "Tracking details saved." },
      });

      render(<MyRepairs />);
      const pendingTabs = await screen.findAllByRole("button", { name: /Pending/i });
      fireEvent.click(pendingTabs[0]);

      const editableTracking = await screen.findByRole("region", { name: "Intake courier tracking" });
      expect(within(editableTracking).queryByText("Locked after handoff")).not.toBeInTheDocument();
      fireEvent.change(within(editableTracking).getByLabelText("Intake carrier"), {
        target: { value: "J&T" },
      });
      fireEvent.change(within(editableTracking).getByLabelText("Intake tracking number"), {
        target: { value: "WARRANTY-INTAKE-123" },
      });
      fireEvent.click(within(editableTracking).getByRole("button", { name: "Save intake tracking" }));

      await waitFor(() => expect(mocks.post).toHaveBeenCalledWith(
        "/api/customer/repairs/77/external-tracking",
        {
          leg: "intake",
          carrier: "J&T",
          tracking_number: "WARRANTY-INTAKE-123",
          tracking_url: null,
        },
      ));

      cleanup();
      mocks.repair = repair({
        ...sponsoredIntake,
        status: "received",
        received_at: "2026-07-26T11:00:00.000Z",
        intake_address: {
          external_tracking: {
            carrier: "J&T",
            tracking_number: "WARRANTY-INTAKE-123",
            tracking_url: null,
          },
        },
      });

      render(<MyRepairs />);
      const receivedTabs = await screen.findAllByRole("button", { name: /Received/i });
      fireEvent.click(receivedTabs[0]);

      const lockedTracking = await screen.findByRole("region", { name: "Intake courier tracking" });
      expect(within(lockedTracking).getByText("Locked after handoff")).toBeInTheDocument();
      expect(within(lockedTracking).getByText("WARRANTY-INTAKE-123")).toBeInTheDocument();
      expect(within(lockedTracking).queryByLabelText("Intake carrier")).not.toBeInTheDocument();
      expect(within(lockedTracking).queryByRole("button", { name: "Save intake tracking" })).not.toBeInTheDocument();
    });
  });

  it("keeps sponsored customer-pickup tracking editable until staff handoff", async () => {
    const sponsoredPickup = {
      is_warranty_job: true,
      billing_mode: "warranty_no_charge",
      payment_status: "completed",
      payment_enabled: false,
      return_delivery_method: "customer_pickup",
      return_logistics_locked_at: "2026-07-26T10:00:00.000Z",
    };
    mocks.repair = repair({
      ...sponsoredPickup,
      pickup_enabled: false,
    });
    mocks.post.mockResolvedValueOnce({
      data: { success: true, message: "Tracking details saved." },
    });

    await renderReadyRepair();

    const editableTracking = screen.getByRole("region", { name: "Return courier tracking" });
    expect(within(editableTracking).queryByText("Locked after handoff")).not.toBeInTheDocument();
    fireEvent.change(within(editableTracking).getByLabelText("Return carrier"), {
      target: { value: "Lalamove" },
    });
    fireEvent.change(within(editableTracking).getByLabelText("Return tracking number"), {
      target: { value: "WARRANTY-RETURN-123" },
    });
    fireEvent.click(within(editableTracking).getByRole("button", { name: "Save return tracking" }));

    await waitFor(() => expect(mocks.post).toHaveBeenCalledWith(
      "/api/customer/repairs/77/external-tracking",
      {
        leg: "return",
        carrier: "Lalamove",
        tracking_number: "WARRANTY-RETURN-123",
        tracking_url: null,
      },
    ));

    cleanup();
    mocks.repair = repair({
      ...sponsoredPickup,
      pickup_enabled: true,
      return_address: {
        external_tracking: {
          carrier: "Lalamove",
          tracking_number: "WARRANTY-RETURN-123",
          tracking_url: null,
        },
      },
    });

    await renderReadyRepair();

    const lockedTracking = screen.getByRole("region", { name: "Return courier tracking" });
    expect(within(lockedTracking).getByText("Locked after handoff")).toBeInTheDocument();
    expect(within(lockedTracking).getByText("WARRANTY-RETURN-123")).toBeInTheDocument();
    expect(within(lockedTracking).queryByLabelText("Return carrier")).not.toBeInTheDocument();
    expect(within(lockedTracking).queryByRole("button", { name: "Save return tracking" })).not.toBeInTheDocument();
  });

  it("keeps customer-arranged and walk-in intake available outside shop coverage", async () => {
    mocks.repair = repair({
      status: "repairer_accepted",
      is_warranty_job: true,
      billing_mode: "warranty_no_charge",
      payment_status: "completed",
      payment_enabled: false,
      intake_delivery_method: "customer_delivery",
      intake_logistics_locked_at: null,
    });
    mocks.fetch.mockResolvedValue({
      ok: true,
      json: async () => ({
        available: false,
        reason: "Outside shop rider coverage.",
        distance_km: 20,
        coverage_radius_km: 15,
        fee: 0,
      }),
    });

    render(<MyRepairs />);
    const pendingTabs = await screen.findAllByRole("button", { name: /Pending/i });
    fireEvent.click(pendingTabs[0]);

    expect(await screen.findByText("Outside shop rider coverage.")).toBeInTheDocument();
    expect(screen.getByRole("radio", { name: /Shop rider pickup/i })).toBeDisabled();

    const walkIn = screen.getByRole("radio", { name: /Walk-in delivery to shop/i });
    expect(walkIn).toBeEnabled();
    fireEvent.click(walkIn);
    fireEvent.click(screen.getByRole("button", { name: "Save intake plan" }));
    await waitFor(() => expect(mocks.patch).toHaveBeenCalledWith(
      "/api/customer/repairs/77/delivery-method",
      { intake_delivery_method: "walk_in" },
    ));

    mocks.patch.mockClear();
    const customerDelivery = screen.getByRole("radio", { name: /Customer-arranged delivery/i });
    await waitFor(() => expect(customerDelivery).toBeEnabled());
    fireEvent.click(customerDelivery);
    fireEvent.click(screen.getByRole("button", { name: "Use saved pickup address" }));
    fireEvent.click(screen.getByRole("button", { name: "Save intake plan" }));
    await waitFor(() => expect(mocks.patch).toHaveBeenCalledWith(
      "/api/customer/repairs/77/delivery-method",
      {
        intake_delivery_method: "customer_delivery",
        intake_address_id: 42,
      },
    ));
  });

  it("lets the customer rebook a cancelled sponsored intake pickup", async () => {
    mocks.repair = repair({
      status: "repairer_accepted",
      is_warranty_job: true,
      billing_mode: "warranty_no_charge",
      payment_status: "completed",
      payment_enabled: false,
      intake_logistics_locked_at: null,
      logistics_shipments: [{ id: 11, purpose: "repair_pickup", status: "cancelled" }],
    });
    mocks.patch.mockResolvedValueOnce({
      data: {
        success: true,
        message: "Delivery plan updated.",
        intake_delivery_method: "shop_pickup",
        intake_address: { address_id: 42, version: "intake-v2" },
        intake_delivery_fee: 90,
        intake_logistics_quote: { available: true, fee: 90, method: "shop_pickup" },
      },
    });

    render(<MyRepairs />);
    const pendingTabs = await screen.findAllByRole("button", { name: /Pending/i });
    fireEvent.click(pendingTabs[0]);

    expect(await screen.findByRole("heading", { name: "Rebook sponsored pickup" })).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Use saved pickup address" }));
    await waitFor(() => expect(mocks.fetch).toHaveBeenCalledWith(
      "/api/repair/shops/9/delivery-quote?address_id=42",
      expect.objectContaining({ credentials: "include" }),
    ));
    const rebookButton = await screen.findByRole("button", { name: "Save intake plan" });
    await waitFor(() => expect(rebookButton).toBeEnabled());
    fireEvent.click(rebookButton);

    await waitFor(() => expect(mocks.patch).toHaveBeenCalledWith(
      "/api/customer/repairs/77/delivery-method",
      {
        intake_delivery_method: "shop_pickup",
        intake_address_id: 42,
      },
    ));
    expect(await screen.findByRole("status")).toHaveTextContent("Delivery plan updated.");
  });

  it("reuses the pinned repair address and exposes shop-owned and third-party choices", async () => {
    mocks.repair = repair({
      status: "picked_up",
      picked_up_at: "2026-07-26T10:00:00.000Z",
      return_logistics_locked_at: "2026-07-26T09:00:00.000Z",
    });

    render(<MyRepairs />);
    const completedTabs = await screen.findAllByRole("button", { name: /Completed/i });
    fireEvent.click(completedTabs[0]);
    fireEvent.click(await screen.findByRole("button", { name: "WARRANTY CLAIM" }));

    const dialog = screen.getByText("File Warranty Claim").closest("div.fixed");
    expect(dialog).not.toBeNull();
    const warrantyModal = within(dialog as HTMLElement);
    expect(warrantyModal.getByRole("radio", { name: /Shop rider pickup/i })).toBeEnabled();
    expect(warrantyModal.getByRole("radio", { name: /Shop rider delivery/i })).toBeEnabled();
    expect(warrantyModal.getAllByRole("radio", { name: /Third-party courier/i })).toHaveLength(2);
    expect(warrantyModal.getByText(/Pinned intake address: 5 Intake Street/)).toBeInTheDocument();
    expect(warrantyModal.getByText(/Pinned return address: 5 Intake Street/)).toBeInTheDocument();
    expect(warrantyModal.queryByPlaceholderText("House no., street, building")).not.toBeInTheDocument();
  });
});
