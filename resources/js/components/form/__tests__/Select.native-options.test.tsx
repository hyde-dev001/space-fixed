import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import Select from '../Select';

describe('shared Select native-options adapter', () => {
  it('keeps field styling on the trigger instead of duplicating it on the wrapper', () => {
    render(
      <Select
        aria-label="Status"
        className="mt-1 min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm"
      >
        <option value="">All statuses</option>
        <option value="pending">Pending</option>
      </Select>,
    );

    const trigger = screen.getByRole('combobox', { name: 'Status' });
    const wrapper = trigger.parentElement;

    expect(wrapper).toHaveClass('mt-1', 'w-full');
    expect(wrapper).not.toHaveClass('border', 'border-gray-300', 'bg-white', 'px-3');
    expect(trigger).toHaveClass('border-gray-300', 'bg-white', 'px-3');
  });

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

  it('keeps long option labels visible when the trigger uses intrinsic width', () => {
    render(
      <Select aria-label="Role">
        <option value="all">All</option>
        <option value="repairer">Logistics Dispatcher</option>
        <option value="recent">Recent (7 days)</option>
      </Select>,
    );

    fireEvent.click(screen.getByRole('combobox', { name: 'Role' }));

    expect(screen.getByRole('listbox')).toHaveClass(
      'w-max',
      'min-w-full',
      'max-w-[calc(100vw-2rem)]',
    );
    expect(screen.getByRole('option', { name: 'Logistics Dispatcher' })).toHaveClass('whitespace-nowrap');
  });

  it('opens upward when a clipping boundary leaves no room below', () => {
    render(
      <div style={{ height: 100, overflow: 'hidden' }}>
        <Select aria-label="Rider">
          <option value="">Choose available rider</option>
          <option value="one">Rider One</option>
          <option value="two">Rider Two</option>
        </Select>
      </div>,
    );

    const trigger = screen.getByRole('combobox', { name: 'Rider' });
    const boundary = trigger.parentElement?.parentElement;

    vi.spyOn(trigger, 'getBoundingClientRect').mockReturnValue({
      top: 70,
      bottom: 100,
      left: 0,
      right: 180,
      width: 180,
      height: 30,
      x: 0,
      y: 70,
      toJSON: () => ({}),
    });
    vi.spyOn(boundary!, 'getBoundingClientRect').mockReturnValue({
      top: 0,
      bottom: 100,
      left: 0,
      right: 200,
      width: 200,
      height: 100,
      x: 0,
      y: 0,
      toJSON: () => ({}),
    });

    fireEvent.click(trigger);

    expect(screen.getByRole('listbox')).toHaveClass('bottom-full', 'mb-1');
  });

  it('keeps an explicitly bottom-placed menu below its trigger', () => {
    render(
      <div style={{ height: 100, overflow: 'hidden' }}>
        <Select aria-label="Status" placement="bottom">
          <option value="">All statuses</option>
          <option value="pending">Pending</option>
        </Select>
      </div>,
    );

    const trigger = screen.getByRole('combobox', { name: 'Status' });
    vi.spyOn(trigger, 'getBoundingClientRect').mockReturnValue({
      top: 70,
      bottom: 100,
      left: 0,
      right: 180,
      width: 180,
      height: 30,
      x: 0,
      y: 70,
      toJSON: () => ({}),
    });

    fireEvent.click(trigger);

    expect(screen.getByRole('listbox')).toHaveClass('top-full', 'mt-1');
    expect(screen.getByRole('listbox')).not.toHaveClass('bottom-full');
  });
});
