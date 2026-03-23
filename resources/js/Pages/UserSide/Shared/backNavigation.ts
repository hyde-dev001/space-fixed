import { router } from '@inertiajs/react';

const isSameOriginReferrer = (): boolean => {
  if (typeof window === 'undefined') return false;
  if (!document.referrer) return false;

  try {
    return new URL(document.referrer).origin === window.location.origin;
  } catch {
    return false;
  }
};

export const navigateBackOr = (fallbackHref: string): void => {
  if (typeof window === 'undefined') {
    router.visit(fallbackHref);
    return;
  }

  if (window.history.length > 1 && isSameOriginReferrer()) {
    window.history.back();
    return;
  }

  router.visit(fallbackHref);
};
