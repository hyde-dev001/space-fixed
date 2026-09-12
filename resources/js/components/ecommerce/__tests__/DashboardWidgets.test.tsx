import { fireEvent, render, screen } from '@testing-library/react';
import { vi } from 'vitest';
import MonthlySalesChart from '../MonthlySalesChart';
import RecentOrders from '../RecentOrders';

vi.mock('react-apexcharts', () => ({
  default: ({ height }: { height: number }) => <div data-testid="revenue-chart" data-height={height} />,
}));

const orders = Array.from({ length: 10 }, (_, index) => ({
  id: index + 1,
  order_number: `ORDER-${index + 1}`,
  customer_name: `Customer ${index + 1}`,
  customer_email: `customer${index + 1}@example.com`,
  total_amount: 1000 + index,
  status: 'delivered',
  created_at: '2026-08-25T00:00:00.000Z',
  items_count: 1,
  order_items: [{
    id: index + 1,
    product_id: index + 1,
    quantity: 1,
    price: 1000 + index,
    product: {
      id: index + 1,
      name: `Product ${index + 1}`,
      images: null,
    },
  }],
}));

describe('Shop owner dashboard widgets', () => {
  it('uses the available card height for the weekly revenue chart', () => {
    render(<MonthlySalesChart revenueTrend={[{ date: '2026-08-25', revenue: 1000 }]} />);

    expect(screen.getByTestId('revenue-chart')).toHaveAttribute('data-height', '380');
  });

  it('paginates recent orders five rows at a time', () => {
    render(<RecentOrders orders={orders} />);

    expect(screen.getByText('Product 1')).toBeInTheDocument();
    expect(screen.queryByText('Product 6')).not.toBeInTheDocument();
    expect(screen.getByText('Showing 1 to 5 of 10 orders')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Previous page' })).toBeDisabled();

    fireEvent.click(screen.getByRole('button', { name: 'Next page' }));

    expect(screen.queryByText('Product 1')).not.toBeInTheDocument();
    expect(screen.getByText('Product 6')).toBeInTheDocument();
    expect(screen.getByText('Showing 6 to 10 of 10 orders')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Next page' })).toBeDisabled();
  });
});
