import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
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
  showAddTrigger?: boolean;
  showAddressSummary?: boolean;
  modalMode?: 'add' | 'edit';
  isModalOpen?: boolean;
  onModalOpenChange?: (open: boolean) => void;
};

type AddressForm = Omit<CustomerAddress, 'id'>;

const emptyForm = (): AddressForm => ({
  name: '', phone: '', address_line: '', barangay: '', city: '', province: '', region: '',
  postal_code: '', latitude: null, longitude: null, delivery_instructions: '', is_default: false,
});

const fullAddress = (address: CustomerAddress) => [
  address.address_line, address.barangay, address.city, address.province, address.postal_code,
].filter(Boolean).join(', ');

const addressToForm = (address: CustomerAddress): AddressForm => {
  const province = normalizeProvinceSelection(address.province || address.region) || address.province;

  return {
    name: address.name,
    phone: address.phone,
    address_line: address.address_line,
    barangay: address.barangay,
    city: normalizeCityMunicipalitySelection(province, address.city) || address.city,
    province,
    region: address.region || province,
    postal_code: address.postal_code,
    latitude: address.latitude,
    longitude: address.longitude,
    delivery_instructions: address.delivery_instructions || '',
    is_default: address.is_default,
  };
};

export default function CustomerAddressManager({
  onSelect,
  initialAddressId = null,
  disabled = false,
  title = 'Delivery address',
  description = 'Choose where the rider should go. You can still use walk-in or your own courier.',
  showAddTrigger = true,
  showAddressSummary = true,
  modalMode = 'add',
  isModalOpen: controlledModalOpen = false,
  onModalOpenChange,
}: Props) {
  const [addresses, setAddresses] = useState<CustomerAddress[]>([]);
  const [selectedId, setSelectedId] = useState<number | null>(initialAddressId);
  const [editingId, setEditingId] = useState<number | null | undefined>(undefined);
  const [form, setForm] = useState<AddressForm>(emptyForm);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [status, setStatus] = useState('Loading saved addresses…');
  const [error, setError] = useState<string | null>(null);
  const modalRef = useRef<HTMLDivElement | null>(null);
  const closeButtonRef = useRef<HTMLButtonElement | null>(null);
  const cities = useMemo(() => getCityMunicipalityOptions(form.province), [form.province]);
  const isModalControlled = onModalOpenChange !== undefined;
  const modalOpen = isModalControlled ? controlledModalOpen : editingId !== undefined;

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
    onModalOpenChange?.(true);
  };

  const openEdit = (address: CustomerAddress) => {
    setEditingId(address.id);
    setForm(addressToForm(address));
    setError(null);
    onModalOpenChange?.(true);
  };

  const closeModal = useCallback(() => {
    setEditingId(undefined);
    setError(null);
    onModalOpenChange?.(false);
  }, [onModalOpenChange]);

  useEffect(() => {
    if (!isModalControlled) return;

    if (controlledModalOpen && editingId === undefined) {
      const addressToEdit = modalMode === 'edit'
        ? addresses.find((address) => address.id === selectedId)
        : undefined;
      setEditingId(addressToEdit?.id ?? null);
      setForm(addressToEdit ? addressToForm(addressToEdit) : emptyForm());
      setError(null);
    }

    if (!controlledModalOpen && editingId !== undefined) {
      setEditingId(undefined);
      setError(null);
    }
  }, [addresses, controlledModalOpen, editingId, isModalControlled, modalMode, selectedId]);

  useEffect(() => {
    if (!modalOpen) return;

    const previousActiveElement = document.activeElement as HTMLElement | null;
    const previousOverflow = document.body.style.overflow;
    const focusTimer = window.setTimeout(() => closeButtonRef.current?.focus(), 0);

    document.body.style.overflow = 'hidden';

    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        closeModal();
        return;
      }

      if (event.key !== 'Tab' || !modalRef.current) return;

      const focusable = Array.from(modalRef.current.querySelectorAll<HTMLElement>(
        'button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [href], [tabindex]:not([tabindex="-1"])',
      ));
      if (focusable.length === 0) return;

      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    };

    document.addEventListener('keydown', handleKeyDown);

    return () => {
      window.clearTimeout(focusTimer);
      document.body.style.overflow = previousOverflow;
      document.removeEventListener('keydown', handleKeyDown);
      if (previousActiveElement && document.contains(previousActiveElement)) previousActiveElement.focus();
    };
  }, [closeModal, modalOpen]);

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
      onModalOpenChange?.(false);
      setSelectedId(saved.id);
      setStatus('Address saved and selected.');
      onSelect(saved);
    } catch (reason) {
      setError((reason as Error).message);
    } finally {
      setSaving(false);
    }
  };

  const addressSummary = (
    <div className="space-y-3 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
      <div className="flex items-start justify-between gap-3">
        <div>
          <h2 className="font-semibold text-gray-950">{title}</h2>
          <p className="text-sm text-gray-600">{description}</p>
        </div>
        {showAddTrigger && (
          <button type="button" disabled={disabled} onClick={openAdd} className="min-h-11 shrink-0 px-1 text-sm font-semibold text-gray-900 underline underline-offset-4 transition-colors hover:text-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:opacity-50">
            Add address
          </button>
        )}
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
    </div>
  );

  return (
    <>
      {showAddressSummary ? addressSummary : (
        <div className="sr-only">
          <p aria-live="polite" role="status">{loading ? 'Loading saved addresses...' : status}</p>
          {error && editingId === undefined && <p role="alert">{error}</p>}
        </div>
      )}

      {modalOpen && (
        <div
          className="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/50 p-3 backdrop-blur-[2px] sm:p-5"
          onMouseDown={(event) => {
            if (event.target === event.currentTarget) closeModal();
          }}
        >
          <div
            ref={modalRef}
            role="dialog"
            aria-modal="true"
            aria-labelledby="customer-address-modal-title"
            tabIndex={-1}
            className="flex max-h-[min(92dvh,52rem)] w-full max-w-3xl flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-2xl"
          >
            <div className="flex items-start justify-between gap-4 border-b border-gray-200 px-4 py-4 sm:px-6">
              <div>
                <p className="mb-1 text-xs font-semibold uppercase tracking-[0.14em] text-blue-700">Delivery address</p>
                <h2 id="customer-address-modal-title" className="text-xl font-semibold text-gray-950">
                  {editingId ? 'Edit delivery address' : 'Add delivery address'}
                </h2>
                <p className="mt-1 text-sm text-gray-600">Use a precise map pin so nearby repair shops can confirm coverage.</p>
              </div>
              <div className="flex shrink-0 items-center gap-1">
                {editingId && (
                  <button type="button" onClick={openAdd} className="min-h-11 px-2 text-xs font-semibold text-blue-700 underline underline-offset-4 transition-colors hover:text-blue-900 focus:outline-none focus:ring-2 focus:ring-blue-500 sm:text-sm">
                    Add new address
                  </button>
                )}
                <button
                ref={closeButtonRef}
                type="button"
                aria-label="Close address modal"
                onClick={closeModal}
                className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-full text-2xl leading-none text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-950 focus:outline-none focus:ring-2 focus:ring-blue-500"
              >
                <span aria-hidden="true">×</span>
                </button>
              </div>
            </div>
            <div className="overflow-y-auto p-4 sm:p-6">
              <form className="space-y-4" onSubmit={(event) => { event.preventDefault(); void save(); }}>
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
          <div className="flex flex-col-reverse gap-3 border-t border-gray-200 pt-4 sm:flex-row sm:justify-end">
            <button type="button" onClick={closeModal} className="min-h-11 rounded-lg px-4 text-sm font-semibold text-gray-700 underline underline-offset-4 transition-colors hover:text-gray-950 focus:outline-none focus:ring-2 focus:ring-blue-500">
              Cancel
            </button>
            <button type="submit" disabled={saving} className="min-h-11 rounded-lg bg-gray-950 px-5 text-sm font-semibold text-white transition-colors hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:opacity-60">
              {saving ? 'Saving…' : editingId ? 'Save changes' : 'Save address'}
            </button>
          </div>
        </form>
            </div>
          </div>
        </div>
      )}
    </>
  );
}
