import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('react-dropzone', () => ({
  useDropzone: () => ({
    getRootProps: () => ({ 'data-testid': 'dropzone-root' }),
    getInputProps: () => ({ 'aria-label': 'ID file', type: 'file' }),
    isDragActive: false,
  }),
}));

import DropzoneComponent from '../DropZone';

describe('DropzoneComponent compact layout', () => {
  it('keeps compact card height natural so following status content cannot overlap it', () => {
    render(
      <DropzoneComponent
        compact
        isUploaded
        fileName="front-id.jpg"
        previewUrl="blob:front-id"
      />,
    );

    const dropzoneRoot = screen.getByTestId('dropzone-root');

    expect(dropzoneRoot).not.toHaveClass('h-full');
    expect(dropzoneRoot.parentElement).not.toHaveClass('h-full');
  });
});
