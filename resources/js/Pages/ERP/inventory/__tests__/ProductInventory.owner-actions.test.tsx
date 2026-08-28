import React from 'react';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ProductInventory from '../ProductInventory';
import UploadInventory from '../UploadInventory';

const mocks = vi.hoisted(() => ({
  page: {
    props: {
      auth: { erpActor: { ownerMode: true, type: 'shop_owner' } },
      initialData: { data: [] },
      erpCapabilities: {},
    },
  },
  getProducts: vi.fn(),
  getItems: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  usePage: () => mocks.page,
}));

vi.mock('../../../../layout/AppLayout_ERP', () => ({
  default: ({ children }: React.PropsWithChildren) => <>{children}</>,
}));

vi.mock('@/services/inventoryAPI', () => ({
  productInventoryAPI: {
    getAll: mocks.getProducts,
    updateQuantity: vi.fn(),
  },
  inventoryItemAPI: {
    getAll: mocks.getItems,
    restore: vi.fn(),
    delete: vi.fn(),
    update: vi.fn(),
    create: vi.fn(),
    uploadImages: vi.fn(),
    deleteImage: vi.fn(),
    addSizeToColorVariant: vi.fn(),
    addColor: vi.fn(),
    updateSizeQuantity: vi.fn(),
  },
}));

vi.mock('@/components/variants/ColorVariantManager', () => ({
  ColorVariantManager: () => null,
}));

vi.mock('@/components/variants/ColorVariantImageUploader', () => ({
  ColorVariantImageUploader: () => null,
}));

vi.mock('sweetalert2', () => ({ default: { fire: vi.fn() } }));

const product = {
  id: 21,
  name: 'Runner',
  sku: 'RUN-21',
  category: 'shoes',
  brand: 'SoleSpace',
  unit: 'pairs',
  available_quantity: 12,
  reserved_quantity: 1,
  updated_at: '2026-08-20',
  main_image: 'runner.jpg',
  images: [{ image_path: 'runner.jpg' }],
  sizes: [{ size: '9' }],
  color_variants: [],
};

beforeEach(() => {
  mocks.page.props = {
    auth: { erpActor: { ownerMode: true, type: 'shop_owner' } },
    initialData: { data: [product] },
    erpCapabilities: {
      'GET:inventory.products.index': {
        allowed: true,
        url: '/api/shop-owner/erp/inventory/products',
      },
    },
  };
  mocks.getProducts.mockReset();
  mocks.getProducts.mockResolvedValue({ data: [product] });
  mocks.getItems.mockReset();
  mocks.getItems.mockResolvedValue({ data: [] });
});

afterEach(() => {
  cleanup();
});

describe('owner product inventory action boundary', () => {
  it('keeps product stock details read-only for owners', async () => {
    render(<ProductInventory />);

    expect(await screen.findByRole('heading', { name: 'Product Inventory' })).toBeInTheDocument();
    await waitFor(() => expect(mocks.getProducts).toHaveBeenCalled());

    fireEvent.click(screen.getByRole('button', { name: 'View details for Runner' }));

    expect(await screen.findByRole('heading', { name: 'Runner' })).toBeInTheDocument();
    expect(screen.queryByLabelText('Edit Available Quantity')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Save Quantity' })).not.toBeInTheDocument();
  });
});

describe('owner upload inventory action boundary', () => {
  it('keeps the upload form and image controls out of the owner view', () => {
    render(<UploadInventory />);

    expect(screen.getByRole('heading', { name: 'Upload Stocks' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: '+ Add Stock Entry' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Show Archived/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Edit stock/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Archive stock/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Save Quantity' })).not.toBeInTheDocument();
    expect(screen.queryByRole('textbox', { name: /image/i })).not.toBeInTheDocument();
    expect(screen.queryByDisplayValue('')).not.toBeInTheDocument();
  });
});
