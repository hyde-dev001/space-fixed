import axios from "axios";

export type RepairPosRefundRequestPayload = {
  source_transaction_id: number;
  request_type: "full" | "partial";
  requested_amount: number;
  reason_code: string;
  reason_notes?: string;
  receipt_no?: string;
};

export type ManualRejectedNoAccountRefundPayload = {
  source_transaction_id: number;
  receipt_no: string;
};

export type RepairPosWarrantyClaimPayload = {
  repair_request_id: number;
  receipt_no: string;
  walk_in_phone: string;
  reason_code: string;
  reason_details?: string;
  preferred_return_method: "walk_in" | "customer_delivery";
  images: File[];
};

export const repairPosHistoryApi = {
  listTransactions(repairRequestId?: number, perPage = 200) {
    const params: Record<string, number> = {
      per_page: perPage,
    };

    if (repairRequestId && repairRequestId > 0) {
      params.repair_request_id = repairRequestId;
    }

    return axios.get("/api/repair-pos/transactions", {
      params,
      withCredentials: true,
    });
  },

  requestRefund(payload: RepairPosRefundRequestPayload) {
    return axios.post("/api/repair-pos/refunds", payload, { withCredentials: true });
  },

  manualRefundRejectedNoAccount(payload: ManualRejectedNoAccountRefundPayload) {
    return axios.post(
      "/api/repair-pos/refunds/manual-rejected-no-account",
      payload,
      { withCredentials: true },
    );
  },

  requestWarrantyClaim(payload: RepairPosWarrantyClaimPayload) {
    const formData = new FormData();
    formData.append('repair_request_id', String(payload.repair_request_id));
    formData.append('receipt_no', payload.receipt_no);
    formData.append('walk_in_phone', payload.walk_in_phone);
    formData.append('reason_code', payload.reason_code);
    formData.append('reason_details', payload.reason_details || 'Filed from POS warranty flow.');
    formData.append('same_issue_confirmation', '1');
    formData.append('preferred_return_method', payload.preferred_return_method);

    payload.images.forEach((file, index) => {
      formData.append(`images[${index}]`, file);
    });

    return axios.post('/api/repair-pos/warranty-claims', formData, {
      withCredentials: true,
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    });
  },
};
