import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { ColorVariantManager } from './ColorVariantManager';
import { NAMED_COLORS } from '../../data/namedColors';

vi.mock('./ColorVariantImageUploader', () => ({
  ColorVariantImageUploader: () => null,
}));

vi.mock('sweetalert2', () => ({
  default: { fire: vi.fn() },
}));

afterEach(() => {
  vi.clearAllMocks();
});

describe('ColorVariantManager named color search', () => {
  it('includes a searchable standard color catalog with Indigo', () => {
    expect(NAMED_COLORS.length).toBeGreaterThanOrEqual(140);
    expect(NAMED_COLORS).toContainEqual({ name: 'indigo', code: '#4B0082' });
  });

  it('finds and adds Indigo from the color search', () => {
    const onColorVariantsChange = vi.fn();

    render(
      <ColorVariantManager
        colorVariants={[]}
        onColorVariantsChange={onColorVariantsChange}
      />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Add Color' }));
    fireEvent.change(screen.getByRole('searchbox', { name: 'Search named colors' }), {
      target: { value: 'indigo' },
    });

    const indigoOption = screen.getByRole('button', { name: 'Select Indigo' });
    expect(indigoOption).toBeInTheDocument();

    fireEvent.click(indigoOption);
    expect(screen.getByText('Indigo selected (click Add to use this color)')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Add' }));

    expect(onColorVariantsChange).toHaveBeenCalledWith([
      expect.objectContaining({
        color_name: 'Indigo',
        color_code: '#4B0082',
      }),
    ]);
  });

  it.each([
    'resources/js/Pages/ShopOwner/Products/product management/ProductManagementWithVariants.tsx',
    'resources/js/Pages/ERP/inventory/UploadInventory.tsx',
  ])('keeps %s on the shared color manager', (pagePath) => {
    const source = readFileSync(resolve(pagePath), 'utf8');

    expect(source).toContain('ColorVariantManager');
  });
});
