import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ProductQuickView, { type ProductQuickViewProduct } from '../ProductQuickView';

const addToCartProps = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', () => ({
  Link: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...props}>
      {children}
    </a>
  ),
}));

vi.mock('../../CartActions', () => ({
  default: (props: { label?: string; disabled?: boolean; onAdded?: () => void }) => {
    addToCartProps(props);
    return (
      <button type="button" disabled={props.disabled} onClick={props.onAdded}>
        {props.label}
      </button>
    );
  },
}));

const product: ProductQuickViewProduct = {
  id: 7,
  name: 'SoleSpace Runner',
  slug: 'solespace-runner',
  price: 5555,
  compare_at_price: 6000,
  main_image: '/storage/products/runner.jpg',
  gallery_images: ['/storage/products/runner-side.jpg'],
  brand: 'SoleSpace',
  stock_quantity: 4,
  sizes_available: ['US 8', 'US 9'],
  colors_available: ['Black', 'White'],
};

describe('ProductQuickView', () => {
  beforeEach(() => {
    addToCartProps.mockClear();
  });

  it('renders product details, image navigation, and a category-preserving details link', () => {
    render(
      <ProductQuickView
        product={product}
        detailsHref="/products/solespace-runner?category=men"
        onClose={vi.fn()}
      />,
    );

    expect(screen.getByRole('dialog', { name: 'SoleSpace Runner' })).toBeInTheDocument();
    expect(screen.getByText('SoleSpace')).toBeInTheDocument();
    expect(screen.getByText(/5,555/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'View product details' })).toHaveAttribute(
      'href',
      '/products/solespace-runner?category=men',
    );
    expect(screen.getByRole('button', { name: 'Next product image' })).toBeInTheDocument();
  });

  it('passes selected color, size, image, and quantity to the existing cart action', () => {
    render(<ProductQuickView product={product} detailsHref="/products/solespace-runner" onClose={vi.fn()} />);

    fireEvent.click(screen.getByRole('button', { name: 'Color White' }));
    fireEvent.click(screen.getByRole('button', { name: 'Size US 9' }));
    fireEvent.click(screen.getByRole('button', { name: 'Increase quantity' }));

    const latestProps = addToCartProps.mock.lastCall?.[0];
    expect(latestProps).toMatchObject({
      productId: 7,
      stockQuantity: 4,
      disabled: false,
      label: 'Add to Cart',
    });
    expect(latestProps.product).toMatchObject({
      size: 'US 9',
      color: 'White',
      qty: 2,
      selectedImage: '/storage/products/runner.jpg',
    });
  });

  it('closes from Escape, backdrop click, and a successful cart add', () => {
    const onClose = vi.fn();
    const { container } = render(
      <ProductQuickView product={product} detailsHref="/products/solespace-runner" onClose={onClose} />,
    );

    fireEvent.keyDown(document, { key: 'Escape' });
    fireEvent.click(container.firstElementChild as HTMLElement);
    fireEvent.click(screen.getByRole('button', { name: 'Add to Cart' }));

    expect(onClose).toHaveBeenCalledTimes(3);
  });
});
