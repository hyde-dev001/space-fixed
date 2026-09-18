import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ColorVariantManager } from '../ColorVariantManager';
import type { ColorVariant } from '../ColorVariantManager';

const emptyVariants: ColorVariant[] = [];

describe('ColorVariantManager color picker', () => {
  it('shows named color search before quick select', () => {
    render(
      <ColorVariantManager
        colorVariants={emptyVariants}
        onColorVariantsChange={vi.fn()}
      />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Add Color' }));

    const searchInput = screen.getByRole('searchbox', { name: 'Search named colors' });
    const quickSelectHeading = screen.getByText('Quick Select');

    expect(
      searchInput.compareDocumentPosition(quickSelectHeading) & Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy();
  });

  it('searches and selects a named color', () => {
    const onColorVariantsChange = vi.fn();

    render(
      <ColorVariantManager
        colorVariants={emptyVariants}
        onColorVariantsChange={onColorVariantsChange}
      />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Add Color' }));

    const searchInput = screen.getByRole('searchbox', { name: 'Search named colors' });
    fireEvent.change(searchInput, { target: { value: 'indigo' } });
    fireEvent.click(screen.getByRole('button', { name: 'Select Indigo color' }));

    expect(onColorVariantsChange).toHaveBeenCalledWith([
      expect.objectContaining({
        color_name: 'Indigo',
        color_code: '#4B0082',
      }),
    ]);
  });
});
