import React from 'react';
import { Package } from 'lucide-react';
import type { LogisticsOrderSummary } from '@/types/logistics';

type Props = {
  summary?: LogisticsOrderSummary | null;
  expanded?: boolean;
  instructions?: string | null;
};

const imageUrl = (path?: string | null) => {
  if (!path) return null;
  if (/^(https?:|data:|\/)/i.test(path)) return path;
  return path.startsWith('storage/') ? `/${path}` : `/storage/${path}`;
};

const ProductImage = ({ path, alt }: { path?: string | null; alt: string }) => {
  const src = imageUrl(path);
  return src
    ? <img src={src} alt={alt} className="h-12 w-12 shrink-0 rounded-lg border border-gray-200 object-cover" />
    : <span aria-label="No product image" className="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-400"><Package size={20} /></span>;
};

export default function RetailOrderSummary({ summary, expanded = false, instructions }: Props) {
  if (!summary) return null;
  if (!summary.available) return <p className="text-sm font-medium text-amber-700">Order details unavailable</p>;

  if (expanded) {
    return <section aria-label="Order items" className="space-y-2">
      <h3 className="font-semibold text-gray-950 dark:text-white">Order items</h3>
      {summary.items.map((item) => (
        <div key={item.id} className="flex items-center gap-3 rounded-xl border border-gray-200 p-3 dark:border-gray-700">
          <ProductImage path={item.image} alt={item.model} />
          <div className="min-w-0 flex-1">
            <p className="font-semibold text-gray-950 dark:text-white">{[item.brand, item.model].filter(Boolean).join(' ')}</p>
            <p className="text-sm text-gray-600 dark:text-gray-300">
              {[item.color, item.size ? `Size ${item.size}` : null, `Qty ${item.quantity}`].filter(Boolean).join(' · ')}
            </p>
          </div>
        </div>
      ))}
    </section>;
  }

  const first = summary.items[0];
  if (!first) return <p className="text-sm text-gray-500">No order items recorded</p>;
  const more = Math.max(0, summary.model_count - 1);

  return <div className="flex min-w-0 items-center gap-3">
    <ProductImage path={first.image} alt={first.model} />
    <div className="min-w-0">
      <p className="truncate text-sm font-semibold text-gray-950 dark:text-white">{[first.brand, first.model].filter(Boolean).join(' ')}</p>
      <p className="text-xs text-gray-500">
        {summary.total_quantity} {summary.total_quantity === 1 ? 'pair' : 'pairs'} · {summary.variant_count} {summary.variant_count === 1 ? 'variant' : 'variants'}
        {more > 0 ? ` · +${more} more` : ''}
      </p>
      {instructions && <p className="text-xs font-medium text-blue-700">Delivery instructions</p>}
    </div>
  </div>;
}
