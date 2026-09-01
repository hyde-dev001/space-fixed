import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import type { ReactNode } from "react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const axiosGetMock = vi.hoisted(() => vi.fn());
const axiosPostMock = vi.hoisted(() => vi.fn());
const swalFireMock = vi.hoisted(() => vi.fn());

vi.mock("axios", () => ({
  default: {
    get: (...args: unknown[]) => axiosGetMock(...args),
    post: (...args: unknown[]) => axiosPostMock(...args),
  },
}));

vi.mock("sweetalert2", () => ({
  default: {
    fire: (...args: unknown[]) => swalFireMock(...args),
  },
}));

vi.mock("@inertiajs/react", () => ({
  Head: () => null,
}));

vi.mock("../../../../layout/AppLayout_ERP", () => ({
  default: ({ children }: { children: ReactNode }) => <div>{children}</div>,
}));

import WarrantyQueue from "../WarrantyQueue";

const claim = {
  id: 1,
  claim_no: "WCLM-TEST-001",
  status: "pending_repairer",
  reason_code: "issue_returned",
  reason_details: "The issue returned after repair.",
  source_channel: "customer_portal",
  evidence_media: [],
  created_at: "2026-08-30 08:00:00",
  warranty_expires_at_snapshot: "2026-09-29 08:00:00",
  original_repair: {
    id: 7,
    request_id: "REP-TEST-001",
    customer_name: "Test Customer",
    status: "ready_for_pickup",
  },
};

describe("Repairer Warranty Queue", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    axiosGetMock.mockImplementation((url: string) => {
      if (url === "/api/repairer/warranty-claims") {
        return Promise.resolve({ data: { success: true, data: [claim] } });
      }

      if (url === "/api/repairer/warranty-claims/kpi") {
        return Promise.resolve({ data: { success: true, data: {} } });
      }

      return Promise.resolve({ data: { success: true, data: [] } });
    });
    axiosPostMock.mockResolvedValue({ data: { success: true } });
    swalFireMock.mockResolvedValue({ isConfirmed: true });
  });

  afterEach(() => {
    cleanup();
  });

  const openClaimDetails = async () => {
    render(<WarrantyQueue />);
    await waitFor(() => expect(screen.getByText("WCLM-TEST-001")).toBeInTheDocument());
    fireEvent.click(screen.getByTitle("View Details"));
  };

  it("opens a separate rejection reason dialog and does not post when cancelled", async () => {
    await openClaimDetails();

    expect(screen.queryByLabelText("Rejection reason")).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Reject" }));

    const dialog = screen.getByRole("dialog", { name: "Reject warranty claim" });
    expect(within(dialog).getByLabelText("Rejection reason")).toBeInTheDocument();
    expect(within(dialog).getByRole("button", { name: "Cancel" })).toBeInTheDocument();

    fireEvent.click(within(dialog).getByRole("button", { name: "Cancel" }));

    expect(screen.queryByRole("dialog", { name: "Reject warranty claim" })).not.toBeInTheDocument();
    expect(axiosPostMock).not.toHaveBeenCalled();
  });

  it("submits the trimmed reason from the rejection dialog through the existing endpoint", async () => {
    await openClaimDetails();
    fireEvent.click(screen.getByRole("button", { name: "Reject" }));

    const dialog = screen.getByRole("dialog", { name: "Reject warranty claim" });
    fireEvent.change(within(dialog).getByLabelText("Rejection reason"), {
      target: { value: "  Claim is outside the warranty scope.  " },
    });
    fireEvent.click(within(dialog).getByRole("button", { name: "Confirm rejection" }));

    await waitFor(() => expect(axiosPostMock).toHaveBeenCalledWith(
      "/api/repairer/warranty-claims/1/reject",
      { rejection_reason: "Claim is outside the warranty scope." },
    ));
    expect(screen.queryByRole("dialog", { name: "Reject warranty claim" })).not.toBeInTheDocument();
  });
});
