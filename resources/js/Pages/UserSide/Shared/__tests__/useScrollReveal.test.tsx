import { act, render, screen, waitFor } from '@testing-library/react';
import { useRef } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { useScrollReveal } from '../useScrollReveal';

type ObserverInstance = {
  callback: IntersectionObserverCallback;
  disconnect: ReturnType<typeof vi.fn>;
  observe: ReturnType<typeof vi.fn>;
  unobserve: ReturnType<typeof vi.fn>;
};

const observerInstances: ObserverInstance[] = [];

const Harness = () => {
  const rootRef = useRef<HTMLDivElement | null>(null);
  useScrollReveal(rootRef);

  return (
    <div ref={rootRef} data-testid="root">
      <section data-scroll-reveal className="scroll-reveal" data-testid="first" />
      <section data-scroll-reveal data-scroll-delay="120" className="scroll-reveal" data-testid="second" />
    </div>
  );
};

describe('useScrollReveal', () => {
  beforeEach(() => {
    observerInstances.length = 0;
    vi.stubGlobal('matchMedia', vi.fn().mockReturnValue({ matches: false }));
    vi.stubGlobal(
      'IntersectionObserver',
      class {
        readonly root = null;
        readonly rootMargin = '0px 0px -10% 0px';
        readonly thresholds = [0.16];
        readonly disconnect = vi.fn();
        readonly observe = vi.fn();
        readonly takeRecords = vi.fn(() => []);
        readonly unobserve = vi.fn();

        constructor(callback: IntersectionObserverCallback) {
          observerInstances.push({
            callback,
            disconnect: this.disconnect,
            observe: this.observe,
            unobserve: this.unobserve,
          });
        }
      },
    );
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('reveals intersecting elements once and cleans up its observers', () => {
    const { unmount } = render(<Harness />);
    const first = screen.getByTestId('first');
    const second = screen.getByTestId('second');
    const observer = observerInstances[0];

    expect(observer.observe).toHaveBeenCalledWith(first);
    expect(observer.observe).toHaveBeenCalledWith(second);
    expect(second.style.transitionDelay).toBe('120ms');
    expect(first).not.toHaveClass('is-visible');

    act(() => {
      observer.callback(
        [{ isIntersecting: true, target: first } as IntersectionObserverEntry],
        observer as unknown as IntersectionObserver,
      );
    });

    expect(first).toHaveClass('is-visible');
    expect(observer.unobserve).toHaveBeenCalledWith(first);

    unmount();
    expect(observer.disconnect).toHaveBeenCalledOnce();
  });

  it('shows content immediately when reduced motion is preferred', () => {
    vi.stubGlobal('matchMedia', vi.fn().mockReturnValue({ matches: true }));

    render(<Harness />);

    expect(screen.getByTestId('first')).toHaveClass('is-visible');
    expect(screen.getByTestId('second')).toHaveClass('is-visible');
    expect(observerInstances).toHaveLength(0);
  });

  it('shows current and newly inserted content when IntersectionObserver is unavailable', async () => {
    vi.stubGlobal('IntersectionObserver', undefined);

    render(<Harness />);
    const dynamicElement = document.createElement('article');
    dynamicElement.dataset.scrollReveal = '';
    dynamicElement.className = 'scroll-reveal';

    expect(screen.getByTestId('first')).toHaveClass('is-visible');

    act(() => {
      screen.getByTestId('root').append(dynamicElement);
    });

    await waitFor(() => expect(dynamicElement).toHaveClass('is-visible'));
  });

  it('observes marked content inserted after the initial render', async () => {
    render(<Harness />);
    const observer = observerInstances[0];
    const dynamicElement = document.createElement('article');
    dynamicElement.dataset.scrollReveal = '';
    dynamicElement.className = 'scroll-reveal';

    act(() => {
      screen.getByTestId('root').append(dynamicElement);
    });

    await waitFor(() => expect(observer.observe).toHaveBeenCalledWith(dynamicElement));
  });
});
