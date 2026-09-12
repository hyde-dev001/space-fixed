import { describe, expect, it } from 'vitest';
import { mergeRepairProcessPrefill } from '../repairProcessPrefill';

describe('mergeRepairProcessPrefill', () => {
    it('fills empty contact and delivery fields without replacing saved values', () => {
        const result = mergeRepairProcessPrefill(
            {
                customerName: 'Saved Name',
                email: '',
                phone: '',
                pickupAddressLine: '',
                pickupBarangay: '',
                pickupCity: '',
                pickupRegion: '',
                pickupPostalCode: '',
                returnAddressLine: 'Saved Return',
                returnBarangay: '',
                returnCity: '',
                returnRegion: '',
                returnPostalCode: '',
            },
            { name: 'Account Name', email: 'user@example.com' },
            {
                address_line: '123 Rizal St',
                barangay: 'Ermita',
                city: 'Manila',
                province: 'Metro Manila',
                region: 'NCR',
                postal_code: '1000',
                phone: '09171234567',
            },
        );

        expect(result).toMatchObject({
            customerName: 'Saved Name',
            email: 'user@example.com',
            phone: '09171234567',
            pickupAddressLine: '123 Rizal St',
            pickupBarangay: 'Ermita',
            pickupCity: 'Manila',
            pickupRegion: 'Metro Manila',
            pickupPostalCode: '1000',
            returnAddressLine: 'Saved Return',
            returnBarangay: 'Ermita',
            returnCity: 'Manila',
            returnRegion: 'Metro Manila',
            returnPostalCode: '1000',
        });
    });
});
