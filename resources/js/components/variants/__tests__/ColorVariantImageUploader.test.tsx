import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ColorVariantImageUploader } from '../ColorVariantImageUploader';
import type { ColorVariantImage } from '../ColorVariantImageUploader';

const existingImage: ColorVariantImage = {
  id: 'image-1',
  file: null,
  preview: 'https://example.test/image.jpg',
  is_thumbnail: false,
  sort_order: 0,
};

describe('ColorVariantImageUploader persistence hooks', () => {
  it('routes thumbnail and remove actions to persistence callbacks when provided', () => {
    const onImagesChange = vi.fn();
    const onSetThumbnail = vi.fn();
    const onRemoveImage = vi.fn();

    render(
      <ColorVariantImageUploader
        colorName="Indigo"
        images={[existingImage]}
        onImagesChange={onImagesChange}
        onSetThumbnail={onSetThumbnail}
        onRemoveImage={onRemoveImage}
      />,
    );

    fireEvent.click(screen.getByTitle('Set as thumbnail'));
    fireEvent.click(screen.getByTitle('Remove image'));

    expect(onSetThumbnail).toHaveBeenCalledWith('image-1');
    expect(onRemoveImage).toHaveBeenCalledWith('image-1');
    expect(onImagesChange).not.toHaveBeenCalled();
  });
});
