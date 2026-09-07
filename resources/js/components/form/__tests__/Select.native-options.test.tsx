import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import Select from '../Select';

describe('shared Select native-options adapter', () => {
  it('renders a custom monochrome menu while preserving native select values and changes', () => {
    const onChange = vi.fn();

    render(
      <Select
        aria-label="Status"
        name="status"
        defaultValue="pending"
        onChange={onChange}
      >
        <option value="">All statuses</option>
        <option value="pending">Pending</option>
        <option value="approved">Approved</option>
      </Select>,
    );

    const trigger = screen.getByRole('combobox', { name: 'Status' });
    expect(trigger).toHaveTextContent('Pending');

    fireEvent.click(trigger);

    const selectedOption = screen.getByRole('option', { name: 'Pending' });
    expect(selectedOption).toHaveAttribute('aria-selected', 'true');
    expect(selectedOption).toHaveClass('bg-gray-950', 'text-white');

    fireEvent.click(screen.getByRole('option', { name: 'Approved' }));

    expect(onChange).toHaveBeenCalledTimes(1);
    expect(onChange.mock.calls[0][0].target.value).toBe('approved');
    expect(screen.getByRole('combobox', { name: 'Status' })).toHaveTextContent('Approved');
    expect(screen.getByRole('combobox', { name: 'Status' })).toHaveAttribute('aria-expanded', 'false');
    expect(screen.getByRole('combobox', { name: 'Status' })).toHaveAttribute('id', expect.stringContaining('select-trigger'));
    expect(document.querySelector('select')).toHaveValue('approved');
  });
});
