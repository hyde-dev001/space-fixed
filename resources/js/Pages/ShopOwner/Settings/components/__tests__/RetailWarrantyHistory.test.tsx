import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import axios from 'axios';
import RetailWarrantyHistory from '../RetailWarrantyHistory';

vi.mock('axios', () => ({ default: { get: vi.fn(), patch: vi.fn(), isCancel: vi.fn(() => false) } }));

describe('Issued Warranties management', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(axios.get).mockResolvedValue({ data: { data: [], total: 0, current_page: 1, last_page: 1 } });
  });

  it('loads and searches server-paginated history with effective status filters', async () => {
    render(<RetailWarrantyHistory />);
    expect(await screen.findByText('No issued warranties match your search.')).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText('Search warranties'), { target: { value: 'WRNTY-2026' } });
    fireEvent.click(screen.getByRole('button', { name: 'Search' }));
    await waitFor(() => expect(axios.get).toHaveBeenLastCalledWith('/api/shop-owner/retail-warranties', expect.objectContaining({ params: expect.objectContaining({ search: 'WRNTY-2026', page: 1 }) })));
    fireEvent.change(screen.getByLabelText('Warranty status'), { target: { value: 'partially_used' } });
    await waitFor(() => expect(axios.get).toHaveBeenLastCalledWith('/api/shop-owner/retail-warranties', expect.objectContaining({ params: expect.objectContaining({ status: 'partially_used' }) })));
  });

  it('shows a retry action when history could not load', async () => {
    vi.mocked(axios.get).mockRejectedValue(new Error('offline'));
    render(<RetailWarrantyHistory />);
    expect(await screen.findByRole('alert')).toHaveTextContent('Could not load issued warranties.');
    expect(screen.getByRole('button', { name: 'Retry' })).toBeInTheDocument();
  });
});
