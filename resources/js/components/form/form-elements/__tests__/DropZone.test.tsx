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

describe('DropzoneComponent layout', () => {
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

  it('keeps the standard card height natural for following metadata fields', () => {
    render(<DropzoneComponent isUploaded fileName="permit.jpg" />);

    const dropzoneRoot = screen.getByTestId('dropzone-root');

    expect(dropzoneRoot).not.toHaveClass('h-full');
    expect(dropzoneRoot.parentElement).not.toHaveClass('h-full');
  });

  it('keeps an existing resubmission file in the neutral upload layout', () => {
    const { container } = render(
      <DropzoneComponent
        onDrop={vi.fn()}
        isUploaded
        isExistingFile
        fileName="existing-document.png"
        previewUrl="/documents/existing-document.png"
      />,
    );

    expect(screen.getByText('Drag & Drop Documents Here')).toBeInTheDocument();
    expect(screen.queryByRole('img')).not.toBeInTheDocument();
    expect(screen.queryByText('File Uploaded Successfully!')).not.toBeInTheDocument();
    expect(screen.getByText('Browse File')).toBeInTheDocument();
    expect(screen.queryByText('Change File')).not.toBeInTheDocument();
    expect(container.querySelector('.border-green-500')).toBeNull();
  });
});

describe('document upload preview', () => {
  it('constrains long filenames inside the upload card', () => {
    const fileName = `${'A'.repeat(240)}.png`;
    const { container } = render(<DropzoneComponent isUploaded fileName={fileName} />);
    expect(screen.getByText(fileName)).toHaveClass('truncate', 'max-w-full');
    expect(container.firstElementChild).toHaveClass('min-w-0', 'overflow-hidden');
  });

  it.each(['data:image/png;base64,AAA', 'blob:http://localhost/internal-image-id'])('does not render an image URL as a filename: %s', (fileName) => {
    render(<DropzoneComponent isUploaded fileName={fileName} />);
    expect(screen.queryByText(fileName)).not.toBeInTheDocument();
    expect(screen.getByText('Selected document')).toBeInTheDocument();
  });

  it('shows the selected document uncropped with a file-selection label when requested', () => {
    render(<DropzoneComponent isUploaded uploadedLabel="File selected" previewFit="contain" fileName="permit.png" previewUrl="blob:original-permit" previewAlt="Mayor's Permit preview" />);
    expect(screen.getByText('File selected')).toBeInTheDocument();
    expect(screen.getByRole('img', { name: "Mayor's Permit preview" })).toHaveClass('object-contain');
    expect(screen.getByRole('img')).toHaveAttribute('src', 'blob:original-permit');
  });
});
