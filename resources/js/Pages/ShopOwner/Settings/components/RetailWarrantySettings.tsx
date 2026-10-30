import React, { useState } from 'react';
import axios from 'axios';
import type { RetailWarrantySettingsPayload, WarrantyDurationUnit } from '@/types/retailWarranty';

const units: WarrantyDurationUnit[] = ['days', 'weeks', 'months', 'years'];
const textFields = [
  ['description', 'Description'], ['terms', 'Terms and Conditions'], ['exclusions', 'Exclusions'], ['instructions', 'Customer Instructions'],
] as const;
const inputClass = 'mt-1 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-black focus:outline-none focus:ring-1 focus:ring-black';

export default function RetailWarrantySettings({ initial }: { initial: RetailWarrantySettingsPayload }) {
  const [form, setForm] = useState(initial);
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState(false);
  const [error, setError] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  async function save(event: React.FormEvent) {
    event.preventDefault();
    setSaving(true); setSaved(false); setError(''); setErrors({});
    try {
      await axios.put('/shop-owner/settings/retail-warranty', form);
      setSaved(true);
    } catch (failure) {
      setError('Could not save warranty settings. Review the fields and try again.');
      if (axios.isAxiosError<{ errors?: Record<string, string[]> }>(failure)) {
        setErrors(failure.response?.data.errors ?? {});
      }
    } finally { setSaving(false); }
  }

  return (
    <section aria-labelledby="retail-warranty-heading" className="border-b border-gray-200 p-6">
      <h2 id="retail-warranty-heading" className="text-xl font-semibold text-gray-900">Retail Product Warranty</h2>
      <p className="mt-1 text-sm text-gray-600">Define coverage for future eligible purchases. Issued certificates keep their original terms. Warranty allows assessment; refunds still require inspection and approval.</p>
      <form onSubmit={save} className="mt-5 space-y-4">
        <label className="flex min-h-11 items-center gap-3 text-sm font-medium"><input type="checkbox" checked={form.enabled} onChange={e => setForm({ ...form, enabled: e.target.checked })} className="h-4 w-4 accent-black" />Enable Retail Product Warranty</label>
        <label className="block text-sm font-medium">Warranty Title<input className={inputClass} required={form.enabled} maxLength={160} value={form.title ?? ''} onChange={e => setForm({ ...form, title: e.target.value })} /><span className="text-sm" role={errors.title ? 'alert' : undefined}>{errors.title?.[0]}</span></label>
        <div className="grid gap-4 sm:grid-cols-2">
          <label className="block text-sm font-medium">Duration value<input className={inputClass} type="number" min={1} step={1} required value={form.duration_value} onChange={e => setForm({ ...form, duration_value: Number(e.target.value) })} /><span className="text-sm" role={errors.duration_value ? 'alert' : undefined}>{errors.duration_value?.[0]}</span></label>
          <label className="block text-sm font-medium">Duration unit<select className={inputClass} value={form.duration_unit} onChange={e => { const unit = units.find(value => value === e.target.value); if (unit) setForm({ ...form, duration_unit: unit }); }}>{units.map(unit => <option key={unit} value={unit}>{unit[0].toUpperCase() + unit.slice(1)}</option>)}</select></label>
        </div>
        {textFields.map(([key, label]) => <label key={key} className="block text-sm font-medium">{label}<textarea className={inputClass} rows={key === 'terms' ? 5 : 3} required={key === 'terms' && form.enabled} maxLength={key === 'terms' ? 20000 : key === 'description' ? 5000 : 10000} value={form[key] ?? ''} onChange={e => setForm({ ...form, [key]: e.target.value })} /><span className="text-sm" role={errors[key] ? 'alert' : undefined}>{errors[key]?.[0]}</span></label>)}
        {form.eligible_orders_from ? <p className="text-xs text-gray-500">Eligible purchase boundary: {new Date(form.eligible_orders_from).toLocaleString()}. Earlier purchases stay uncovered.</p> : null}
        {error ? <p role="alert" className="text-sm text-gray-900">{error}</p> : null}
        {saved ? <p role="status" className="text-sm text-gray-900">Warranty settings saved.</p> : null}
        <button type="submit" disabled={saving} className="min-h-11 rounded-lg bg-black px-4 py-2 text-sm font-medium text-white disabled:opacity-50">{saving ? 'Saving…' : 'Save warranty settings'}</button>
      </form>
    </section>
  );
}
