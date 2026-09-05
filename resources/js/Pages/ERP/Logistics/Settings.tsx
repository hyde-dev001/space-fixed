import React, { FormEvent, useState } from 'react';
import { Head, usePage } from '@inertiajs/react';
import axios from 'axios';
import AppLayoutERP from '@/layout/AppLayout_ERP';
import { workflowFeedback } from '@/utils/workflowFeedback';
import DeliveryCoverageMap from './DeliveryCoverageMap';
import { formatTimeDisplay, TimePickerModal } from './components/TimePickerModal';

type Settings = {
  operating_days: number[]; cutoff_time: string; blackout_dates: string[];
  lead_time_days: number; morning_start: string; morning_end: string;
  afternoon_start: string; afternoon_end: string; coverage_radius_km: string | number;
  arrival_radius_m: number; daily_rider_capacity: number; max_delivery_attempts: number;
};

type ShopLocation = {
  latitude: number | null;
  longitude: number | null;
  address: string | null;
};

const TIME_KEYS = ['cutoff_time', 'morning_start', 'morning_end', 'afternoon_start', 'afternoon_end'] as const;
type TimeKey = (typeof TIME_KEYS)[number];

const TIME_LABELS: Record<TimeKey, string> = {
  cutoff_time: 'Cutoff',
  morning_start: 'Morning start',
  morning_end: 'Morning end',
  afternoon_start: 'Afternoon start',
  afternoon_end: 'Afternoon end',
};

const normalizeTimes = (settings: Settings): Settings => ({
  ...settings,
  cutoff_time: settings.cutoff_time.slice(0, 5),
  morning_start: settings.morning_start.slice(0, 5),
  morning_end: settings.morning_end.slice(0, 5),
  afternoon_start: settings.afternoon_start.slice(0, 5),
  afternoon_end: settings.afternoon_end.slice(0, 5),
});

const now = new Date();
const today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;

export default function LogisticsSettings() {
  const { settings: initial, shopLocation } = usePage<{ settings: Settings; shopLocation: ShopLocation }>().props;
  const [form, setForm] = useState<Settings>(() => normalizeTimes(initial));
  const [baseline, setBaseline] = useState<Settings>(() => normalizeTimes(initial));
  const [saving, setSaving] = useState(false);
  const [blackout, setBlackout] = useState('');
  const [timePickerKey, setTimePickerKey] = useState<TimeKey | null>(null);
  const changed = blackout !== '' || JSON.stringify(form) !== JSON.stringify(baseline);
  const set = (key: keyof Settings, value: Settings[keyof Settings]) => setForm({ ...form, [key]: value });
  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setSaving(true);
    try {
      await axios.put('/api/logistics/settings', form);
      setBaseline(form);
      await workflowFeedback.success({ title: 'Settings saved', text: 'Logistics settings were updated successfully.' });
    } catch (error) {
      const data = (error as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } }).response?.data;
      const message = data?.errors ? Object.values(data.errors).flat().join('\n') : data?.message || 'Unable to save logistics settings. Please try again.';
      await workflowFeedback.error(message, data?.errors ? 'Check your settings' : 'Save failed');
    } finally {
      setSaving(false);
    }
  };
  const discard = async () => {
    const result = await workflowFeedback.confirm({
      title: 'Discard changes?',
      text: 'Your unsaved logistics settings will be lost.',
      confirmButtonText: 'Discard',
    });
    if (!result.isConfirmed) return;
    setForm(baseline);
    setBlackout('');
  };

  const renderTimeField = (key: TimeKey) => (
    <label key={key} className="block">
      <span className="mb-1 block">{TIME_LABELS[key]}</span>
      <button
        type="button"
        aria-haspopup="dialog"
        aria-expanded={timePickerKey === key}
        aria-label={`${TIME_LABELS[key]}, ${formatTimeDisplay(form[key])}`}
        className="flex min-h-11 w-full items-center justify-between rounded-xl border border-gray-300 bg-white px-3 text-left font-medium text-gray-800 transition-colors duration-150 hover:border-gray-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
        onClick={() => setTimePickerKey(key)}
      >
        <span>{formatTimeDisplay(form[key])}</span>
        <svg aria-hidden="true" className="h-5 w-5 text-gray-500 dark:text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8">
          <circle cx="12" cy="12" r="8.5" />
          <path d="M12 7v5l3 2" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
      </button>
    </label>
  );

  return <AppLayoutERP>
    <Head title="Logistics Settings" />
    <form onSubmit={submit} className="mx-auto w-full max-w-3xl space-y-5 p-4 sm:p-5 md:p-6">
      <h1 className="text-2xl font-bold">Logistics Settings</h1>
      <fieldset><legend className="font-semibold">Operating days</legend><div className="flex flex-wrap gap-3">{['Mon','Tue','Wed','Thu','Fri','Sat','Sun'].map((day, index) => <label key={day}><input type="checkbox" checked={form.operating_days.includes(index + 1)} onChange={(e) => set('operating_days', e.target.checked ? [...form.operating_days, index + 1].sort() : form.operating_days.filter((value) => value !== index + 1))} /> {day}</label>)}</div></fieldset>
      <div className="grid gap-4 sm:grid-cols-2">
        {renderTimeField('cutoff_time')}
        <label>Lead days<input className="block w-full rounded border p-2" type="number" min="0" value={form.lead_time_days} onChange={(e) => set('lead_time_days', Number(e.target.value))} /></label>
        {(['morning_start', 'morning_end', 'afternoon_start', 'afternoon_end'] as const).map(renderTimeField)}
        <div>
          <label>Daily delivery stops per rider<input aria-describedby="daily-rider-capacity-help" className="block w-full rounded border p-2" type="number" min="1" value={form.daily_rider_capacity} onChange={(e) => set('daily_rider_capacity', Number(e.target.value))} /></label>
          <p id="daily-rider-capacity-help" className="text-sm text-gray-500">One delivery address counts as one stop, regardless of item quantity.</p>
        </div>
        <label>Maximum attempts<input className="block w-full rounded border p-2" type="number" min="1" value={form.max_delivery_attempts} onChange={(e) => set('max_delivery_attempts', Number(e.target.value))} /></label>
      </div>
      <section className="space-y-4 rounded-xl border p-4">
        <div>
          <h2 className="font-semibold">Delivery service area</h2>
          <p className="text-sm text-gray-500">Set how far delivery and pickup addresses may be from your saved shop pin.</p>
        </div>
        <label>Coverage radius (km)<input className="mt-1 block min-h-11 w-full rounded border p-2" type="number" min="0.1" step="0.1" value={form.coverage_radius_km} onChange={(e) => set('coverage_radius_km', e.target.value)} /></label>
        <p className="text-sm text-gray-600">Addresses within {form.coverage_radius_km} km of the saved shop pin qualify.</p>
        {shopLocation.latitude !== null && shopLocation.longitude !== null
          ? <DeliveryCoverageMap latitude={shopLocation.latitude} longitude={shopLocation.longitude} radiusKm={Number(form.coverage_radius_km)} />
          : <a className="inline-flex min-h-11 items-center font-medium text-blue-700 underline" href="/shop-owner/settings">Set the shop location in Shop Settings</a>}
        <div>
          <label>Arrival check radius (metres)<input aria-describedby="arrival-radius-help" className="mt-1 block min-h-11 w-full rounded border p-2" type="number" min="50" max="500" step="1" value={form.arrival_radius_m} onChange={(e) => set('arrival_radius_m', Number(e.target.value))} /></label>
          <p id="arrival-radius-help" className="text-sm text-gray-500">Used when riders tap I've arrived at customer locations.</p>
        </div>
      </section>
      <fieldset className="space-y-3 rounded-2xl border border-gray-200 p-4 dark:border-gray-700">
        <legend className="font-semibold">Blackout dates</legend>
        <div className="flex w-full items-center gap-2">
          <input aria-label="Blackout date" className="min-h-11 min-w-0 flex-1 rounded-xl border border-gray-300 bg-white px-3 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100" type="date" min={today} value={blackout} onChange={(e) => setBlackout(e.target.value)} />
          <button type="button" disabled={!blackout || blackout < today} className="min-h-11 shrink-0 rounded-xl border border-gray-300 px-4 font-semibold text-gray-700 transition-colors hover:border-gray-500 hover:text-gray-950 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:text-gray-300 dark:hover:border-gray-500 dark:hover:text-white" onClick={() => {
            if (blackout && !form.blackout_dates.includes(blackout)) set('blackout_dates', [...form.blackout_dates, blackout].sort());
            setBlackout('');
          }}>Add</button>
        </div>
        <ul className="space-y-2">{form.blackout_dates.map((date) => <li key={date} className="flex items-center justify-between gap-3 rounded-xl bg-gray-50 px-3 py-2 text-sm dark:bg-gray-800"><span className="font-medium">{date}</span><button type="button" className="min-h-11 shrink-0 px-2 font-semibold text-gray-600 underline-offset-2 hover:underline dark:text-gray-300" onClick={() => set('blackout_dates', form.blackout_dates.filter((value) => value !== date))}>Remove</button></li>)}</ul>
      </fieldset>
      <div className="flex flex-col gap-3 sm:flex-row">
        <button disabled={saving} className="min-h-11 w-full rounded-xl bg-blue-600 px-4 py-2 font-semibold text-white transition-colors hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto">{saving ? 'Saving...' : 'Save'}</button>
        <button type="button" disabled={!changed || saving} onClick={discard} className="min-h-11 w-full rounded-xl border border-gray-300 px-4 py-2 font-semibold text-gray-700 transition-colors hover:border-gray-500 hover:text-gray-950 disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto dark:border-gray-700 dark:text-gray-300 dark:hover:border-gray-500 dark:hover:text-white">Discard changes</button>
      </div>
    </form>
    {timePickerKey && (
      <TimePickerModal
        isOpen
        label={TIME_LABELS[timePickerKey]}
        value={form[timePickerKey]}
        onCancel={() => setTimePickerKey(null)}
        onConfirm={(value) => {
          set(timePickerKey, value);
          setTimePickerKey(null);
        }}
      />
    )}
  </AppLayoutERP>;
}
