import React, { FormEvent, useMemo, useState } from 'react';
import { createPortal } from 'react-dom';
import { Head } from '@inertiajs/react';
import AppLayoutERP from '../../../layout/AppLayout_ERP';
import { ColorVariantManager, ColorVariant } from '../STAFF/ColorVariantManager';
import { ColorVariantImageUploader, ColorVariantImage } from '../STAFF/ColorVariantImageUploader';

type StockCategory = 'shoes' | 'cleaning-materials';
type StockStatus = 'In Stock' | 'Low Stock' | 'Out of Stock';

type StockItem = {
  id: number;
  name: string;
  category: StockCategory;
  sku: string;
  quantity: number;
  unit: string;
  notes: string;
  colorVariants: ColorVariant[];
  repairImages: ColorVariantImage[];
  imageUrl?: string;
  createdAt: string;
};

type MetricCardProps = {
  title: string;
  value: number | string;
  icon: React.FC<{ className?: string }>;
  color?: 'success' | 'error' | 'warning' | 'info';
};

const ArrowUpIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 10l7-7m0 0l7 7m-7-7v18" />
  </svg>
);

const BoxIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10" />
  </svg>
);

const CheckCircleIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
  </svg>
);

const ExclamationIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4c-.77-1.33-2.69-1.33-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z" />
  </svg>
);

const TrendingUpIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
  </svg>
);

const MetricCard: React.FC<MetricCardProps> = ({ title, value, icon: Icon, color }) => {
  const getColorClasses = () => {
    switch (color) {
      case 'success': return 'from-green-500 to-emerald-600';
      case 'error': return 'from-red-500 to-rose-600';
      case 'warning': return 'from-yellow-500 to-orange-600';
      case 'info': return 'from-blue-500 to-indigo-600';
      default: return 'from-gray-500 to-gray-600';
    }
  };

  return (
    <div className="group relative overflow-hidden rounded-2xl border border-gray-200 bg-white p-6 shadow-sm transition-all duration-500 hover:shadow-xl hover:border-gray-300 hover:-translate-y-1 dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-gray-700">
      <div className={`absolute inset-0 bg-gradient-to-br ${getColorClasses()} opacity-0 transition-opacity duration-500 group-hover:opacity-5`} />
      <div className="relative">
        <div className="flex items-center justify-between mb-4">
          <div className={`flex items-center justify-center w-14 h-14 bg-gradient-to-br ${getColorClasses()} rounded-2xl shadow-lg transition-all duration-300 group-hover:scale-110 group-hover:rotate-6`}>
            <Icon className="text-white size-7 drop-shadow-sm" />
          </div>
          <div className="flex items-center gap-1 px-3 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400">
            <ArrowUpIcon className="size-3" />
            0%
          </div>
        </div>
        <div className="space-y-2">
          <p className="text-sm font-medium text-gray-600 dark:text-gray-400">{title}</p>
          <h3 className="text-3xl font-bold text-gray-900 dark:text-white transition-colors duration-300">
            {typeof value === 'number' ? value.toLocaleString() : value}
          </h3>
        </div>
      </div>
    </div>
  );
};

const getStatusByQuantity = (quantity: number): StockStatus => {
  if (quantity === 0) return 'Out of Stock';
  if (quantity < 10) return 'Low Stock';
  return 'In Stock';
};

const getPrimaryImageFromVariants = (variants: ColorVariant[]): string => {
  for (const variant of variants) {
    const thumbnail = variant.images.find((image) => image.is_thumbnail && image.preview);
    if (thumbnail?.preview) return thumbnail.preview;

    const firstImage = variant.images.find((image) => image.preview);
    if (firstImage?.preview) return firstImage.preview;
  }

  return '';
};

const initialStockData: StockItem[] = [
  {
    id: 1,
    name: 'Nike Air Zoom Pegasus',
    category: 'shoes',
    sku: 'SHOE-001',
    quantity: 24,
    unit: 'pairs',
    notes: 'Main shelf',
    colorVariants: [],
    repairImages: [],
    createdAt: '2026-02-21 09:30 AM',
  },
  {
    id: 2,
    name: 'Premium Cleaning Foam',
    category: 'cleaning-materials',
    sku: 'CLEAN-004',
    quantity: 8,
    unit: 'bottles',
    notes: 'Backroom rack',
    colorVariants: [],
    repairImages: [],
    createdAt: '2026-02-21 10:15 AM',
  },
];

export default function UploadInventory() {
  const [stocks, setStocks] = useState<StockItem[]>(initialStockData);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [editingStock, setEditingStock] = useState<StockItem | null>(null);
  const [colorVariants, setColorVariants] = useState<ColorVariant[]>([]);
  const [repairImages, setRepairImages] = useState<ColorVariantImage[]>([]);
  const [isShoesMode, setIsShoesMode] = useState(true);
  const [formData, setFormData] = useState({
    name: '',
    category: 'shoes' as StockCategory,
    sku: '',
    quantity: '',
    unit: 'pcs',
    notes: '',
  });

  const totalItems = useMemo(() => stocks.length, [stocks]);
  const totalUnits = useMemo(() => stocks.reduce((sum, item) => sum + item.quantity, 0), [stocks]);
  const inStock = useMemo(() => stocks.filter((item) => getStatusByQuantity(item.quantity) === 'In Stock').length, [stocks]);
  const outOfStock = useMemo(() => stocks.filter((item) => getStatusByQuantity(item.quantity) === 'Out of Stock').length, [stocks]);

  const resetForm = () => {
    setFormData({
      name: '',
      category: 'shoes',
      sku: '',
      quantity: '',
      unit: 'pcs',
      notes: '',
    });
    setColorVariants([]);
    setRepairImages([]);
    setIsShoesMode(true);
    setEditingStock(null);
  };

  const handleOpenModal = (stock?: StockItem) => {
    if (stock) {
      setEditingStock(stock);
      setFormData({
        name: stock.name,
        category: stock.category,
        sku: stock.sku,
        quantity: stock.quantity.toString(),
        unit: stock.unit,
        notes: stock.notes,
      });
      setColorVariants(stock.colorVariants || []);
      setRepairImages(stock.repairImages || []);
      setIsShoesMode(stock.category === 'shoes');
    } else {
      resetForm();
    }

    setIsModalOpen(true);
  };

  const handleDelete = (id: number) => {
    setStocks((previous) => previous.filter((item) => item.id !== id));
  };

  const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();

    const quantityAsNumber = Number(formData.quantity);
    if (Number.isNaN(quantityAsNumber) || quantityAsNumber < 0) {
      return;
    }

    if (isShoesMode) {
      const totalVariantImages = colorVariants.reduce((sum, variant) => sum + variant.images.length, 0);
      if (colorVariants.length === 0 || totalVariantImages === 0) {
        alert('Please add at least one color variant and upload at least one image.');
        return;
      }
    } else {
      if (repairImages.length === 0) {
        alert('Please add at least one repair material image.');
        return;
      }
    }

    const primaryImageUrl = isShoesMode
      ? getPrimaryImageFromVariants(colorVariants)
      : (repairImages[0]?.preview || '');

    if (editingStock) {
      setStocks((previous) =>
        previous.map((item) =>
          item.id === editingStock.id
            ? {
                ...item,
                name: formData.name,
                category: formData.category,
                sku: formData.sku,
                quantity: quantityAsNumber,
                unit: formData.unit,
                notes: formData.notes,
                colorVariants: isShoesMode ? colorVariants : [],
                repairImages: isShoesMode ? [] : repairImages,
                imageUrl: primaryImageUrl,
              }
            : item
        )
      );
    } else {
      setStocks((previous) => [
        {
          id: Date.now(),
          name: formData.name,
          category: formData.category,
          sku: formData.sku,
          quantity: quantityAsNumber,
          unit: formData.unit,
          notes: formData.notes,
          colorVariants: isShoesMode ? colorVariants : [],
          repairImages: isShoesMode ? [] : repairImages,
          imageUrl: primaryImageUrl,
          createdAt: new Date().toLocaleString(),
        },
        ...previous,
      ]);
    }

    setIsModalOpen(false);
    resetForm();
  };

  return (
    <>
      <AppLayoutERP>
        <Head title="Upload Stocks" />

        <div className="space-y-6">
          <div className="flex items-center justify-between">
            <div>
              <h1 className="text-3xl font-bold text-gray-900 dark:text-white">Upload Stocks</h1>
              <p className="text-gray-600 dark:text-gray-400 mt-1">
                Manage stock uploads for shoes and cleaning materials
              </p>
            </div>
            <button
              onClick={() => handleOpenModal()}
              className="px-5 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-medium"
            >
              + Add Stock Entry
            </button>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-4 gap-6">
            <MetricCard title="Total Stock Items" value={totalItems} color="info" icon={BoxIcon} />
            <MetricCard title="In Stock" value={inStock} color="success" icon={CheckCircleIcon} />
            <MetricCard title="Out of Stock" value={outOfStock} color="error" icon={ExclamationIcon} />
            <MetricCard title="Total Units" value={totalUnits} color="warning" icon={TrendingUpIcon} />
          </div>

          <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm overflow-hidden">
            <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
              <thead className="bg-gray-50 dark:bg-gray-900">
                <tr>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Item</th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Category</th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">SKU</th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Quantity</th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Uploaded</th>
                  <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
                </tr>
              </thead>
              <tbody className="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                {stocks.length === 0 ? (
                  <tr>
                    <td colSpan={7} className="px-6 py-12 text-center text-gray-500 dark:text-gray-400">
                      No stock entries yet. Create your first stock upload!
                    </td>
                  </tr>
                ) : (
                  stocks.map((stock) => {
                    const status = getStatusByQuantity(stock.quantity);

                    return (
                      <tr key={stock.id} className="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
                        <td className="px-6 py-4 whitespace-nowrap">
                          <div className="flex items-center gap-3">
                            {stock.imageUrl ? (
                              <img src={stock.imageUrl} alt={stock.name} className="size-12 rounded-lg object-cover" />
                            ) : (
                              <div className="size-12 rounded-lg bg-gray-200 dark:bg-gray-700 flex items-center justify-center">
                                <span className="text-xs text-gray-500">No image</span>
                              </div>
                            )}
                            <div>
                              <p className="font-medium text-gray-900 dark:text-white">{stock.name}</p>
                              <p className="text-sm text-gray-500 dark:text-gray-400">{stock.notes || 'No notes'}</p>
                            </div>
                          </div>
                        </td>
                        <td className="px-6 py-4 text-gray-900 dark:text-white">
                          {stock.category === 'shoes' ? 'Shoes' : 'Cleaning Materials'}
                        </td>
                        <td className="px-6 py-4 text-gray-900 dark:text-white">{stock.sku}</td>
                        <td className="px-6 py-4">
                          <span className={`px-2 py-1 text-xs font-medium rounded-full ${
                            status === 'Out of Stock'
                              ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'
                              : status === 'Low Stock'
                              ? 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400'
                              : 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
                          }`}>
                            {stock.quantity} {stock.unit}
                          </span>
                        </td>
                        <td className="px-6 py-4">
                          <span className={`px-2 py-1 text-xs font-medium rounded-full ${
                            status === 'In Stock'
                              ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
                              : status === 'Low Stock'
                              ? 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400'
                              : 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'
                          }`}>
                            {status}
                          </span>
                        </td>
                        <td className="px-6 py-4 text-gray-900 dark:text-white">{stock.createdAt}</td>
                        <td className="px-6 py-4 text-right flex items-center justify-end gap-2">
                          <button
                            onClick={() => handleOpenModal(stock)}
                            className="p-2 text-blue-600 hover:text-blue-700 dark:hover:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg transition-colors"
                            title="Edit stock"
                          >
                            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                            </svg>
                          </button>
                          <button
                            onClick={() => handleDelete(stock.id)}
                            className="p-2 text-red-600 hover:text-red-700 dark:hover:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition-colors"
                            title="Delete stock"
                          >
                            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                            </svg>
                          </button>
                        </td>
                      </tr>
                    );
                  })
                )}
              </tbody>
            </table>
          </div>
        </div>
      </AppLayoutERP>

      {isModalOpen && createPortal(
        <div className="fixed inset-0 z-[999999] bg-black/60 backdrop-blur-sm flex items-center justify-center p-2">
          <div className="bg-white dark:bg-gray-800 rounded-xl max-w-6xl w-full shadow-2xl relative flex flex-col border border-gray-200 dark:border-gray-700" style={{ height: 'calc(100vh - 1rem)' }}>
            <div className="sticky top-0 p-6 border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 rounded-t-xl z-10">
              <h2 className="text-2xl font-bold text-gray-900 dark:text-white">
                {editingStock ? 'Edit Stock Entry' : 'Add Stock Entry'}
              </h2>
              <p className="text-sm text-gray-600 dark:text-gray-400 mt-1">
                Fill out stock details for shoes and cleaning materials.
              </p>
            </div>

            <form onSubmit={handleSubmit} className="flex flex-col flex-1 overflow-hidden">
              <div className="flex-1 overflow-y-auto flex flex-col gap-6 p-6 pr-2">
                <div className="flex flex-wrap items-center justify-between gap-4 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                  <div>
                    <p className="text-sm font-semibold text-gray-900 dark:text-white">Upload Type</p>
                    <p className="text-xs text-gray-500 dark:text-gray-400">Toggle between repair materials and shoes.</p>
                  </div>
                  <div className="flex items-center gap-3">
                    <span className={`text-sm font-medium ${!isShoesMode ? 'text-gray-900 dark:text-white' : 'text-gray-400'}`}>
                      Repair Materials
                    </span>
                    <button
                      type="button"
                      onClick={() => {
                        const nextIsShoes = !isShoesMode;
                        setIsShoesMode(nextIsShoes);
                        setFormData((prev) => ({
                          ...prev,
                          category: nextIsShoes ? 'shoes' : 'cleaning-materials',
                        }));
                        if (!nextIsShoes) {
                          setColorVariants([]);
                        }
                      }}
                      className={`relative inline-flex h-6 w-12 items-center rounded-full transition-colors ${
                        isShoesMode ? 'bg-blue-600' : 'bg-gray-300 dark:bg-gray-700'
                      }`}
                      aria-label="Toggle upload type"
                    >
                      <span
                        className={`inline-block h-5 w-5 transform rounded-full bg-white transition-transform ${
                          isShoesMode ? 'translate-x-6' : 'translate-x-1'
                        }`}
                      />
                    </button>
                    <span className={`text-sm font-medium ${isShoesMode ? 'text-gray-900 dark:text-white' : 'text-gray-400'}`}>
                      Shoes
                    </span>
                  </div>
                </div>

                {isShoesMode && (
                <div className="bg-gray-50 dark:bg-gray-900/50 rounded-lg p-6">
                  <ColorVariantManager
                    colorVariants={colorVariants}
                    onColorVariantsChange={setColorVariants}
                  />
                </div>
                )}

                {!isShoesMode && (
                  <div className="bg-gray-50 dark:bg-gray-900/50 rounded-lg p-6">
                    <div className="flex items-center justify-between mb-3">
                      <div>
                        <h3 className="text-lg font-semibold text-gray-900 dark:text-white">
                          Repair Material Images
                        </h3>
                        <p className="text-sm text-gray-600 dark:text-gray-400 mt-0.5">
                          Upload images for the repair materials
                        </p>
                      </div>
                    </div>
                    <ColorVariantImageUploader
                      colorName="Repair Materials"
                      images={repairImages}
                      onImagesChange={setRepairImages}
                      maxImages={10}
                    />
                  </div>
                )}

                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <label htmlFor="stock-name" className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Item Name *</label>
                    <input
                      id="stock-name"
                      type="text"
                      value={formData.name}
                      onChange={(event) => setFormData((prev) => ({ ...prev, name: event.target.value }))}
                      className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-white"
                      placeholder="e.g., Nike Air Force 1"
                      required
                    />
                  </div>

                  <div>
                    <label htmlFor="stock-category" className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Category *</label>
                    <select
                      id="stock-category"
                      title="Stock category"
                      value={formData.category}
                      onChange={(event) => setFormData((prev) => ({ ...prev, category: event.target.value as StockCategory }))}
                      className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-white"
                      required
                    >
                      <option value="shoes">Shoes</option>
                      <option value="cleaning-materials">Cleaning Materials</option>
                    </select>
                  </div>

                  <div>
                    <label htmlFor="stock-sku" className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">SKU *</label>
                    <input
                      id="stock-sku"
                      type="text"
                      value={formData.sku}
                      onChange={(event) => setFormData((prev) => ({ ...prev, sku: event.target.value }))}
                      className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-white"
                      placeholder="e.g., SHOE-001"
                      required
                    />
                  </div>

                  <div>
                    <label htmlFor="stock-quantity" className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Quantity *</label>
                    <input
                      id="stock-quantity"
                      type="number"
                      min="0"
                      value={formData.quantity}
                      onChange={(event) => setFormData((prev) => ({ ...prev, quantity: event.target.value }))}
                      className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-white"
                      placeholder="0"
                      required
                    />
                  </div>

                  <div>
                    <label htmlFor="stock-unit" className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Unit *</label>
                    <input
                      id="stock-unit"
                      type="text"
                      value={formData.unit}
                      onChange={(event) => setFormData((prev) => ({ ...prev, unit: event.target.value }))}
                      className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-white"
                      placeholder="pairs / bottles / pcs"
                      required
                    />
                  </div>

                  <div className="md:col-span-2">
                    <label htmlFor="stock-notes" className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Notes</label>
                    <textarea
                      id="stock-notes"
                      rows={3}
                      value={formData.notes}
                      onChange={(event) => setFormData((prev) => ({ ...prev, notes: event.target.value }))}
                      className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-white"
                      placeholder="Optional notes"
                    />
                  </div>
                </div>
              </div>

              <div className="grid grid-cols-2 gap-3 p-6 border-t border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 flex-shrink-0">
                <button
                  type="button"
                  onClick={() => {
                    setIsModalOpen(false);
                    resetForm();
                  }}
                  className="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm font-semibold text-gray-700 shadow-sm transition-colors hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-900"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  className="h-11 w-full rounded-lg bg-black px-4 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-gray-800"
                >
                  {editingStock ? 'Update Stock' : 'Save Stock'}
                </button>
              </div>
            </form>
          </div>
        </div>,
        document.body
      )}
    </>
  );
}
