import React, { FormEvent, useState } from 'react';
import { Head, usePage } from '@inertiajs/react';
import axios from 'axios';
import AppLayoutERP from '@/layout/AppLayout_ERP';

type Settings = {
  operating_days: number[]; cutoff_time: string; blackout_dates: string[];
  lead_time_days: number; morning_start: string; morning_end: string;
  afternoon_start: string; afternoon_end: string; coverage_radius_km: string | number;
  daily_rider_capacity: number; max_delivery_attempts: number;
};

export default function LogisticsSettings() {
  const initial = usePage<{ settings: Settings }>().props.settings;
  const [form, setForm] = useState(initial);
  const [saved, setSaved] = useState(false);
  const [blackout, setBlackout] = useState('');
  const set = (key: keyof Settings, value: Settings[keyof Settings]) => setForm({ ...form, [key]: value });
  const submit = async (event: FormEvent) => {
    event.preventDefault();
    await axios.put('/api/logistics/settings', form);
    setSaved(true);
  };

  return <AppLayoutERP>
    <Head title="Logistics Settings" />
    <form onSubmit={submit} className="mx-auto max-w-3xl space-y-5 p-6">
      <h1 className="text-2xl font-bold">Logistics Settings</h1>
      <fieldset><legend className="font-semibold">Operating days</legend><div className="flex flex-wrap gap-3">{['Mon','Tue','Wed','Thu','Fri','Sat','Sun'].map((day, index) => <label key={day}><input type="checkbox" checked={form.operating_days.includes(index + 1)} onChange={(e) => set('operating_days', e.target.checked ? [...form.operating_days, index + 1].sort() : form.operating_days.filter((value) => value !== index + 1))} /> {day}</label>)}</div></fieldset>
      <div className="grid gap-4 sm:grid-cols-2">
        <label>Cutoff<input className="block w-full rounded border p-2" type="time" value={form.cutoff_time.slice(0, 5)} onChange={(e) => set('cutoff_time', e.target.value)} /></label>
        <label>Lead days<input className="block w-full rounded border p-2" type="number" min="0" value={form.lead_time_days} onChange={(e) => set('lead_time_days', Number(e.target.value))} /></label>
        {(['morning_start','morning_end','afternoon_start','afternoon_end'] as const).map((key) => <label key={key}>{key.replace('_', ' ')}<input className="block w-full rounded border p-2" type="time" value={form[key].slice(0, 5)} onChange={(e) => set(key, e.target.value)} /></label>)}
        <label>Coverage radius (km)<input className="block w-full rounded border p-2" type="number" min="0.1" step="0.1" value={form.coverage_radius_km} onChange={(e) => set('coverage_radius_km', e.target.value)} /></label>
        <label>Daily capacity per rider<input className="block w-full rounded border p-2" type="number" min="1" value={form.daily_rider_capacity} onChange={(e) => set('daily_rider_capacity', Number(e.target.value))} /></label>
        <label>Maximum attempts<input className="block w-full rounded border p-2" type="number" min="1" value={form.max_delivery_attempts} onChange={(e) => set('max_delivery_attempts', Number(e.target.value))} /></label>
      </div>
      <fieldset>
        <legend className="font-semibold">Blackout dates</legend>
        <div className="flex gap-2">
          <input aria-label="Blackout date" className="rounded border p-2" type="date" value={blackout} onChange={(e) => setBlackout(e.target.value)} />
          <button type="button" className="rounded border px-3" onClick={() => {
            if (blackout && !form.blackout_dates.includes(blackout)) set('blackout_dates', [...form.blackout_dates, blackout].sort());
            setBlackout('');
          }}>Add</button>
        </div>
        <ul>{form.blackout_dates.map((date) => <li key={date} className="mt-2 flex gap-2">{date}<button type="button" onClick={() => set('blackout_dates', form.blackout_dates.filter((value) => value !== date))}>Remove</button></li>)}</ul>
      </fieldset>
      <button className="rounded bg-blue-600 px-4 py-2 font-semibold text-white">Save</button>
      {saved && <span className="ml-3 text-green-700">Saved</span>}
    </form>
  </AppLayoutERP>;
}
