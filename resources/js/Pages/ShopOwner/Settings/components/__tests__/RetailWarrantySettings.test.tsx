import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import axios from 'axios';
import RetailWarrantySettings from '../RetailWarrantySettings';

vi.mock('axios', () => ({ default: { put: vi.fn(), get: vi.fn(), isAxiosError: vi.fn(() => false) } }));

const initial = { enabled: false, title: 'Product Warranty', duration_value: 5, duration_unit: 'days' as const,
  description: '', terms: 'Our custom terms', exclusions: '', instructions: '' };

describe('Retail Product Warranty settings', () => {
  beforeEach(() => { vi.clearAllMocks(); vi.mocked(axios.put).mockResolvedValue({ data: {} }); });

  it('saves the independent retail policy with native units and shop-defined terms', async () => {
    render(<RetailWarrantySettings initial={initial} />);
    fireEvent.click(screen.getByLabelText('Enable Retail Product Warranty'));
    fireEvent.change(screen.getByLabelText('Duration unit'), { target: { value: 'years' } });
    fireEvent.change(screen.getByLabelText('Duration value'), { target: { value: '1' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save warranty settings' }));
    await waitFor(() => expect(axios.put).toHaveBeenCalledWith('/shop-owner/settings/retail-warranty', expect.objectContaining({ enabled: true, duration_value: 1, duration_unit: 'years', terms: 'Our custom terms' })));
    expect(await screen.findByRole('status')).toHaveTextContent('Warranty settings saved.');
  });

  it('shows a recoverable error without claiming a failed save succeeded', async () => {
    vi.mocked(axios.put).mockRejectedValue(new Error('offline'));
    render(<RetailWarrantySettings initial={initial} />);
    fireEvent.click(screen.getByRole('button', { name: 'Save warranty settings' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Could not save warranty settings.');
  });
});
