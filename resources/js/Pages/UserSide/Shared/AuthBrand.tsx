import { Link, router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

const BRAND_CLICK_WINDOW_MS = 700;

export default function AuthBrand() {
  const clickCountRef = useRef(0);
  const navigationTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => () => {
    if (navigationTimeoutRef.current) {
      clearTimeout(navigationTimeoutRef.current);
    }
  }, []);

  const handleBrandClick = (event: React.MouseEvent<HTMLAnchorElement>) => {
    event.preventDefault();
    clickCountRef.current += 1;

    if (navigationTimeoutRef.current) {
      clearTimeout(navigationTimeoutRef.current);
    }

    if (clickCountRef.current === 7) {
      clickCountRef.current = 0;
      navigationTimeoutRef.current = null;
      router.visit(route('admin.login'));
      return;
    }

    navigationTimeoutRef.current = setTimeout(() => {
      clickCountRef.current = 0;
      navigationTimeoutRef.current = null;
      router.visit(route('landing'));
    }, BRAND_CLICK_WINDOW_MS);
  };

  return (
    <div className="absolute inset-x-0 top-0 flex justify-center px-4 pt-8 sm:pt-10" data-testid="auth-brand">
      <Link
        href={route('landing')}
        onClick={handleBrandClick}
        aria-label="SoleSpace"
        className="userside-auth-title text-2xl font-bold tracking-tight text-gray-900 transition-opacity hover:opacity-75 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-4"
      >
        SoleSpace
      </Link>
    </div>
  );
}
