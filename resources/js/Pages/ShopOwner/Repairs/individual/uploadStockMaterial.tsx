import { Head } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import axios from 'axios';
import Swal from 'sweetalert2';
import AppLayoutShopOwner from '../../../../layout/AppLayout_shopOwner';

type MaterialItem = {
  id: number;
  name: string;
  sku: string;
  available_quantity: number;
  unit: string;
  reorder_level: number;
  reorder_quantity: number;
  cost_price: number | null;
  price: number | null;
  notes: string | null;
  created_at: string;
};

type MaterialForm = {
  name: string;
  sku: string;
  quantity: string;
  unit: string;
  reorderLevel: string;
  reorderQuantity: string;
  costPrice: string;
  sellingPrice: string;
  notes: string;
};

const defaultForm: MaterialForm = {
  name: '',
  sku: '',
  quantity: '',
  unit: 'pcs',
  reorderLevel: '10',
  reorderQuantity: '50',
  costPrice: '',
  sellingPrice: '',
  notes: '',
};

const formatCurrency = (value: number | null): string => {
  if (value === null) return '-';
  return new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
    minimumFractionDigits: 2,
  }).format(value);
};

const formatDate = (iso: string): string => {
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return iso;
  return date.toLocaleString('en-PH', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
    hour12: true,
  });
};

export default function UploadStockMaterial() {
  const [materials, setMaterials] = useState<MaterialItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [editing, setEditing] = useState<MaterialItem | null>(null);
  const [form, setForm] = useState<MaterialForm>(defaultForm);

  const filteredMaterials = useMemo(() => {
    const term = search.trim().toLowerCase();
    if (!term) return materials;

    return materials.filter((item) => {
      return item.name.toLowerCase().includes(term) || item.sku.toLowerCase().includes(term);
    });
  }, [materials, search]);

  const metrics = useMemo(() => {
    const totalItems = materials.length;
    const totalQuantity = materials.reduce((sum, item) => sum + Number(item.available_quantity || 0), 0);
    const lowStockCount = materials.filter(
      (item) => item.available_quantity > 0 && item.available_quantity <= Number(item.reorder_level || 0)
    ).length;

    return { totalItems, totalQuantity, lowStockCount };
  }, [materials]);

  const fetchMaterials = async () => {
    try {
      setLoading(true);
      const response = await axios.get('/api/shop-owner/inventory/items', {
        params: {
          category: 'repair_materials',
          per_page: 100,
        },
      });

      setMaterials(response.data?.data ?? []);
    } catch (error) {
      console.error('Failed to load repair materials', error);
      Swal.fire({
        icon: 'error',
        title: 'Failed to load materials',
        text: 'Unable to fetch repair material inventory right now.',
      });
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchMaterials();
  }, []);

  const resetForm = () => {
    setEditing(null);
    setForm(defaultForm);
  };

  const openCreateModal = () => {
    resetForm();
    setIsModalOpen(true);
  };

  const openEditModal = (item: MaterialItem) => {
    setEditing(item);
    setForm({
      name: item.name,
      sku: item.sku,
      quantity: String(item.available_quantity),
      unit: item.unit || 'pcs',
      reorderLevel: String(item.reorder_level ?? 10),
      reorderQuantity: String(item.reorder_quantity ?? 50),
      costPrice: item.cost_price === null ? '' : String(item.cost_price),
      sellingPrice: item.price === null ? '' : String(item.price),
      notes: item.notes ?? '',
    });
    setIsModalOpen(true);
  };

  const parseOptionalMoney = (value: string): number | null => {
    const trimmed = value.trim();
    if (!trimmed) return null;
    const parsed = Number(trimmed);
    return Number.isFinite(parsed) ? parsed : null;
  };

  const buildPayload = () => {
    const quantity = Number(form.quantity);
    const reorderLevel = Number(form.reorderLevel || 0);
    const reorderQuantity = Number(form.reorderQuantity || 0);

    return {
      name: form.name.trim(),
      sku: form.sku.trim() || null,
      category: 'repair_materials',
      available_quantity: quantity,
      unit: form.unit.trim() || 'pcs',
      reorder_level: Number.isFinite(reorderLevel) ? reorderLevel : 0,
      reorder_quantity: Number.isFinite(reorderQuantity) ? reorderQuantity : 0,
      cost_price: parseOptionalMoney(form.costPrice),
      price: parseOptionalMoney(form.sellingPrice),
      notes: form.notes.trim() || null,
    };
  };

  const validateForm = async (): Promise<boolean> => {
    if (!form.name.trim()) {
      await Swal.fire({ icon: 'warning', title: 'Name is required', text: 'Please enter a material name.' });
      return false;
    }

    const quantity = Number(form.quantity);
    if (!Number.isFinite(quantity) || quantity <= 0) {
      await Swal.fire({ icon: 'warning', title: 'Invalid quantity', text: 'Quantity must be greater than zero.' });
      return false;
    }

    return true;
  };

  const handleSubmit = async () => {
    const valid = await validateForm();
    if (!valid) return;

    const payload = buildPayload();
    setIsSubmitting(true);

    try {
      if (editing) {
        await axios.put(`/api/shop-owner/inventory/items/${editing.id}`, payload);
      } else {
        await axios.post('/api/shop-owner/inventory/items', payload);
      }

      await fetchMaterials();
      setIsModalOpen(false);
      resetForm();

      await Swal.fire({
        icon: 'success',
        title: editing ? 'Material updated' : 'Material created',
        timer: 1800,
        showConfirmButton: false,
      });
    } catch (error: any) {
      const serverMessage = error?.response?.data?.message;
      const validationErrors = error?.response?.data?.errors as unknown;
      let firstValidationError: string | null = null;
      if (validationErrors && typeof validationErrors === 'object') {
        const groupedErrors = validationErrors as Record<string, string[]>;
        const firstKey = Object.keys(groupedErrors)[0];
        firstValidationError = firstKey ? groupedErrors[firstKey]?.[0] ?? null : null;
      }

      await Swal.fire({
        icon: 'error',
        title: 'Save failed',
        text: serverMessage || firstValidationError || 'Unable to save material right now.',
      });
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleDelete = async (item: MaterialItem) => {
    const result = await Swal.fire({
      title: 'Delete this material?',
      text: `${item.name} (${item.sku}) will be removed from your inventory list.`,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Yes, delete',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#dc2626',
    });

    if (!result.isConfirmed) return;

    try {
      await axios.delete(`/api/shop-owner/inventory/items/${item.id}`);
      await fetchMaterials();
      await Swal.fire({
        icon: 'success',
        title: 'Deleted',
        timer: 1400,
        showConfirmButton: false,
      });
    } catch (error) {
      await Swal.fire({
        icon: 'error',
        title: 'Delete failed',
        text: 'Unable to delete this material right now.',
      });
    }
  };

  return (
    <AppLayoutShopOwner hideHeader={isModalOpen}>
      <Head title="Upload Stock Materials" />

      <div className="space-y-6 p-6">
        <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
          <div>
            <h1 className="text-3xl font-bold text-gray-900 dark:text-white">Upload Stock Materials</h1>
            <p className="mt-2 text-sm text-gray-600 dark:text-gray-400">
              Add and manage repair material inventory for individual shop operations.
            </p>
          </div>

          <button
            type="button"
            onClick={openCreateModal}
            className="inline-flex items-center justify-center rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-blue-700"
          >
            Add Material
          </button>
        </div>

        <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
          <div className="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
            <p className="text-sm text-gray-500 dark:text-gray-400">Total Materials</p>
            <p className="mt-2 text-3xl font-bold text-gray-900 dark:text-white">{metrics.totalItems}</p>
          </div>
          <div className="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
            <p className="text-sm text-gray-500 dark:text-gray-400">Total Quantity</p>
            <p className="mt-2 text-3xl font-bold text-gray-900 dark:text-white">{metrics.totalQuantity.toLocaleString()}</p>
          </div>
          <div className="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
            <p className="text-sm text-gray-500 dark:text-gray-400">Low Stock Items</p>
            <p className="mt-2 text-3xl font-bold text-amber-600 dark:text-amber-400">{metrics.lowStockCount}</p>
          </div>
        </div>

        <div className="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/3">
          <input
            type="text"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder="Search by material name or SKU"
            className="w-full rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 outline-none ring-blue-500 transition focus:ring-2 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
          />
        </div>

        <div className="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/3">
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-800">
              <thead className="bg-gray-50 dark:bg-gray-900/40">
                <tr>
                  <th className="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Material</th>
                  <th className="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">SKU</th>
                  <th className="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Quantity</th>
                  <th className="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Cost</th>
                  <th className="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Selling</th>
                  <th className="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Uploaded</th>
                  <th className="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200 dark:divide-gray-800">
                {loading ? (
                  <tr>
                    <td colSpan={7} className="px-6 py-10 text-center text-sm text-gray-500">
                      Loading materials...
                    </td>
                  </tr>
                ) : filteredMaterials.length === 0 ? (
                  <tr>
                    <td colSpan={7} className="px-6 py-10 text-center text-sm text-gray-500">
                      No materials found.
                    </td>
                  </tr>
                ) : (
                  filteredMaterials.map((item) => (
                    <tr key={item.id} className="hover:bg-gray-50/70 dark:hover:bg-white/2">
                      <td className="px-6 py-4">
                        <p className="font-medium text-gray-900 dark:text-white">{item.name}</p>
                        <p className="text-xs text-gray-500 dark:text-gray-400">Reorder level: {item.reorder_level}</p>
                      </td>
                      <td className="px-6 py-4 text-sm text-gray-700 dark:text-gray-300">{item.sku}</td>
                      <td className="px-6 py-4 text-sm text-gray-700 dark:text-gray-300">
                        {item.available_quantity} {item.unit}
                      </td>
                      <td className="px-6 py-4 text-sm text-gray-700 dark:text-gray-300">{formatCurrency(item.cost_price)}</td>
                      <td className="px-6 py-4 text-sm text-gray-700 dark:text-gray-300">{formatCurrency(item.price)}</td>
                      <td className="px-6 py-4 text-sm text-gray-700 dark:text-gray-300">{formatDate(item.created_at)}</td>
                      <td className="px-6 py-4 text-sm">
                        <div className="flex items-center gap-2">
                          <button
                            type="button"
                            onClick={() => openEditModal(item)}
                            className="rounded-md border border-blue-200 px-3 py-1.5 text-blue-600 transition hover:bg-blue-50 dark:border-blue-800 dark:hover:bg-blue-900/20"
                          >
                            Edit
                          </button>
                          <button
                            type="button"
                            onClick={() => handleDelete(item)}
                            className="rounded-md border border-red-200 px-3 py-1.5 text-red-600 transition hover:bg-red-50 dark:border-red-800 dark:hover:bg-red-900/20"
                          >
                            Delete
                          </button>
                        </div>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </div>
      </div>

      {isModalOpen && (
        <div className="fixed inset-0 z-9999 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm">
          <div className="max-h-[90vh] w-full max-w-3xl overflow-y-auto rounded-xl bg-white shadow-2xl dark:bg-gray-900">
            <div className="border-b border-gray-200 p-6 dark:border-gray-800">
              <h2 className="text-xl font-bold text-gray-900 dark:text-white">
                {editing ? 'Edit Material' : 'Add Material'}
              </h2>
            </div>

            <div className="grid grid-cols-1 gap-4 p-6 md:grid-cols-2">
              <div className="md:col-span-2">
                <label htmlFor="material-name" className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Material Name *</label>
                <input
                  id="material-name"
                  type="text"
                  title="Material Name"
                  value={form.name}
                  onChange={(event) => setForm((prev) => ({ ...prev, name: event.target.value }))}
                  className="w-full rounded-lg border border-gray-300 bg-white px-4 py-2 text-gray-900 outline-none ring-blue-500 transition focus:ring-2 dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                />
              </div>

              <div>
                <label htmlFor="material-sku" className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">SKU (optional)</label>
                <input
                  id="material-sku"
                  type="text"
                  title="SKU"
                  value={form.sku}
                  onChange={(event) => setForm((prev) => ({ ...prev, sku: event.target.value }))}
                  className="w-full rounded-lg border border-gray-300 bg-white px-4 py-2 text-gray-900 outline-none ring-blue-500 transition focus:ring-2 dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                />
              </div>

              <div>
                <label htmlFor="material-unit" className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Unit</label>
                <input
                  id="material-unit"
                  type="text"
                  title="Unit"
                  value={form.unit}
                  onChange={(event) => setForm((prev) => ({ ...prev, unit: event.target.value }))}
                  className="w-full rounded-lg border border-gray-300 bg-white px-4 py-2 text-gray-900 outline-none ring-blue-500 transition focus:ring-2 dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                />
              </div>

              <div>
                <label htmlFor="material-quantity" className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Quantity *</label>
                <input
                  id="material-quantity"
                  type="number"
                  min={1}
                  title="Quantity"
                  value={form.quantity}
                  onChange={(event) => setForm((prev) => ({ ...prev, quantity: event.target.value }))}
                  className="w-full rounded-lg border border-gray-300 bg-white px-4 py-2 text-gray-900 outline-none ring-blue-500 transition focus:ring-2 dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                />
              </div>

              <div>
                <label htmlFor="material-reorder-level" className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Reorder Level</label>
                <input
                  id="material-reorder-level"
                  type="number"
                  min={0}
                  title="Reorder Level"
                  value={form.reorderLevel}
                  onChange={(event) => setForm((prev) => ({ ...prev, reorderLevel: event.target.value }))}
                  className="w-full rounded-lg border border-gray-300 bg-white px-4 py-2 text-gray-900 outline-none ring-blue-500 transition focus:ring-2 dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                />
              </div>

              <div>
                <label htmlFor="material-reorder-quantity" className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Reorder Quantity</label>
                <input
                  id="material-reorder-quantity"
                  type="number"
                  min={0}
                  title="Reorder Quantity"
                  value={form.reorderQuantity}
                  onChange={(event) => setForm((prev) => ({ ...prev, reorderQuantity: event.target.value }))}
                  className="w-full rounded-lg border border-gray-300 bg-white px-4 py-2 text-gray-900 outline-none ring-blue-500 transition focus:ring-2 dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                />
              </div>

              <div>
                <label htmlFor="material-cost-price" className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Cost Price</label>
                <input
                  id="material-cost-price"
                  type="number"
                  step="0.01"
                  min={0}
                  title="Cost Price"
                  value={form.costPrice}
                  onChange={(event) => setForm((prev) => ({ ...prev, costPrice: event.target.value }))}
                  className="w-full rounded-lg border border-gray-300 bg-white px-4 py-2 text-gray-900 outline-none ring-blue-500 transition focus:ring-2 dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                />
              </div>

              <div>
                <label htmlFor="material-selling-price" className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Selling Price</label>
                <input
                  id="material-selling-price"
                  type="number"
                  step="0.01"
                  min={0}
                  title="Selling Price"
                  value={form.sellingPrice}
                  onChange={(event) => setForm((prev) => ({ ...prev, sellingPrice: event.target.value }))}
                  className="w-full rounded-lg border border-gray-300 bg-white px-4 py-2 text-gray-900 outline-none ring-blue-500 transition focus:ring-2 dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                />
              </div>

              <div className="md:col-span-2">
                <label htmlFor="material-notes" className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Notes</label>
                <textarea
                  id="material-notes"
                  rows={3}
                  title="Notes"
                  value={form.notes}
                  onChange={(event) => setForm((prev) => ({ ...prev, notes: event.target.value }))}
                  className="w-full rounded-lg border border-gray-300 bg-white px-4 py-2 text-gray-900 outline-none ring-blue-500 transition focus:ring-2 dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                />
              </div>
            </div>

            <div className="flex items-center justify-end gap-3 border-t border-gray-200 p-6 dark:border-gray-800">
              <button
                type="button"
                onClick={() => {
                  setIsModalOpen(false);
                  resetForm();
                }}
                className="rounded-lg border border-gray-300 px-4 py-2 text-gray-700 transition hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
              >
                Cancel
              </button>
              <button
                type="button"
                onClick={handleSubmit}
                disabled={isSubmitting}
                className="rounded-lg bg-blue-600 px-4 py-2 font-medium text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-70"
              >
                {isSubmitting ? 'Saving...' : editing ? 'Save Changes' : 'Create Material'}
              </button>
            </div>
          </div>
        </div>
      )}
    </AppLayoutShopOwner>
  );
}
