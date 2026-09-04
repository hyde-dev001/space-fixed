import React from 'react';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
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
  swalFire: vi.fn(),
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
  ColorVariantManager: ({ onColorVariantsChange }: { onColorVariantsChange?: (variants: unknown[]) => void }) => (
    <button
      type="button"
      data-testid="set-invalid-size"
      onClick={() => onColorVariantsChange?.([{
        id: 'color-1',
        color_name: 'Black',
        color_code: '#000000',
        isExpanded: false,
        images: [],
        sizes: [{ id: 'size-1', size: '7', size_system: 'US', quantity: 0 }],
      }])}
    >
      Set invalid size
    </button>
  ),
}));

vi.mock('@/components/variants/ColorVariantImageUploader', () => ({
  ColorVariantImageUploader: () => null,
}));

vi.mock('sweetalert2', () => ({ default: { fire: mocks.swalFire } }));

const uploadInventorySource = readFileSync(resolve('resources/js/Pages/ERP/inventory/UploadInventory.tsx'), 'utf8');
const colorVariantManagerSource = readFileSync(resolve('resources/js/components/variants/ColorVariantManager.tsx'), 'utf8');
const colorVariantImageUploaderSource = readFileSync(resolve('resources/js/components/variants/ColorVariantImageUploader.tsx'), 'utf8');

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
  mocks.swalFire.mockReset();
  mocks.swalFire.mockResolvedValue({ isConfirmed: false });
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

describe('upload inventory monochrome feedback', () => {
  it('keeps upload controls neutral instead of using blue UI accents', () => {
    for (const source of [uploadInventorySource, colorVariantManagerSource, colorVariantImageUploaderSource]) {
      expect(source).not.toMatch(/(?:bg|text|border|ring|from|to|hover:bg|hover:text|hover:border)-blue-/);
      expect(source).not.toContain('alert(');
    }

    expect(uploadInventorySource).toContain('bg-black text-white rounded-lg hover:bg-gray-800');
    expect(colorVariantManagerSource).toContain('bg-black hover:bg-gray-800 text-white');
    expect(colorVariantImageUploaderSource).toContain('bg-black hover:bg-gray-800 text-white');
  });

  it('shows the zero-size validation through SweetAlert2', async () => {
    mocks.page.props = {
      auth: {
        erpActor: { ownerMode: false, type: 'shop_owner' },
        shop_owner: { business_type: 'both' },
      },
      initialData: { data: [] },
      erpCapabilities: {},
    };
    const browserAlert = vi.spyOn(window, 'alert').mockImplementation(() => undefined);

    render(<UploadInventory />);

    fireEvent.click(screen.getByRole('button', { name: '+ Add Stock Entry' }));
    fireEvent.click(screen.getByTestId('set-invalid-size'));
    fireEvent.submit(screen.getByRole('button', { name: 'Save Stock' }).closest('form') as HTMLFormElement);

    await waitFor(() => expect(mocks.swalFire).toHaveBeenCalledWith(expect.objectContaining({
      icon: 'warning',
      title: 'Invalid stock quantity',
      text: 'Each size must have stock greater than 0.',
      confirmButtonColor: '#000000',
    })));
    expect(browserAlert).not.toHaveBeenCalled();

    browserAlert.mockRestore();
  });
});
