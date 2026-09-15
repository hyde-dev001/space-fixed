import { fireEvent, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { enhanceSweetAlertSelect } from '../monochromeSweetAlertSelect';

afterEach(() => {
  document.body.innerHTML = '';
});

describe('SweetAlert monochrome select enhancer', () => {
  it('does not enhance SweetAlert2 hidden template selects', () => {
    const wrapper = document.createElement('div');
    wrapper.className = 'swal2-container';
    wrapper.innerHTML = '<select class="swal2-select" style="display: none"></select>';
    document.body.appendChild(wrapper);

    const select = wrapper.querySelector<HTMLSelectElement>('.swal2-select');
    expect(select).not.toBeNull();

    enhanceSweetAlertSelect(select as HTMLSelectElement);

    expect(wrapper.querySelector('[role="combobox"]')).toBeNull();
    expect(wrapper.querySelector('.swal2-select')).toBe(select);
  });

  it('keeps the native value while presenting monochrome custom options', () => {
    const wrapper = document.createElement('div');
    wrapper.className = 'swal2-container';
    wrapper.innerHTML = `
      <label for="payment-method">Payment Method</label>
      <select id="payment-method" name="payment_method">
        <option value="cash">Cash</option>
        <option value="bank_transfer">Bank Transfer</option>
      </select>
    `;
    document.body.appendChild(wrapper);

    const select = wrapper.querySelector<HTMLSelectElement>('#payment-method');
    expect(select).not.toBeNull();
    const changeHandler = vi.fn();
    select?.addEventListener('change', changeHandler);

    enhanceSweetAlertSelect(select as HTMLSelectElement);

    const trigger = screen.getByRole('combobox', { name: 'Payment Method' });
    fireEvent.click(trigger);

    const bankOption = within(wrapper).getByRole('option', { name: 'Bank Transfer' });
    expect(bankOption).toHaveClass('text-gray-900', 'hover:bg-gray-100');
    fireEvent.click(bankOption);

    expect(select).toHaveValue('bank_transfer');
    expect(changeHandler).toHaveBeenCalledTimes(1);
    expect(trigger).toHaveTextContent('Bank Transfer');
    expect(trigger).toHaveAttribute('aria-expanded', 'false');
  });
});
