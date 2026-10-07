import React, { useEffect, useState } from 'react';
import axios from 'axios';
import RetailWarrantyPanel from '@/components/orders/RetailWarrantyPanel';
import type { RetailWarrantyProjection } from '@/types/retailWarranty';

interface WarrantyPage { data: RetailWarrantyProjection[]; current_page: number; last_page: number; total: number }
const statusLabels: Record<string, string> = { active: 'Active', partially_used: 'Partially Used', no_remaining_coverage: 'No Remaining Coverage', expired: 'Expired', voided: 'Voided' };

export default function RetailWarrantyHistory() {
  const [draft, setDraft] = useState('');
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [reload, setReload] = useState(0);
  const [data, setData] = useState<WarrantyPage | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [detail, setDetail] = useState<number | null>(null);
  const [voidId, setVoidId] = useState<number | null>(null);
  const [reason, setReason] = useState('');
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState('');

  useEffect(() => {
    const controller = new AbortController();
    setLoading(true); setError('');
    axios.get<WarrantyPage>('/api/shop-owner/retail-warranties', { params: { page, search, ...(status ? { status } : {}) }, signal: controller.signal })
      .then(response => { if (!controller.signal.aborted) setData(response.data); })
      .catch(() => { if (!controller.signal.aborted) setError('Could not load issued warranties. Please try again.'); })
      .finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [page, search, status, reload]);

  async function voidCoverage(event: React.FormEvent) {
    event.preventDefault();
    if (!voidId || saving) return;
    setSaving(true); setError(''); setMessage('');
    try {
      await axios.patch(`/api/shop-owner/retail-warranties/items/${voidId}/void`, { reason });
      setVoidId(null); setReason(''); setMessage('Coverage voided. Original certificate and terms are preserved.'); setReload(value => value + 1);
    } catch { setError('Could not void coverage. A legitimate reason is required; try again.'); }
    finally { setSaving(false); }
  }

  return <section className="border-b border-gray-200 p-6" aria-labelledby="issued-warranties-heading">
    <h2 id="issued-warranties-heading" className="text-xl font-semibold text-gray-900">Issued Warranties</h2>
    <p className="mt-1 text-sm text-gray-600">Search original certificates and inspect current item coverage. Historical terms cannot be edited.</p>
    <form onSubmit={event => { event.preventDefault(); setSearch(draft); setPage(1); setReload(value => value + 1); }} className="mt-4 flex flex-wrap items-end gap-3">
      <label className="flex-1 text-sm font-medium">Search warranties<input value={draft} maxLength={120} onChange={event => setDraft(event.target.value)} placeholder="Reference, order or customer" className="mt-1 min-h-11 w-full rounded-lg border border-gray-300 px-3 py-2 focus:outline-none focus:ring-1 focus:ring-black" /></label>
      <label className="text-sm font-medium">Warranty status<select value={status} onChange={event => { setStatus(event.target.value); setPage(1); }} className="mt-1 block min-h-11 rounded-lg border border-gray-300 bg-white px-3 py-2"><option value="">All statuses</option>{Object.entries(statusLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label>
      <button className="min-h-11 rounded-lg bg-black px-4 py-2 text-sm font-medium text-white">Search</button>
    </form>
    {error ? <div role="alert" className="mt-4 text-sm">{error} <button type="button" className="ml-2 min-h-11 underline" onClick={() => setReload(value => value + 1)}>Retry</button></div> : null}
    {message ? <p role="status" className="mt-4 text-sm">{message}</p> : null}
    {loading ? <p role="status" className="mt-4 text-sm text-gray-600">Loading issued warranties…</p> : data?.data.length ? <div className="mt-4 space-y-3">
      {data.data.map(warranty => <article key={warranty.id} className="rounded-xl border border-gray-200 bg-white p-4">
        <div className="flex flex-wrap justify-between gap-3"><div><p className="break-all text-sm font-semibold">{warranty.reference}</p><p className="mt-1 text-sm text-gray-600">Order {warranty.order_number} · {warranty.customer_name}</p><p className="mt-1 text-xs text-gray-500">{new Date(warranty.issued_at).toLocaleString()} · {warranty.items.length} covered lines · {statusLabels[warranty.status] ?? warranty.status}</p></div>
          <button type="button" aria-expanded={detail === warranty.id} className="min-h-11 rounded-lg border border-gray-300 px-3 py-2 text-sm" onClick={() => { setDetail(detail === warranty.id ? null : warranty.id); setVoidId(null); }}>{detail === warranty.id ? 'Hide details' : 'View details'}</button></div>
        {detail === warranty.id ? <div className="mt-4"><RetailWarrantyPanel warranty={warranty} />
          <div className="mt-3 flex flex-wrap gap-2">{warranty.items.filter(item => item.status !== 'voided').map(item => <button key={item.id} type="button" className="min-h-11 rounded-lg border border-gray-300 px-3 text-sm" onClick={() => { setVoidId(item.id); setReason(''); }}>Void coverage: {item.product_name}</button>)}</div>
          {voidId && warranty.items.some(item => item.id === voidId) ? <form onSubmit={voidCoverage} className="mt-4 space-y-3 rounded-lg border border-gray-300 p-4"><p className="text-sm font-medium">Confirm administrative void. This affects remaining item coverage and preserves the original record.</p><label className="block text-sm font-medium">Legitimate void reason<textarea required minLength={3} maxLength={1000} autoFocus rows={3} value={reason} onChange={event => setReason(event.target.value)} className="mt-1 w-full rounded-lg border border-gray-300 p-2" /></label><div className="flex gap-3"><button disabled={saving} className="min-h-11 rounded-lg bg-black px-4 text-sm text-white disabled:opacity-50">{saving ? 'Saving…' : 'Confirm void'}</button><button type="button" disabled={saving} onClick={() => setVoidId(null)} className="min-h-11 px-3 text-sm underline">Cancel</button></div></form> : null}
        </div> : null}
      </article>)}
      <nav aria-label="Warranty pagination" className="flex flex-wrap items-center justify-between gap-3 pt-3 text-sm"><span>{data.total} results · Page {data.current_page} of {data.last_page}</span><div className="flex gap-2"><button type="button" disabled={page <= 1} onClick={() => setPage(value => value - 1)} className="min-h-11 rounded-lg border border-gray-300 px-3 disabled:opacity-40">Previous</button><button type="button" disabled={page >= data.last_page} onClick={() => setPage(value => value + 1)} className="min-h-11 rounded-lg border border-gray-300 px-3 disabled:opacity-40">Next</button></div></nav>
    </div> : !error ? <p className="mt-4 text-sm text-gray-600">No issued warranties match your search.</p> : null}
  </section>;
}
