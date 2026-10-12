import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import IconButton from './IconButton';

describe('IconButton', () => {
  it.each(['neutral', 'primary', 'success', 'warning', 'danger'] as const)(
    'publishes the %s semantic variant as an accessible action',
    (variant) => {
      render(
        <IconButton variant={variant} label="View employee">
          <svg aria-hidden="true" />
        </IconButton>,
      );

      const button = screen.getByRole('button', { name: 'View employee' });

      expect(button).toHaveAttribute('type', 'button');
      expect(button).toHaveAttribute('data-erp-icon-action', 'true');
      expect(button).toHaveAttribute('data-semantic-color', variant);
      expect(button).toHaveAttribute('title', 'View employee');
    },
  );

  it('preserves an explicit title and disabled state', () => {
    render(
      <IconButton
        variant="danger"
        label="Delete campaign"
        title="Delete this campaign"
        disabled
      >
        <svg aria-hidden="true" />
      </IconButton>,
    );

    const button = screen.getByRole('button', { name: 'Delete campaign' });

    expect(button).toHaveAttribute('title', 'Delete this campaign');
    expect(button).toBeDisabled();
  });
});
