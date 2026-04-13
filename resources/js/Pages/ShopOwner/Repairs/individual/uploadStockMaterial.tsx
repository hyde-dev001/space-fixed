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
  notes: string | null;
  created_at: string;
};

type MaterialForm = {
  name: string;
  sku: string;
  quantity: string;
  unit: string;
  notes: string;
};

const defaultForm: MaterialForm = {
  name: '',
  sku: '',
  quantity: '',
  unit: 'pcs',
  notes: '',
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

const EditIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path
      strokeLinecap="round"
      strokeLinejoin="round"
      strokeWidth={2}
      d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"
    />
  </svg>
);

const TrashIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path
      strokeLinecap="round"
      strokeLinejoin="round"
      strokeWidth={2}
      d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
    />
  </svg>
);

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
      notes: item.notes ?? '',
    });
    setIsModalOpen(true);
  };

  const buildPayload = () => {
    const quantity = Number(form.quantity);

    return {
      name: form.name.trim(),
      sku: form.sku.trim() || null,
      category: 'repair_materials',
      available_quantity: quantity,
      unit: form.unit.trim() || 'pcs',
      reorder_level: Number.isFinite(Number(editing?.reorder_level))
        ? Number(editing?.reorder_level)
        : 10,
      reorder_quantity: Number.isFinite(Number(editing?.reorder_quantity))
        ? Number(editing?.reorder_quantity)
        : 50,
      cost_price: null,
      price: null,
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
            className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-6 py-3 font-medium text-white shadow-sm transition-colors hover:bg-blue-700"
          >
            Add Material
          </button>
        </div>

        <div className="grid grid-cols-1 gap-6 md:grid-cols-3">
          <div className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm transition-all duration-300 hover:-translate-y-1 hover:shadow-lg dark:border-gray-800 dark:bg-white/3">
            <p className="text-sm font-medium text-gray-600 dark:text-gray-400">Total Materials</p>
            <p className="mt-2 text-3xl font-bold text-gray-900 dark:text-white">{metrics.totalItems}</p>
          </div>
          <div className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm transition-all duration-300 hover:-translate-y-1 hover:shadow-lg dark:border-gray-800 dark:bg-white/3">
            <p className="text-sm font-medium text-gray-600 dark:text-gray-400">Total Quantity</p>
            <p className="mt-2 text-3xl font-bold text-gray-900 dark:text-white">{metrics.totalQuantity.toLocaleString()}</p>
          </div>
          <div className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm transition-all duration-300 hover:-translate-y-1 hover:shadow-lg dark:border-gray-800 dark:bg-white/3">
            <p className="text-sm font-medium text-gray-600 dark:text-gray-400">Low Stock Items</p>
            <p className="mt-2 text-3xl font-bold text-amber-600 dark:text-amber-400">{metrics.lowStockCount}</p>
          </div>
        </div>

        <div className="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/3">
          <input
            type="text"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder="Search by material name or SKU"
            className="w-full rounded-lg border border-gray-300 bg-white px-4 py-2 text-gray-900 outline-none ring-blue-500 transition focus:ring-2 dark:border-gray-700 dark:bg-gray-800 dark:text-white"
          />
        </div>

        <div className="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/3">
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead className="bg-gray-50 dark:bg-gray-900/50 border-b border-gray-200 dark:border-gray-800">
                <tr>
                  <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Material</th>
                  <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">SKU</th>
                  <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Quantity</th>
                  <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Uploaded</th>
                  <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Actions</th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-200 dark:bg-white/2 dark:divide-gray-800">
                {loading ? (
                  <tr>
                    <td colSpan={5} className="px-6 py-12 text-center text-sm text-gray-500 dark:text-gray-400">
                      Loading materials...
                    </td>
                  </tr>
                ) : filteredMaterials.length === 0 ? (
                  <tr>
                    <td colSpan={5} className="px-6 py-12 text-center text-sm text-gray-500 dark:text-gray-400">
                      No materials found.
                    </td>
                  </tr>
                ) : (
                  filteredMaterials.map((item) => (
                    <tr key={item.id} className="transition-colors hover:bg-gray-50 dark:hover:bg-white/2">
                      <td className="px-6 py-4 whitespace-nowrap">
                        <p className="font-medium text-gray-900 dark:text-white">{item.name}</p>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-700 dark:text-gray-300">{item.sku}</td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-700 dark:text-gray-300">
                        {item.available_quantity} {item.unit}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-700 dark:text-gray-300">{formatDate(item.created_at)}</td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm font-medium">
                        <div className="flex items-center gap-2">
                          <button
                            type="button"
                            onClick={() => openEditModal(item)}
                            aria-label={`Edit ${item.name}`}
                            title="Edit"
                            className="p-2 text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg transition-colors"
                          >
                            <EditIcon className="h-5 w-5" />
                          </button>
                          <button
                            type="button"
                            onClick={() => handleDelete(item)}
                            aria-label={`Delete ${item.name}`}
                            title="Delete"
                            className="p-2 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition-colors"
                          >
                            <TrashIcon className="h-5 w-5" />
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
        <div className="fixed inset-0 z-999999 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm">
          <div className="max-h-[90vh] w-full max-w-3xl overflow-y-auto rounded-xl bg-white shadow-2xl dark:bg-gray-900">
            <div className="border-b border-gray-200 p-6 dark:border-gray-800">
              <h2 className="text-2xl font-bold text-gray-900 dark:text-white">
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
