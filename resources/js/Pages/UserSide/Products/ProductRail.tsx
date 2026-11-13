import { Link } from '@inertiajs/react';

import type { ProductRailItem } from './productHistory';

type ProductRailProps = {
  title: string;
  items: ProductRailItem[];
};

const ProductRail = ({ title, items }: ProductRailProps) => {
  if (items.length === 0) {
    return null;
  }

  return (
    <section className="hidden xl:block" aria-label={title}>
      <div className="mb-6 flex items-end justify-between border-b border-black/15 pb-4">
        <h2 className="text-sm font-semibold uppercase tracking-[0.18em] text-black">{title}</h2>
        <span className="text-xs text-black/55">{items.length} items</span>
      </div>

      <div className="grid gap-x-6 gap-y-10 xl:grid-cols-4">
        {items.map((item) => (
          <Link key={item.id} href={item.url} className="group block focus-visible:outline-none">
            <div className="aspect-square overflow-hidden bg-[#f5f5f5] ring-black group-focus-visible:ring-2 group-focus-visible:ring-offset-2">
              {item.image ? (
                <img
                  src={item.image}
                  alt={item.name}
                  loading="lazy"
                  className="h-full w-full object-contain transition-transform duration-300 group-hover:scale-[1.02] motion-reduce:transition-none"
                />
              ) : (
                <div className="flex h-full items-center justify-center px-6 text-center text-xs uppercase tracking-[0.14em] text-black/40">
                  Image unavailable
                </div>
              )}
            </div>

            <div className="pt-4">
              {(item.brand || item.category) && (
                <p className="mb-1 text-[11px] uppercase tracking-[0.14em] text-black/55">
                  {item.brand || item.category}
                </p>
              )}
              <h3 className="line-clamp-2 text-sm font-medium leading-5 text-black">{item.name}</h3>
              <div className="mt-2 flex items-center gap-2 text-sm">
                <span className="font-medium text-black">{item.price}</span>
                {item.compare_at_price && (
                  <span className="text-black/45 line-through">{item.compare_at_price}</span>
                )}
              </div>
            </div>
          </Link>
        ))}
      </div>
    </section>
  );
};

export default ProductRail;
