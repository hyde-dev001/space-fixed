import React from 'react';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

const mocks = vi.hoisted(() => ({
  ownerMode: true, downloadAllowed: true, fetch: vi.fn(), csrf: vi.fn(), swal: vi.fn(), click: vi.fn(),
}));
vi.mock('@inertiajs/react', () => ({ Head: () => null, usePage: () => ({ props: {
  auth: { erpActor: { ownerMode: mocks.ownerMode } },
  erpCapabilities: {
    'GET:api.manager.reports.index': { allowed: true, url: mocks.ownerMode ? '/api/shop-owner/erp/manager/reports' : '/api/manager/reports' },
    'GET:api.manager.reports.download': { allowed: mocks.downloadAllowed,
      url: mocks.ownerMode ? '/api/shop-owner/erp/manager/reports/__ERP_PARAM_id__/download' : '/api/manager/reports/__ERP_PARAM_id__/download' },
  },
} }) }));
vi.mock('../../../../layout/AppLayout_ERP', () => ({ default: ({ children }: React.PropsWithChildren) => <>{children}</> }));
vi.mock('@/utils/fetch-with-csrf', () => ({ fetchWithCsrf: mocks.csrf }));
vi.mock('sweetalert2', () => ({ default: { fire: mocks.swal } }));
import Reports from '../Reports';

const records = ['generated', 'reviewed', 'sent', 'failed'].map((status, index) => ({
  id: index + 1, report_type: 'sales', report_title: `QA report ${index + 1}`, description: '', date_range: 'week',
  status, notes: null, generated_at: '2026-10-04T10:00:00Z', reviewed_at: null, downloaded_at: null,
}));
beforeEach(() => {
  vi.clearAllMocks();
  mocks.ownerMode = true;
  mocks.downloadAllowed = true;
  mocks.fetch.mockImplementation(async (url: string) => url.endsWith('/download') ? {
    ok: true, blob: async () => new Blob(['safe CSV']), headers: new Headers({ 'Content-Disposition': 'attachment; filename="sales-qa.csv"' }),
  } : { ok: true, json: async () => ({ metrics: {}, report_types: [{ id: 'sales', title: 'Sales Report', description: '', last_report: records[0] }], recent_reports: records }) });
  vi.stubGlobal('fetch', mocks.fetch);
  Object.defineProperty(URL, 'createObjectURL', { configurable: true, value: vi.fn(() => 'blob:qa-download') });
  Object.defineProperty(URL, 'revokeObjectURL', { configurable: true, value: vi.fn() });
  vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(mocks.click);
});
afterEach(() => { cleanup(); vi.restoreAllMocks(); vi.unstubAllGlobals(); });

it('downloads completed owner reports through the server-approved owner URL', async () => {
  render(<Reports />);
  const download = await screen.findByRole('button', { name: 'Download QA report 1' });
  expect(screen.getByRole('button', { name: 'Download QA report 2' })).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Download QA report 3' })).toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Download QA report 4' })).not.toBeInTheDocument();
  fireEvent.click(download);
  await waitFor(() => expect(mocks.click).toHaveBeenCalledTimes(1));
  expect(mocks.fetch).toHaveBeenCalledWith('/api/shop-owner/erp/manager/reports/1/download', expect.objectContaining({ credentials: 'include' }));
  expect(mocks.fetch).not.toHaveBeenCalledWith('/api/manager/reports/1/download', expect.anything());
  expect(screen.queryByRole('button', { name: /Generate/ })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: /as reviewed/ })).not.toBeInTheDocument();
  expect(mocks.csrf).not.toHaveBeenCalled();
});

it('does not offer an owner download when the projected GET capability is denied', async () => {
  mocks.downloadAllowed = false;
  render(<Reports />);
  await screen.findByText('QA report 1');
  expect(screen.queryByRole('button', { name: /Download/ })).not.toBeInTheDocument();
  expect(mocks.csrf).not.toHaveBeenCalled();
});

it('retains the Manager download path and mutation controls', async () => {
  mocks.ownerMode = false;
  render(<Reports />);
  fireEvent.click(await screen.findByRole('button', { name: 'Download QA report 1' }));
  await waitFor(() => expect(mocks.click).toHaveBeenCalledTimes(1));
  expect(mocks.fetch).toHaveBeenCalledWith('/api/manager/reports/1/download', expect.objectContaining({ credentials: 'include' }));
  expect(screen.getByRole('button', { name: 'Generate Sales Report' })).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Mark QA report 1 as reviewed' })).toBeInTheDocument();
});
