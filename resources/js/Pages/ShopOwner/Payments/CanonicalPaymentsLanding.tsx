import { Link } from '@inertiajs/react';

import AppLayoutShopOwner from '../../../layout/AppLayout_shopOwner';

type PaymentLinks = {
  retail: string | null;
  repair: string | null;
};

type CanonicalPaymentsLandingProps = {
  links: PaymentLinks;
};

const CanonicalPaymentsLanding = ({ links }: CanonicalPaymentsLandingProps) => {
  const destinations = [
    { key: 'retail', label: 'Retail Point of Sale', url: links.retail },
    { key: 'repair', label: 'Repair Point of Sale', url: links.repair },
  ].filter((destination): destination is { key: string; label: string; url: string } => destination.url !== null);

  return (
    <AppLayoutShopOwner>
      <section className="mx-auto max-w-4xl space-y-8" aria-labelledby="canonical-payments-title">
        <header className="space-y-2">
          <p className="text-xs font-semibold uppercase tracking-[0.18em] text-blue-600 dark:text-blue-300">Operate</p>
          <h1 id="canonical-payments-title" className="text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">
            Payments
          </h1>
          <p className="text-sm leading-6 text-gray-600 dark:text-gray-300">
            Open the payment workspace for an authorized retail or repair operation.
          </p>
        </header>

        {destinations.length > 0 ? (
          <nav aria-label="Payment workspaces" className="grid gap-4 sm:grid-cols-2">
            {destinations.map((destination) => (
              <Link
                key={destination.key}
                href={destination.url}
                className="rounded-2xl border border-gray-200 bg-white p-5 text-sm font-semibold text-gray-900 shadow-theme-xs transition hover:border-blue-300 focus:outline-none focus:ring-2 focus:ring-blue-500/40 dark:border-gray-800 dark:bg-gray-900 dark:text-white"
              >
                {destination.label}
              </Link>
            ))}
          </nav>
        ) : (
          <p className="rounded-2xl border border-dashed border-gray-300 p-5 text-sm text-gray-600 dark:border-gray-700 dark:text-gray-300">
            No authorized payment workspace is available for this shop.
          </p>
        )}
      </section>
    </AppLayoutShopOwner>
  );
};

export default CanonicalPaymentsLanding;
