import React from 'react';
import type { RetailWarrantyProjection } from '@/types/retailWarranty';

const statusLabels: Record<string, string> = { active: 'Active', partially_used: 'Partially Used', no_remaining_coverage: 'No Remaining Coverage', expired: 'Expired', voided: 'Voided' };

export default function RetailWarrantyPanel({ warranty, onAssess, shopAssisted = false, disabled = false }: {
  warranty?: RetailWarrantyProjection | null; onAssess?: () => void; shopAssisted?: boolean; disabled?: boolean;
}) {
  if (!warranty) return null;
  const date = (value: string) => new Date(value).toLocaleString(undefined, { timeZone: warranty.timezone });
  const canAssess = warranty.items.some(item => item.can_assess);
  return (
    <section aria-label="Product Warranty" className="rounded-xl border border-gray-200 bg-white p-4 text-gray-900 sm:p-5">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div><h3 className="text-base font-semibold">Product Warranty</h3><p className="mt-1 break-all text-sm font-medium">{warranty.reference}</p><p className="mt-1 text-xs text-gray-600">Issued {date(warranty.issued_at)} · {statusLabels[warranty.status] ?? 'Status unavailable'}</p></div>
        <a href={warranty.download_url} className="inline-flex min-h-11 items-center rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium focus:outline-none focus:ring-2 focus:ring-black">Download Warranty PDF</a>
      </div>
      <div className="mt-4 space-y-4">
        {warranty.items.map(item => <div key={item.id} className="border-t border-gray-100 pt-3">
          <h4 className="text-sm font-semibold">{item.product_name}</h4>
          <p className="text-xs text-gray-600">Size: {item.size ?? '—'} · Color: {item.color ?? '—'}</p>
          <p className="mt-1 text-sm">Qty Covered: {item.covered_quantity} · Qty Remaining: {item.remaining_quantity}{item.reserved_quantity > 0 ? ` · Under Assessment: ${item.reserved_quantity}` : ''}</p>
          <p className="mt-1 text-xs text-gray-600">{statusLabels[item.status] ?? 'Status unavailable'} · {item.policy.duration_value} {item.policy.duration_unit} · {date(item.start_date)} to {date(item.expiration_date)}</p>
          {item.void_reason ? <p className="mt-1 text-sm">Void reason: {item.void_reason}</p> : null}
          <details className="mt-2 text-sm"><summary className="cursor-pointer py-2 font-medium focus:outline-none focus:ring-2 focus:ring-black">Original terms and instructions</summary>
            <p className="mt-2 whitespace-pre-wrap">{item.policy.description}</p><p className="mt-2 whitespace-pre-wrap">{item.policy.terms}</p>
            <p className="mt-2 whitespace-pre-wrap"><strong>Exclusions: </strong>{item.policy.exclusions || 'None specified.'}</p>
            <p className="mt-2 whitespace-pre-wrap"><strong>Instructions: </strong>{item.policy.instructions || 'Present the certificate to the shop for assessment.'}</p>
          </details>
        </div>)}
      </div>
      {canAssess && onAssess && !shopAssisted ? <button type="button" disabled={disabled} onClick={onAssess} className="mt-4 min-h-11 rounded-lg bg-black px-4 py-2 text-sm font-medium text-white disabled:opacity-50">Request Warranty Assessment</button> : null}
      {canAssess && shopAssisted ? <p className="mt-4 text-sm">Present your certificate at the shop for warranty assessment of this POS purchase.</p> : null}
      <p className="mt-3 text-xs text-gray-500">The certificate records original coverage. Live status and remaining quantities reflect later refunds or voiding. Assessment follows the shop's terms and existing inspection and approval process.</p>
    </section>
  );
}
