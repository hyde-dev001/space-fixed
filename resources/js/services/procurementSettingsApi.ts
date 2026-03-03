/**
 * Procurement Settings API Service
 * 
 * Handles all API calls for Procurement Settings management
 */

import axios, { AxiosResponse } from 'axios';
import {
    ProcurementSettings,
    UpdateProcurementSettingsPayload,
} from '@/types/procurement';

const BASE_URL = '/api/erp/procurement/settings';

export const procurementSettingsApi = {
    /**
     * Get procurement settings for current shop owner
     */
    async get(): Promise<ProcurementSettings> {
        const response: AxiosResponse<ProcurementSettings> = await axios.get(BASE_URL);
        return response.data;
    },

    /**
     * Update procurement settings
     */
    async update(data: UpdateProcurementSettingsPayload): Promise<ProcurementSettings> {
        const response: AxiosResponse<ProcurementSettings> = await axios.put(BASE_URL, data);
        return response.data;
    },
};

export default procurementSettingsApi;
