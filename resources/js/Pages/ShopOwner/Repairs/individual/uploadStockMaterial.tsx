import { Head } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import type { ComponentType } from 'react';
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
  quantity: string;
  unit: string;
  notes: string;
};

const defaultForm: MaterialForm = {
  name: '',
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

const ArchiveBoxIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path
      strokeLinecap="round"
      strokeLinejoin="round"
      strokeWidth={2}
      d="M20 13V7a2 2 0 00-2-2h-3V3H9v2H6a2 2 0 00-2 2v6m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0H4m5 4h6"
    />
  </svg>
);

const ArchiveRestoreIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path
      strokeLinecap="round"
      strokeLinejoin="round"
      strokeWidth={2}
      d="M3 7h4v4M3 11l5-5a9 9 0 111.24 12.73M9 17h6"
    />
  </svg>
);

const MaterialsIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path
      strokeLinecap="round"
      strokeLinejoin="round"
      strokeWidth={2}
      d="M4 7l8-4 8 4-8 4-8-4zm0 5l8 4 8-4m-16 5l8 4 8-4"
    />
  </svg>
);

const QuantityIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path
      strokeLinecap="round"
      strokeLinejoin="round"
      strokeWidth={2}
      d="M7 20h10M12 4v12m0 0l4-4m-4 4l-4-4"
    />
  </svg>
);

const LowStockIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path
      strokeLinecap="round"
      strokeLinejoin="round"
      strokeWidth={2}
      d="M12 9v4m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4c-.77-1.33-2.69-1.33-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z"
    />
  </svg>
);

const ArrowUpIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 10l7-7m0 0l7 7m-7-7v18" />
  </svg>
);

const ArrowDownIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 14l-7 7m0 0l-7-7m7 7V3" />
  </svg>
);

type MetricTone = 'blue' | 'indigo' | 'amber';
type ChangeType = 'increase' | 'decrease';

type MetricCardProps = {
  title: string;
  value: number | string;
  change?: number;
  changeType?: ChangeType;
  description?: string;
  icon: ComponentType<{ className?: string }>;
  tone: MetricTone;
};

const metricToneStyles: Record<MetricTone, { gradient: string }> = {
  blue: {
    gradient: 'from-blue-500 to-indigo-600',
  },
  indigo: {
    gradient: 'from-indigo-500 to-violet-600',
  },
  amber: {
    gradient: 'from-yellow-500 to-orange-600',
  },
};

const MetricCard = ({ title, value, change, changeType, description, icon: Icon, tone }: MetricCardProps) => {
  const palette = metricToneStyles[tone];

  return (
    <div className="group relative overflow-hidden rounded-2xl border border-gray-200 bg-white p-6 shadow-sm transition-all duration-500 hover:-translate-y-1 hover:border-gray-300 hover:shadow-xl dark:border-gray-800 dark:bg-white/3 dark:hover:border-gray-700">
      <div className={`absolute inset-0 bg-linear-to-br ${palette.gradient} opacity-0 transition-opacity duration-500 group-hover:opacity-5`} />
      <div className="relative">
        <div className="mb-4 flex items-center justify-between">
          <div className={`flex h-14 w-14 items-center justify-center rounded-2xl bg-linear-to-br ${palette.gradient} shadow-lg transition-all duration-300 group-hover:scale-110 group-hover:rotate-6`}>
            <Icon className="size-7 text-white drop-shadow-sm" />
          </div>
          {change !== undefined && (
            <div
              className={`flex items-center gap-1 rounded-full px-3 py-1 text-xs font-semibold transition-all duration-300 ${
                changeType === 'decrease'
                  ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'
                  : 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
              }`}
            >
              {changeType === 'decrease' ? <ArrowDownIcon className="size-3" /> : <ArrowUpIcon className="size-3" />}
              {Math.abs(change)}%
            </div>
          )}
        </div>
        <div className="space-y-2">
          <p className="text-sm font-medium text-gray-600 dark:text-gray-400">{title}</p>
          <h3 className="text-3xl font-bold text-gray-900 transition-colors duration-300 dark:text-white">{value}</h3>
          {description && <p className="text-xs text-gray-500 dark:text-gray-400">{description}</p>}
        </div>
      </div>
    </div>
  );
};

export default function UploadStockMaterial() {
  const [materials, setMaterials] = useState<MaterialItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [showArchived, setShowArchived] = useState(false);
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

  const fetchMaterials = async (archived: boolean = showArchived) => {
    try {
      setLoading(true);
      const response = await axios.get('/api/shop-owner/inventory/items', {
        params: {
          category: 'repair_materials',
          per_page: 100,
          ...(archived ? { archived: true } : {}),
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
    fetchMaterials(showArchived);
  }, [showArchived]);

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
      sku: editing?.sku?.trim() || null,
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

      await fetchMaterials(showArchived);
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

  const handleArchive = async (item: MaterialItem) => {
    const result = await Swal.fire({
      title: 'Archive this material?',
      text: `${item.name} (${item.sku}) will be hidden from active inventory.`,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Yes, archive',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#dc2626',
    });

    if (!result.isConfirmed) return;

    try {
      await axios.delete(`/api/shop-owner/inventory/items/${item.id}`);
      await fetchMaterials(showArchived);
      await Swal.fire({
        icon: 'success',
        title: 'Archived',
        timer: 1400,
        showConfirmButton: false,
      });
    } catch (error) {
      await Swal.fire({
        icon: 'error',
        title: 'Archive failed',
        text: 'Unable to archive this material right now.',
      });
    }
  };

  const handleRestore = async (item: MaterialItem) => {
    const result = await Swal.fire({
      title: 'Restore this material?',
      text: `${item.name} (${item.sku}) will be moved back to active inventory.`,
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Yes, restore',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#2563eb',
    });

    if (!result.isConfirmed) return;

    try {
      await axios.post(`/api/shop-owner/inventory/items/${item.id}/restore`);
      await fetchMaterials(showArchived);
      await Swal.fire({
        icon: 'success',
        title: 'Restored',
        timer: 1400,
        showConfirmButton: false,
      });
    } catch {
      await Swal.fire({
        icon: 'error',
        title: 'Restore failed',
        text: 'Unable to restore this material right now.',
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

          <div className="flex items-center gap-3">
            <button
              type="button"
              onClick={() => setShowArchived((prev) => !prev)}
              className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-800"
            >
              {showArchived ? 'Show Active' : 'Show Archived'}
            </button>
            {!showArchived && (
              <button
                type="button"
                onClick={openCreateModal}
                className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition-colors hover:bg-blue-700"
              >
                Add Material
              </button>
            )}
          </div>
        </div>

        <div className="grid grid-cols-1 gap-6 md:grid-cols-3">
          <MetricCard
            title="Total Materials"
            value={metrics.totalItems}
            change={12}
            changeType="increase"
            description={showArchived ? 'Archived materials in view' : 'Active materials in inventory'}
            icon={MaterialsIcon}
            tone="blue"
          />
          <MetricCard
            title="Total Quantity"
            value={metrics.totalQuantity.toLocaleString()}
            change={8}
            changeType="increase"
            description="Combined quantity across listed materials"
            icon={QuantityIcon}
            tone="indigo"
          />
          <MetricCard
            title="Low Stock Items"
            value={metrics.lowStockCount}
            change={15}
            changeType="increase"
            description="Items at or below reorder level"
            icon={LowStockIcon}
            tone="amber"
          />
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
                      {showArchived ? 'No archived materials found.' : 'No materials found.'}
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
                            disabled={showArchived}
                          >
                            <EditIcon className="h-5 w-5" />
                          </button>
                          {!showArchived ? (
                            <button
                              type="button"
                              onClick={() => handleArchive(item)}
                              aria-label={`Archive ${item.name}`}
                              title="Archive"
                              className="p-2 text-amber-600 hover:bg-amber-50 dark:hover:bg-amber-900/20 rounded-lg transition-colors"
                            >
                              <ArchiveBoxIcon className="h-5 w-5" />
                            </button>
                          ) : (
                            <button
                              type="button"
                              onClick={() => handleRestore(item)}
                              aria-label={`Restore ${item.name}`}
                              title="Restore"
                              className="p-2 text-emerald-600 hover:bg-emerald-50 dark:hover:bg-emerald-900/20 rounded-lg transition-colors"
                            >
                              <ArchiveRestoreIcon className="h-5 w-5" />
                            </button>
                          )}
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
