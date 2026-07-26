import React, { useEffect, useMemo, useState } from 'react';
import CustomerAddressMapPicker from './CustomerAddressMapPicker';
import {
  PHILIPPINE_LOCATIONS,
  getCityMunicipalityOptions,
  normalizeCityMunicipalitySelection,
  normalizeProvinceSelection,
} from '@/data/philippineLocations';

export type CustomerAddress = {
  id: number;
  name: string;
  phone: string;
  address_line: string;
  barangay: string;
  city: string;
  province: string;
  region: string;
  postal_code: string | null;
  latitude: number | null;
  longitude: number | null;
  delivery_instructions: string | null;
  is_default: boolean;
};

type Props = {
  onSelect: (address: CustomerAddress) => void;
  initialAddressId?: number | null;
  disabled?: boolean;
  title?: string;
  description?: string;
};

type AddressForm = Omit<CustomerAddress, 'id'>;

const emptyForm = (): AddressForm => ({
  name: '', phone: '', address_line: '', barangay: '', city: '', province: '', region: '',
  postal_code: '', latitude: null, longitude: null, delivery_instructions: '', is_default: false,
});

const fullAddress = (address: CustomerAddress) => [
  address.address_line, address.barangay, address.city, address.province, address.postal_code,
].filter(Boolean).join(', ');

export default function CustomerAddressManager({
  onSelect,
  initialAddressId = null,
  disabled = false,
  title = 'Delivery address',
  description = 'Choose where the rider should go. You can still use walk-in or your own courier.',
}: Props) {
  const [addresses, setAddresses] = useState<CustomerAddress[]>([]);
  const [selectedId, setSelectedId] = useState<number | null>(initialAddressId);
  const [editingId, setEditingId] = useState<number | null | undefined>(undefined);
  const [form, setForm] = useState<AddressForm>(emptyForm);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [status, setStatus] = useState('Loading saved addresses…');
  const [error, setError] = useState<string | null>(null);
  const cities = useMemo(() => getCityMunicipalityOptions(form.province), [form.province]);

  useEffect(() => {
    let active = true;
    fetch('/api/user/addresses', { headers: { Accept: 'application/json' }, credentials: 'include' })
      .then(async (response) => {
        if (!response.ok) throw new Error('Unable to load your saved addresses.');
        return response.json() as Promise<{ addresses?: CustomerAddress[] }>;
      })
      .then(({ addresses: loaded = [] }) => {
        if (!active) return;
        setAddresses(loaded);
        const selected = loaded.find((address) => address.id === initialAddressId)
          ?? loaded.find((address) => address.is_default)
          ?? loaded[0];
        if (selected) {
          setSelectedId(selected.id);
          onSelect(selected);
          setStatus('Delivery address ready.');
        } else {
          setStatus('No saved addresses yet. Add one to check shop coverage.');
        }
      })
      .catch((reason: Error) => active && setError(reason.message))
      .finally(() => active && setLoading(false));

    return () => { active = false; };
  }, [initialAddressId, onSelect]);

  const select = (address: CustomerAddress) => {
    setSelectedId(address.id);
    setStatus('Delivery address selected.');
    onSelect(address);
  };

  const openAdd = () => {
    setEditingId(null);
    setForm(emptyForm());
    setError(null);
  };

  const openEdit = (address: CustomerAddress) => {
    const province = normalizeProvinceSelection(address.province || address.region) || address.province;
    setEditingId(address.id);
    setForm({
      ...address,
      province,
      region: address.region || province,
      city: normalizeCityMunicipalitySelection(province, address.city) || address.city,
    });
    setError(null);
  };

  const update = <K extends keyof AddressForm>(key: K, value: AddressForm[K]) => {
    setForm((current) => ({ ...current, [key]: value }));
  };

  const save = async () => {
    setError(null);
    if (form.latitude === null || form.longitude === null) {
      setError('Pin the exact delivery entrance on the map before saving.');
      return;
    }

    const city = normalizeCityMunicipalitySelection(form.province, form.city);
    if (!form.name.trim() || !form.phone.trim() || !form.address_line.trim()
      || !form.barangay.trim() || !form.province || !city) {
      setError('Complete the required address details before saving.');
      return;
    }

    setSaving(true);
    try {
      const response = await fetch(editingId ? `/api/user/addresses/${editingId}` : '/api/user/addresses', {
        method: editingId ? 'PUT' : 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
          'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'include',
        body: JSON.stringify({ ...form, city, region: form.region || form.province }),
      });
      const payload = await response.json().catch(() => ({})) as { address?: CustomerAddress; message?: string };
      if (!response.ok || !payload.address) throw new Error(payload.message || 'Unable to save this address.');

      const saved = payload.address;
      setAddresses((current) => editingId
        ? current.map((address) => address.id === saved.id ? saved : address)
        : [saved, ...current]);
      setEditingId(undefined);
      setSelectedId(saved.id);
      setStatus('Address saved and selected.');
      onSelect(saved);
    } catch (reason) {
      setError((reason as Error).message);
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="space-y-3 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
      <div className="flex items-start justify-between gap-3">
        <div>
          <h2 className="font-semibold text-gray-950">{title}</h2>
          <p className="text-sm text-gray-600">{description}</p>
        </div>
        <button type="button" disabled={disabled} onClick={openAdd} className="min-h-11 shrink-0 rounded-lg border border-gray-300 px-3 text-sm font-semibold hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:opacity-50">
          Add address
        </button>
      </div>

      <p aria-live="polite" role="status" className="text-sm text-gray-600">{loading ? 'Loading saved addresses…' : status}</p>
      {error && editingId === undefined && <p role="alert" className="text-sm text-red-700">{error}</p>}

      {!loading && addresses.length > 0 && (
        <div className="grid gap-2 sm:grid-cols-2">
          {addresses.map((address) => (
            <div key={address.id} className={`rounded-xl border p-3 ${selectedId === address.id ? 'border-blue-600 bg-blue-50' : 'border-gray-200'}`}>
              <button
                type="button"
                aria-label={`Use address at ${address.address_line}`}
                aria-pressed={selectedId === address.id}
                disabled={disabled}
                onClick={() => select(address)}
                className="min-h-11 w-full text-left focus:outline-none focus:ring-2 focus:ring-blue-500"
              >
                <span className="block font-semibold text-gray-950">{address.name}{address.is_default ? ' · Default' : ''}</span>
                <span className="block text-sm text-gray-700">{fullAddress(address)}</span>
                {address.latitude === null || address.longitude === null
                  ? <span className="mt-1 block text-xs font-semibold text-amber-700">Pin required</span>
                  : <span className="mt-1 block text-xs font-semibold text-green-700">Pinned address</span>}
              </button>
              <button type="button" aria-label={`Edit ${address.address_line}`} disabled={disabled} onClick={() => openEdit(address)} className="mt-2 min-h-11 text-sm font-semibold text-blue-700 underline focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:opacity-50">
                Edit
              </button>
            </div>
          ))}
        </div>
      )}

      {editingId !== undefined && (
        <div className="space-y-4 rounded-xl border border-gray-200 bg-gray-50 p-4">
          <div className="flex items-center justify-between gap-3">
            <h3 className="text-lg font-semibold text-gray-950">{editingId ? 'Edit address' : 'Add address'}</h3>
            <button type="button" onClick={() => { setEditingId(undefined); setError(null); }} className="min-h-11 px-2 text-sm font-semibold text-gray-700 underline">Cancel</button>
          </div>
          <div className="grid gap-3 sm:grid-cols-2">
            <label className="text-sm font-medium text-gray-800">Full name<input required value={form.name} onChange={(event) => update('name', event.target.value)} className="mt-1 min-h-11 w-full rounded-lg border border-gray-300 px-3" /></label>
            <label className="text-sm font-medium text-gray-800">Phone<input required inputMode="numeric" value={form.phone} onChange={(event) => update('phone', event.target.value)} className="mt-1 min-h-11 w-full rounded-lg border border-gray-300 px-3" /></label>
            <label className="sm:col-span-2 text-sm font-medium text-gray-800">House no., street, subdivision or building<input required value={form.address_line} onChange={(event) => update('address_line', event.target.value)} className="mt-1 min-h-11 w-full rounded-lg border border-gray-300 px-3" /></label>
            <label className="text-sm font-medium text-gray-800">Province<select required value={form.province} onChange={(event) => setForm((current) => ({ ...current, province: event.target.value, region: event.target.value, city: '' }))} className="mt-1 min-h-11 w-full rounded-lg border border-gray-300 px-3"><option value="">Choose province</option>{PHILIPPINE_LOCATIONS.map(({ name }) => <option key={name}>{name}</option>)}</select></label>
            <label className="text-sm font-medium text-gray-800">City/Municipality<select required value={form.city} onChange={(event) => update('city', event.target.value)} className="mt-1 min-h-11 w-full rounded-lg border border-gray-300 px-3"><option value="">Choose city or municipality</option>{cities.map((city) => <option key={city}>{city}</option>)}</select></label>
            <label className="text-sm font-medium text-gray-800">Barangay<input required value={form.barangay} onChange={(event) => update('barangay', event.target.value)} className="mt-1 min-h-11 w-full rounded-lg border border-gray-300 px-3" /></label>
            <label className="text-sm font-medium text-gray-800">Postal code<input value={form.postal_code ?? ''} onChange={(event) => update('postal_code', event.target.value)} className="mt-1 min-h-11 w-full rounded-lg border border-gray-300 px-3" /></label>
          </div>
          <div>
            <p className="mb-2 text-sm font-medium text-gray-800">Pin the exact delivery entrance</p>
            <CustomerAddressMapPicker
              value={form.latitude !== null && form.longitude !== null ? { latitude: form.latitude, longitude: form.longitude } : null}
              onChange={(location) => {
                const province = normalizeProvinceSelection(location.province || location.region) || location.province || location.region;
                setForm((current) => ({
                  ...current,
                  province,
                  region: location.region || province,
                  city: normalizeCityMunicipalitySelection(province, location.city) || location.city,
                  barangay: location.barangay || current.barangay,
                  postal_code: location.postalCode || current.postal_code,
                  latitude: location.latitude,
                  longitude: location.longitude,
                }));
              }}
            />
          </div>
          <label className="block text-sm font-medium text-gray-800">Delivery instructions (optional)<textarea value={form.delivery_instructions ?? ''} onChange={(event) => update('delivery_instructions', event.target.value)} className="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2" rows={2} /></label>
          {error && <p role="alert" className="text-sm text-red-700">{error}</p>}
          <button type="button" onClick={() => void save()} disabled={saving} className="min-h-11 w-full rounded-lg bg-gray-950 px-4 font-semibold text-white disabled:opacity-60">{saving ? 'Saving…' : editingId ? 'Save changes' : 'Save address'}</button>
        </div>
      )}
    </div>
  );
}
