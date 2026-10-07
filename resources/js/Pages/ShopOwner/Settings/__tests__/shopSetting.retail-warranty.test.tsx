import React from 'react';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import axios from 'axios';

const { usePageMock } = vi.hoisted(() => ({ usePageMock: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ Head: () => null, usePage: usePageMock, router: { get: vi.fn(), put: vi.fn(), post: vi.fn() } }));
vi.mock('ziggy-js', () => ({ route: (name: string) => `/${name}` }));
vi.mock('@/components/shop-owner/OwnerSetupGuide', () => ({ default: () => null }));
vi.mock('@/components/UserProfile/EmployeeTotpSecurity', () => ({ default: () => null }));
vi.mock('../components/BusinessScalingSettings', () => ({ default: () => null }));
vi.mock('leaflet', () => {
  const layer = { addTo: vi.fn().mockReturnThis(), on: vi.fn().mockReturnThis(), setLatLng: vi.fn().mockReturnThis(), setRadius: vi.fn() };
  const map = { setView: vi.fn().mockReturnThis(), on: vi.fn().mockReturnThis(), invalidateSize: vi.fn() };
  return { Icon: { Default: { prototype: {}, mergeOptions: vi.fn() } }, map: () => map,
    tileLayer: () => layer, marker: () => layer, circle: () => layer };
});
vi.mock('axios', () => ({ default: { get: vi.fn(), put: vi.fn(), isAxiosError: vi.fn(() => false) } }));

import ShopSettings from '../shopSetting';

const policy = { enabled: true, title: 'Product Warranty', duration_value: 1, duration_unit: 'years',
  description: '', terms: 'Future eligible purchases only', exclusions: '', instructions: '' };

function settings(registration: string, business: string) {
  return { props: { initialSection: 'operations', shop_settings: {
    registration_type: registration, business_type: business, business_name: 'Warranty Shop', can_manage_staff: false,
    premium: { eligible: false }, required_documents: [], document_compliance: [],
    approval_pages: Object.fromEntries(['refund_approval', 'price_approval', 'payslip_approval', 'salary_adjustment_approval', 'purchase_request_approval', 'expense_approval'].map(key => [key, { enabled: false }])),
    retail_warranty: policy,
  } } };
}

describe('Shop Settings retail warranty configuration only', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(axios.get).mockResolvedValue({ data: { data: [], last_page: 1, current_page: 1, total: 0 } });
    vi.mocked(axios.put).mockResolvedValue({ data: {} });
    Element.prototype.scrollIntoView = vi.fn();
  });

  it.each([['individual', 'retail'], ['individual', 'both'], ['company', 'retail'], ['company', 'both']])('keeps configuration without issued management for %s/%s', async (registration, business) => {
    usePageMock.mockReturnValue(settings(registration, business));
    await act(async () => { render(<ShopSettings />); });
    expect(screen.getByRole('heading', { name: 'Retail Product Warranty' })).toBeVisible();
    expect(screen.queryByRole('heading', { name: 'Issued Warranties' })).not.toBeInTheDocument();
    expect(screen.queryByLabelText('Search warranties')).not.toBeInTheDocument();
    expect(screen.queryByLabelText('Warranty status')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /view details|void coverage|confirm void/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Download Warranty PDF' })).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Save warranty settings' }));
    await waitFor(() => expect(axios.put).toHaveBeenCalledWith('/shop-owner/settings/retail-warranty', policy));
    expect(await screen.findByText('Warranty settings saved.')).toBeInTheDocument();
    expect(vi.mocked(axios.get).mock.calls.some(([url]) => String(url).startsWith('/api/shop-owner/retail-warranties'))).toBe(false);
  });

  it('does not expose retail warranty configuration to repair-only shops', async () => {
    usePageMock.mockReturnValue(settings('individual', 'repair'));
    await act(async () => { render(<ShopSettings />); });
    expect(screen.queryByRole('heading', { name: 'Retail Product Warranty' })).not.toBeInTheDocument();
  });
});
