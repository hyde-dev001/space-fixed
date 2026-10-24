import React from 'react';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
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

  it('renders the ERP modal backdrop hook while open', () => {
    render(
      <Modal isOpen onClose={() => undefined}>
        <p>Modal content</p>
      </Modal>,
    );

    const backdrop = document.querySelector('.erp-modal-backdrop');

    expect(backdrop).toBeInTheDocument();
    expect(backdrop).toHaveClass('bg-black/10', 'backdrop-blur-sm');
  });
});


describe('opt-in accessible modal', () => {
  it('contains keyboard focus, makes background inert, and restores the trigger', () => {
    const trigger=document.createElement('button');document.body.append(trigger);trigger.focus();
    const close=vi.fn();
    const { unmount }=render(<Modal isOpen ariaLabel="Confirm refund" onClose={close} showCloseButton={false}><button>Cancel</button><button>Confirm</button></Modal>);
    const dialog=screen.getByRole('dialog', { name:'Confirm refund' });
    expect(trigger).toHaveAttribute('inert');
    expect(screen.getByRole('button',{name:'Cancel'})).toHaveFocus();
    screen.getByRole('button',{name:'Confirm'}).focus();fireEvent.keyDown(dialog,{key:'Tab'});
    expect(screen.getByRole('button',{name:'Cancel'})).toHaveFocus();
    fireEvent.keyDown(dialog,{key:'Escape'});expect(close).toHaveBeenCalledOnce();
    unmount();expect(trigger).not.toHaveAttribute('inert');expect(trigger).toHaveFocus();trigger.remove();
  });
});

it('restores scrolling after a nested details/execution pair unmounts together', () => {
 const {rerender,unmount}=render(<><Modal isOpen onClose={()=>undefined}><button>Details</button></Modal></>);
 rerender(<><Modal isOpen onClose={()=>undefined}><button>Details</button></Modal><Modal isOpen onClose={()=>undefined}><button>Execution</button></Modal></>);
 unmount();expect(document.body.style.overflow).toBe('');
});
