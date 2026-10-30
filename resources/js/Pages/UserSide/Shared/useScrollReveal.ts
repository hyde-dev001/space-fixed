import { useEffect, type RefObject } from 'react';

const REVEAL_SELECTOR = '[data-scroll-reveal]';

const forEachRevealElement = (node: Node, callback: (element: HTMLElement) => void) => {
  if (node instanceof HTMLElement && node.matches(REVEAL_SELECTOR)) {
    callback(node);
  }

  if (node instanceof Element) {
    node.querySelectorAll<HTMLElement>(REVEAL_SELECTOR).forEach(callback);
  }
};

const applyRevealDelay = (element: HTMLElement) => {
  const delay = Number(element.dataset.scrollDelay ?? 0);

  if (Number.isFinite(delay) && delay > 0) {
    element.style.transitionDelay = `${Math.min(600, delay)}ms`;
  }
};

export const useScrollReveal = (rootRef: RefObject<HTMLElement | null>): void => {
  useEffect(() => {
    const root = rootRef.current;
    if (!root) return;

    const prefersReducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
    const reveal = (element: HTMLElement) => {
      applyRevealDelay(element);
      element.classList.add('is-visible');
    };

    if (prefersReducedMotion || typeof IntersectionObserver === 'undefined') {
      root.querySelectorAll<HTMLElement>(REVEAL_SELECTOR).forEach(reveal);

      const mutationObserver = new MutationObserver((records) => {
        records.forEach((record) => record.addedNodes.forEach((node) => forEachRevealElement(node, reveal)));
      });
      mutationObserver.observe(root, { childList: true, subtree: true });

      return () => mutationObserver.disconnect();
    }

    const observer = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (!entry.isIntersecting) return;

          reveal(entry.target as HTMLElement);
          observer.unobserve(entry.target);
        });
      },
      { threshold: 0.16, rootMargin: '0px 0px -10% 0px' },
    );
    const observe = (element: HTMLElement) => {
      if (element.classList.contains('is-visible')) return;

      applyRevealDelay(element);
      observer.observe(element);
    };

    root.querySelectorAll<HTMLElement>(REVEAL_SELECTOR).forEach(observe);

    const mutationObserver = new MutationObserver((records) => {
      records.forEach((record) => record.addedNodes.forEach((node) => forEachRevealElement(node, observe)));
    });
    mutationObserver.observe(root, { childList: true, subtree: true });

    return () => {
      mutationObserver.disconnect();
      observer.disconnect();
    };
  }, [rootRef]);
};
