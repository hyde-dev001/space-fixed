import React from 'react';
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import axios from 'axios';

const { usePageMock, routerMock } = vi.hoisted(() => ({
	usePageMock: vi.fn(),
	routerMock: { get: vi.fn(), put: vi.fn(), post: vi.fn() },
}));

vi.mock('@inertiajs/react', () => ({ Head: () => null, usePage: usePageMock, router: routerMock }));
vi.mock('ziggy-js', () => ({ route: (name: string) => `/${name}` }));
vi.mock('@/components/shop-owner/OwnerSetupGuide', () => ({ default: () => null }));
vi.mock('@/components/UserProfile/EmployeeTotpSecurity', () => ({ default: () => null }));
vi.mock('../components/BusinessScalingSettings', () => ({ default: () => null }));
vi.mock('leaflet', () => {
	const layer = { addTo: vi.fn().mockReturnThis(), on: vi.fn().mockReturnThis(), setLatLng: vi.fn().mockReturnThis(), setRadius: vi.fn() };
	const map = { setView: vi.fn().mockReturnThis(), on: vi.fn().mockReturnThis(), invalidateSize: vi.fn() };
	return { Icon: { Default: { prototype: {}, mergeOptions: vi.fn() } }, map: () => map, tileLayer: () => layer, marker: () => layer, circle: () => layer };
});
vi.mock('axios', () => ({ default: { get: vi.fn(), put: vi.fn(), isAxiosError: vi.fn(() => false) } }));

import ShopSettings from '../shopSetting';

const pageState = {
	props: {
		initialSection: 'operations',
		shop_settings: {
			registration_type: 'company',
			business_type: 'retail',
			business_name: 'Grace Shop',
			can_manage_staff: false,
			max_locations: null,
			approval_pages: Object.fromEntries(['refund_approval', 'price_approval', 'payslip_approval', 'salary_adjustment_approval', 'purchase_request_approval', 'expense_approval'].map((key) => [key, { enabled: false }])),
			business_scaling: {},
			pay_cycle: 'monthly',
			pay_day_first: 15,
			pay_day_second: 30,
			required_documents: [],
			document_compliance: [],
			repair_payment_policy: 'full_upfront',
			repair_workload_limit: 20,
			repair_warranty_enabled: true,
			repair_warranty_duration: 30,
			repair_warranty_duration_unit: 'days',
			order_refund_deadline_days: 7,
			late_grace_period_minutes: 0,
			cod_enabled: false,
			cod_order_threshold: 5000,
			cod_can_enable: false,
			cod_enablement_message: null,
			totp_enabled: false,
			has_paymongo_key: false,
			xendit_supplier_payouts: {},
			attendance_geofence_enabled: false,
			shop_latitude: null,
			shop_longitude: null,
			shop_address: null,
			shop_geofence_radius: 100,
			premium: { eligible: false },
		},
	},
};

beforeEach(() => {
	vi.clearAllMocks();
	usePageMock.mockReturnValue(pageState);
	vi.mocked(axios.get).mockResolvedValue({ data: { data: [], last_page: 1, current_page: 1, total: 0 } });
	vi.mocked(axios.put).mockResolvedValue({ data: {} });
	routerMock.put.mockImplementation((_url, _payload, options) => options?.onSuccess?.());
	Element.prototype.scrollIntoView = vi.fn();
});

afterEach(() => cleanup());

it('shows the grace period below the refund deadline and saves whole minutes', async () => {
	await act(async () => { render(<ShopSettings />); });

	const refundHeading = screen.getByRole('heading', { name: 'Refund Deadline' });
	const graceHeading = screen.getByRole('heading', { name: 'Late Check-in Grace Period' });
	expect(refundHeading.compareDocumentPosition(graceHeading) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();

	const input = screen.getByRole('spinbutton', { name: 'Late check-in grace period in minutes' });
	expect(input).toHaveValue(0);
	expect(input).toHaveAttribute('min', '0');
	expect(input).toHaveAttribute('max', '120');

	await act(async () => {
		fireEvent.change(input, { target: { value: '15' } });
		fireEvent.click(screen.getByRole('button', { name: 'Save Grace Period' }));
	});

	await waitFor(() => expect(routerMock.put).toHaveBeenCalledTimes(1));
	expect(routerMock.put.mock.calls[0][0]).toBe('/shop-owner/settings');
	expect(routerMock.put.mock.calls[0][1]).toEqual({ late_grace_period_minutes: 15 });
	expect(await screen.findByText('Grace period saved.')).toBeInTheDocument();
});

it('rejects a value outside the configured range before saving', async () => {
	await act(async () => { render(<ShopSettings />); });
	await act(async () => {
		fireEvent.change(screen.getByRole('spinbutton', { name: 'Late check-in grace period in minutes' }), {
			target: { value: '121' },
		});
		fireEvent.click(screen.getByRole('button', { name: 'Save Grace Period' }));
	});

	expect(screen.getByRole('alert')).toHaveTextContent('0 to 120 minutes');
	expect(routerMock.put).not.toHaveBeenCalled();
});
