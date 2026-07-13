import axios from 'axios';

export const logisticsApi = {
  shipments: () => axios.get('/api/logistics/shipments'),
  riders: () => axios.get('/api/logistics/riders'),
  assignLeg: (legId: number, riderProfileId: number) =>
    axios.post(`/api/logistics/legs/${legId}/assign`, {
      assignment_type: 'internal_rider',
      rider_profile_id: riderProfileId,
    }),
  recordProof: (legId: number, payload: Record<string, unknown>) =>
    axios.post(`/api/logistics/legs/${legId}/proof`, payload),
  batches: () => axios.get('/api/logistics/batches'),
  suggestions: (deliveryDate: string, deliveryWindow: string) =>
    axios.get('/api/logistics/batch-suggestions', { params: { delivery_date: deliveryDate, delivery_window: deliveryWindow } }),
  createBatch: (payload: Record<string, unknown>) => axios.post('/api/logistics/batches', payload),
  scheduleLegs: (legIds: number[], deliveryDate: string, deliveryWindow: string) =>
    axios.post('/api/logistics/legs/schedule', { leg_ids: legIds, delivery_date: deliveryDate, delivery_window: deliveryWindow }),
  updateBatch: (id: number, legIds: number[]) => axios.put(`/api/logistics/batches/${id}`, { leg_ids: legIds }),
  removeBatchStop: (id: number, legId: number) => axios.delete(`/api/logistics/batches/${id}/legs/${legId}`),
  markUrgent: (legId: number, urgent = true) => axios.post(`/api/logistics/legs/${legId}/urgent`, { urgent }),
  confirmPickup: (legId: number, proofId: number) => axios.post(`/api/logistics/legs/${legId}/pickup-proofs/${proofId}/confirm`),
  rejectPickup: (legId: number, proofId: number, reason: string) => axios.post(`/api/logistics/legs/${legId}/pickup-proofs/${proofId}/reject`, { reason }),
  outForDelivery: (legId: number) => axios.post(`/api/logistics/legs/${legId}/out-for-delivery`),
  reportIncident: (legId: number, payload: Record<string, unknown>) => axios.post(`/api/logistics/legs/${legId}/incidents`, payload),
  resolveIncident: (incidentId: number, payload: Record<string, unknown>) => axios.post(`/api/logistics/incidents/${incidentId}/resolve`, payload),
  createReturnToShop: (legId: number) => axios.post(`/api/logistics/legs/${legId}/return-to-shop`),
  confirmReturnHandoff: (legId: number, proofId: number) => axios.post(`/api/logistics/legs/${legId}/return-proofs/${proofId}/handoff`),
  confirmReturnReceipt: (legId: number, proofId: number) => axios.post(`/api/logistics/legs/${legId}/return-proofs/${proofId}/receipt`),
  offerBatch: (id: number, riderProfileId: number) => axios.post(`/api/logistics/batches/${id}/offer`, { rider_profile_id: riderProfileId }),
  acceptBatch: (id: number) => axios.post(`/api/logistics/batches/${id}/accept`),
  rejectBatch: (id: number, rejectionReason: string) => axios.post(`/api/logistics/batches/${id}/reject`, { rejection_reason: rejectionReason }),
  startBatch: (id: number) => axios.post(`/api/logistics/batches/${id}/start`),
  cancelBatch: (id: number, reason: string) => axios.post(`/api/logistics/batches/${id}/cancel`, { reason }),
};
