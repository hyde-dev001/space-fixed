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
};
