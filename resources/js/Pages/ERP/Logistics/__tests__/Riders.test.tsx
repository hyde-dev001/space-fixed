import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import Riders from '../Riders';

const mocks = vi.hoisted(() => ({
  props: {} as Record<string, unknown>,
  get: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  Link: ({ children, preserveScroll: _preserveScroll, preserveState: _preserveState, ...props }: React.PropsWithChildren<Record<string, unknown>>) => <a {...props}>{children}</a>,
  router: { get: mocks.get },
  usePage: () => ({ props: mocks.props }),
}));
vi.mock('@/layout/AppLayout_ERP', () => ({ default: ({ children }: React.PropsWithChildren) => <>{children}</> }));

beforeEach(() => {
  vi.clearAllMocks();
  mocks.props = {
    riders: {
      data: [
        { id: 1, name: 'Marco Santos', phone: '+639180000002', rider_type: 'employee', availability_status: 'available', active: true, daily_capacity: 10 },
        { id: 2, name: 'Paolo Mendoza', phone: '+639180000003', rider_type: 'contractor', availability_status: 'busy', active: false, daily_capacity: null },
      ],
      links: [
        { url: null, label: '&laquo; Previous', active: false },
        { url: '/erp/logistics/riders?page=1', label: '1', active: true },
        { url: null, label: 'Next &raquo;', active: false },
      ],
      from: 1,
      to: 2,
      total: 2,
      current_page: 1,
      last_page: 1,
    },
    filters: { availability: 'all', type: 'all' },
    auth: { erpActor: { ownerMode: false } },
  };
});

it('renders compact rider cards and keeps the desktop table behind xl', () => {
  render(<Riders />);

  expect(screen.getByTestId('riders-mobile-list')).toHaveClass('xl:hidden');
  expect(screen.getByTestId('riders-desktop-table')).toHaveClass('hidden', 'xl:block');
  expect(screen.getByTestId('rider-card-1')).toHaveTextContent('Marco Santos');
  expect(screen.getByTestId('rider-card-1')).toHaveTextContent('Employee');
  expect(screen.getByTestId('rider-card-1')).toHaveTextContent('+639180000002');
  expect(screen.getByTestId('rider-card-1')).toHaveTextContent('10 stops');
  expect(screen.getByTestId('rider-card-1')).toHaveTextContent('Available');
  expect(screen.getByTestId('rider-card-1')).toHaveTextContent('Active');
  expect(screen.getByTestId('riders-page-intro')).toHaveClass('text-center', 'xl:text-left');
  expect(screen.getByTestId('riders-filter-bar')).toHaveClass('mx-auto', 'max-w-md', 'grid-cols-1', 'sm:grid-cols-2', 'xl:flex', 'xl:mx-0');
  expect(screen.getByTestId('rider-card-1')).toHaveClass('mx-auto', 'max-w-2xl');
  expect(screen.getByTestId('riders-pagination')).toHaveClass('flex-col', 'items-center', 'xl:flex-row', 'xl:justify-between');
  expect(screen.getByTestId('riders-pagination-links')).toHaveClass('justify-center', 'xl:justify-end');
});

it('keeps rider filters wired to the existing Inertia request', () => {
  render(<Riders />);

  fireEvent.change(screen.getByLabelText('Filter riders by availability'), { target: { value: 'busy' } });

  expect(mocks.get).toHaveBeenCalledWith('/erp/logistics/riders', { availability: 'busy', type: 'all', page: 1 }, {
    preserveScroll: true,
    preserveState: true,
  });
});
