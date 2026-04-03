import axios from "axios";

export type RepairPosRefundRequestPayload = {
  source_transaction_id: number;
  request_type: "full" | "partial";
  requested_amount: number;
  reason_code: string;
  reason_notes?: string;
  receipt_no?: string;
};

export const repairPosHistoryApi = {
  listTransactions(repairRequestId?: number) {
    return axios.get("/api/repair-pos/transactions", {
      params: repairRequestId ? { repair_request_id: repairRequestId } : undefined,
      withCredentials: true,
    });
  },

  requestRefund(payload: RepairPosRefundRequestPayload) {
    return axios.post("/api/repair-pos/refunds", payload, { withCredentials: true });
  },
};
