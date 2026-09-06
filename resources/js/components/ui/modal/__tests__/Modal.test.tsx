import React from 'react';
import { cleanup, render } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { Modal } from '../index';

afterEach(() => {
  cleanup();
  document.body.style.overflow = '';
  document.body.style.paddingRight = '';
});

describe('Modal body scroll handling', () => {
  it('preserves the scrollbar gap while open and restores body styles on close', () => {
    Object.defineProperty(window, 'innerWidth', { configurable: true, value: 1000 });
    Object.defineProperty(document.documentElement, 'clientWidth', { configurable: true, value: 980 });

    const { unmount } = render(
      <Modal isOpen onClose={() => undefined}>
        <p>Modal content</p>
      </Modal>,
    );

    expect(document.body.style.overflow).toBe('hidden');
    expect(document.body.style.paddingRight).toBe('20px');

    unmount();

    expect(document.body.style.overflow).toBe('');
    expect(document.body.style.paddingRight).toBe('');
  });
});
