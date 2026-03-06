import React, { FormEvent, useMemo, useState } from 'react';
import { createPortal } from 'react-dom';
import { Head, usePage } from '@inertiajs/react';
import AppLayoutERP from '../../../layout/AppLayout_ERP';
import { ColorVariantManager, ColorVariant, SizeVariant } from '@/components/variants/ColorVariantManager';
import { ColorVariantImageUploader, ColorVariantImage } from '@/components/variants/ColorVariantImageUploader';
import { inventoryItemAPI } from '@/services/inventoryAPI';
import type { InventoryItem as ApiInventoryItem, InventoryColorVariant, InventoryImage, InventorySize } from '@/types/inventory';

type StockCategory = 'shoes' | 'cleaning_materials';
type StockStatus = 'In Stock' | 'Low Stock' | 'Out of Stock';

type StockItem = {
  id: number;
  name: string;
  brand: string;
  shoeType: string;
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

const toStorageUrl = (path?: string | null) =>
  path ? `/storage/${path}` : '';

const mapApiItemToStock = (item: ApiInventoryItem): StockItem => {
  const isShoes = item.category === 'shoes';

  // Map API color variants → ColorVariant[] for the ColorVariantManager
  // Item-level images (no color_variant_id) — used as fallback for old uploads
  const itemLevelImages = (item.images ?? []).filter(
    (img: InventoryImage) => !img.inventory_color_variant_id,
  );

  const colorVariants: ColorVariant[] = (item.color_variants ?? []).map((v: InventoryColorVariant, vi: number) => {
    // Use variant-specific images when available, otherwise assign all item images to the first variant
    const variantImgs = v.images?.length > 0 ? v.images : vi === 0 ? itemLevelImages : [];
    return {
      id: String(v.id),
      color_name: v.color_name,
      color_code: v.color_code ?? '#000000',
      isExpanded: false,
      images: variantImgs.map((img: InventoryImage) => ({
        id: String(img.id),
        file: null,
        preview: toStorageUrl(img.image_path),
        is_thumbnail: img.is_thumbnail,
        sort_order: img.sort_order,
        uploaded_path: img.image_path,
      })),
      sizes: (item.sizes ?? []).map((s: InventorySize) => ({
        id: String(s.id),
        size: s.size,
        quantity: s.quantity,
      })),
    };
  });

  // Map direct item images (not tied to a color variant) → repairImages
  const repairImages: ColorVariantImage[] = (item.images ?? [])
    .filter((img: InventoryImage) => !img.inventory_color_variant_id)
    .map((img: InventoryImage) => ({
      id: String(img.id),
      file: null,
      preview: toStorageUrl(img.image_path),
      is_thumbnail: img.is_thumbnail,
      sort_order: img.sort_order,
      uploaded_path: img.image_path,
    }));

  return {
    id: item.id,
    name: item.name,
    brand: item.brand ?? '',
    shoeType: item.description ?? '',
    category: (isShoes ? 'shoes' : 'cleaning_materials') as StockCategory,
    sku: item.sku,
    quantity: item.available_quantity,
    unit: item.unit,
    notes: item.notes ?? '',
    colorVariants,
    repairImages,
    imageUrl: toStorageUrl(item.main_image),
    createdAt: item.created_at,
  };
};

export default function UploadInventory() {
  const { initialData, auth } = usePage().props as any;

  // Resolve the shop's business_type from either shop_owner guard or user guard's shop_owner sub-object
  const rawBusinessType: string =
    auth?.shop_owner?.business_type ??
    auth?.user?.shop_owner?.business_type ??
    'both';
  const businessType = rawBusinessType.toLowerCase() as 'retail' | 'repair' | 'both';

  // Derived capability flags
  const canUploadShoes    = businessType === 'retail' || businessType === 'both';
  const canUploadRepair   = businessType === 'repair' || businessType === 'both';
  const showToggle        = businessType === 'both';

  const [stocks, setStocks] = useState<StockItem[]>(
    () => (initialData?.data ?? []).map(mapApiItemToStock)
  );
  const [loadingStocks, setLoadingStocks] = useState(false);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [editingStock, setEditingStock] = useState<StockItem | null>(null);
  const [colorVariants, setColorVariants] = useState<ColorVariant[]>([]);
  const [newColorVariants, setNewColorVariants] = useState<ColorVariant[]>([]);
  const [repairImages, setRepairImages] = useState<ColorVariantImage[]>([]);
  // Default mode: shoes for retail/both, repair materials for repair-only
  const [isShoesMode, setIsShoesMode] = useState(canUploadShoes);
  const shoeTypeOptions = [
    { label: 'Women',      value: 'women' },
    { label: 'Men',        value: 'men' },
    { label: 'Kids',       value: 'kids' },
    { label: 'Running',    value: 'running' },
    { label: 'Basketball', value: 'basketball' },
    { label: 'Training',   value: 'training' },
    { label: 'Casual',     value: 'casual' },
    { label: 'Football',   value: 'football' },
    { label: 'Slides',     value: 'slides' },
    { label: 'Tennis',     value: 'tennis' },
    { label: 'Loafers',    value: 'loafers' },
    { label: 'Lifestyle',  value: 'lifestyle' },
    { label: 'Sports',     value: 'sports' },
  ];
  const shoeTypeLabelByValue = shoeTypeOptions.reduce<Record<string, string>>((acc, o) => {
    acc[o.value] = o.label; return acc;
  }, {});

  const [selectedShoeTypes, setSelectedShoeTypes] = useState<string[]>([]);
  const [isShoeTypePickerOpen, setIsShoeTypePickerOpen] = useState(false);

  const [formData, setFormData] = useState({
    name: '',
    brand: '',
    category: (canUploadShoes ? 'shoes' : 'cleaning_materials') as StockCategory,
    sku: '',
    quantity: '',
    unit: 'pcs',
    notes: '',
  });

  const fetchStocks = async () => {
    setLoadingStocks(true);
    try {
      const res = await inventoryItemAPI.getAll({ per_page: 200 });
      setStocks((res.data ?? []).map(mapApiItemToStock));
    } catch (err) {
      console.error('Failed to load inventory items', err);
    } finally {
      setLoadingStocks(false);
    }
  };

  const toggleShoeType = (value: string) => {
    setSelectedShoeTypes((prev) =>
      prev.includes(value) ? prev.filter((v) => v !== value) : [...prev, value]
    );
  };

  const totalItems = useMemo(() => stocks.length, [stocks]);
  const totalUnits = useMemo(() => stocks.reduce((sum, item) => sum + item.quantity, 0), [stocks]);
  const inStock = useMemo(() => stocks.filter((item) => getStatusByQuantity(item.quantity) === 'In Stock').length, [stocks]);
  const outOfStock = useMemo(() => stocks.filter((item) => getStatusByQuantity(item.quantity) === 'Out of Stock').length, [stocks]);

  // Auto-calculate total quantity for shoes from all size variants
  const shoesAutoQuantity = useMemo(() =>
    colorVariants.reduce((total, v) => total + v.sizes.reduce((s, sz) => s + sz.quantity, 0), 0),
  [colorVariants]);

  const resetForm = () => {
    setFormData({
      name: '',
      brand: '',
      category: canUploadShoes ? 'shoes' : 'cleaning_materials',
      sku: '',
      quantity: '',
      unit: 'pcs',
      notes: '',
    });
    setSelectedShoeTypes([]);
    setColorVariants([]);
    setNewColorVariants([]);
    setRepairImages([]);
    setIsShoesMode(canUploadShoes);
    setEditingStock(null);
  };

  const handleOpenModal = (stock?: StockItem) => {
    if (stock) {
      setEditingStock(stock);
      setFormData({
        name: stock.name,
        brand: stock.brand,
        category: stock.category,
        sku: stock.sku,
        quantity: stock.quantity.toString(),
        unit: stock.unit,
        notes: stock.notes,
      });
      setSelectedShoeTypes(stock.shoeType ? stock.shoeType.split(',').filter(Boolean) : []);
      setColorVariants(stock.colorVariants || []);
      setNewColorVariants([]);
      setRepairImages(stock.repairImages || []);
      setIsShoesMode(stock.category !== 'cleaning_materials');
    } else {
      resetForm();
    }

    setIsModalOpen(true);
  };

  const handleDelete = async (id: number) => {
    if (!window.confirm('Delete this stock entry?')) return;
    try {
      await inventoryItemAPI.delete(id);
      setStocks((previous) => previous.filter((item) => item.id !== id));
    } catch (err) {
      console.error('Failed to delete stock item', err);
      alert('Could not delete item. Please try again.');
    }
  };

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();

    // For shoes, use auto-calculated total from size variants; for cleaning materials use the manual field
    const quantityAsNumber = isShoesMode
      ? shoesAutoQuantity
      : Number(formData.quantity);

    if (!isShoesMode && (Number.isNaN(quantityAsNumber) || quantityAsNumber < 0)) {
      return;
    }

    // Only require images/variants when creating — not when editing (already uploaded)
    if (!editingStock) {
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
    }

    // Extract File objects: for shoes images travel with each variant, for repair use flat array
    const imageFiles: File[] = isShoesMode
      ? []  // shoes images are embedded in colorVariantsPayload below
      : repairImages.map((img) => img.file).filter((f): f is File => f !== null);

    // Build color variants payload for shoes — include each variant's images
    const colorVariantsPayload =
      isShoesMode && colorVariants.length > 0
        ? colorVariants.map((v) => ({
            color_name: v.color_name,
            color_code: v.color_code,
            quantity: v.sizes.reduce((sum, s) => sum + s.quantity, 0),
            images: v.images.map((img) => img.file).filter((f): f is File => f !== null),
          }))
        : undefined;

    // Build flat sizes payload from all color variants
    const sizesPayload =
      isShoesMode && colorVariants.length > 0
        ? colorVariants.flatMap((v) =>
            v.sizes.map((s) => ({ size: s.size, quantity: s.quantity }))
          )
        : undefined;

    try {
      const resolvedUnit = isShoesMode ? 'pairs' : (formData.unit || 'pcs');

      if (editingStock) {
        await inventoryItemAPI.update(editingStock.id, {
          name: formData.name,
          sku: formData.sku || undefined,
          category: formData.category,
          brand: isShoesMode ? (formData.brand || undefined) : undefined,
          description: isShoesMode ? (selectedShoeTypes.join(',') || undefined) : undefined,
          unit: resolvedUnit,
          notes: formData.notes,
          available_quantity: quantityAsNumber,
          reorder_level: 5,
          reorder_quantity: 10,
        });
        // Upload any newly-added repair images when editing
        if (imageFiles.length > 0) {
          await inventoryItemAPI.uploadImages(editingStock.id, imageFiles);
        }
        // Persist any new colour variants added in edit mode
        if (isShoesMode && newColorVariants.length > 0) {
          for (const variant of newColorVariants) {
            await inventoryItemAPI.addColor(editingStock.id, {
              color_name: variant.color_name,
              color_code: variant.color_code || undefined,
              images: variant.images.map((img) => img.file).filter((f): f is File => f !== null),
              sizes: variant.sizes.map((s) => ({ size: s.size, quantity: s.quantity })),
            });
          }
        }
      } else {
        await inventoryItemAPI.create({
          name: formData.name,
          sku: formData.sku || undefined,
          category: formData.category,
          brand: isShoesMode ? (formData.brand || undefined) : undefined,
          description: isShoesMode ? (selectedShoeTypes.join(',') || undefined) : undefined,
          unit: resolvedUnit,
          notes: formData.notes,
          available_quantity: quantityAsNumber,
          reorder_level: 5,
          reorder_quantity: 10,
          images: imageFiles.length > 0 ? imageFiles : undefined,
          color_variants: colorVariantsPayload,
          sizes: sizesPayload,
        });
      }
      await fetchStocks();
    } catch (err) {
      console.error('Failed to save stock entry', err);
      alert('Could not save stock entry. Please try again.');
      return;
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
                {businessType === 'retail'
                  ? 'Manage stock uploads for shoes'
                  : businessType === 'repair'
                  ? 'Manage stock uploads for repair materials'
                  : 'Manage stock uploads for shoes and repair materials'}
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
                {loadingStocks ? (
                  <tr><td colSpan={7} className="px-6 py-12 text-center text-gray-500 dark:text-gray-400">Loading stock entries...</td></tr>
                ) : stocks.length === 0 ? (
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
                              <p className="text-sm text-gray-500 dark:text-gray-400">
                                {stock.category === 'shoes'
                                  ? [stock.brand, stock.shoeType.split(',').filter(Boolean).join(', ')].filter(Boolean).join(' · ') || stock.notes || '—'
                                  : stock.notes || '—'}
                              </p>
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
                {businessType === 'retail'
                  ? 'Fill out stock details for shoes.'
                  : businessType === 'repair'
                  ? 'Fill out stock details for repair materials.'
                  : 'Fill out stock details for shoes and repair materials.'}
              </p>
            </div>

            <form onSubmit={handleSubmit} className="flex flex-col flex-1 overflow-hidden">
              <div className="flex-1 overflow-y-auto flex flex-col gap-6 p-6 pr-2">
                <div className="flex flex-wrap items-center justify-between gap-4 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                  <div>
                    <p className="text-sm font-semibold text-gray-900 dark:text-white">Upload Type</p>
                    <p className="text-xs text-gray-500 dark:text-gray-400">
                      {showToggle
                        ? 'Toggle between repair materials and shoes.'
                        : canUploadShoes
                          ? 'This shop is set up for retail — uploading shoes only.'
                          : 'This shop is set up for repair — uploading repair materials only.'}
                    </p>
                  </div>
                  {showToggle ? (
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
                            category: nextIsShoes ? 'shoes' : 'cleaning_materials',
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
                  ) : (
                    <span className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold ${
                      canUploadShoes
                        ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300'
                        : 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-300'
                    }`}>
                      {canUploadShoes ? '👟 Shoes' : '🔧 Repair Materials'}
                    </span>
                  )}
                </div>

                {isShoesMode && (
                <div className="bg-gray-50 dark:bg-gray-900/50 rounded-lg p-6 space-y-6">
                  {editingStock ? (
                    <>
                      {/* Existing colours — read-only */}
                      {colorVariants.length > 0 && (
                        <div>
                          <h3 className="text-base font-semibold text-gray-900 dark:text-white mb-3">
                            Existing Colours
                          </h3>
                          <div className="space-y-3">
                            {colorVariants.map((cv) => (
                              <div
                                key={cv.id}
                                className="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4"
                              >
                                <div className="flex items-center gap-3 mb-2">
                                  <div
                                    className="h-5 w-5 rounded-full border border-gray-300 dark:border-gray-600 flex-shrink-0"
                                    style={{ backgroundColor: cv.color_code }}
                                  />
                                  <span className="font-medium text-gray-900 dark:text-white text-sm">
                                    {cv.color_name}
                                  </span>
                                  <span className="text-xs text-gray-500 dark:text-gray-400">
                                    {cv.images.length} image{cv.images.length !== 1 ? 's' : ''}
                                  </span>
                                  <span className="ml-auto text-xs font-medium bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300 px-2 py-0.5 rounded-full">
                                    {cv.sizes.reduce((sum, s) => sum + s.quantity, 0)} units
                                  </span>
                                </div>
                                <div className="flex flex-wrap gap-2">
                                  {cv.sizes.map((s) => (
                                    <span
                                      key={s.id}
                                      className="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-gray-100 dark:bg-gray-800 text-xs font-medium text-gray-700 dark:text-gray-300"
                                    >
                                      {s.size} <span className="text-gray-400">×</span> {s.quantity}
                                    </span>
                                  ))}
                                </div>
                              </div>
                            ))}
                          </div>
                        </div>
                      )}

                      {/* New colours to add */}
                      <div>
                        <div className="flex items-center gap-2 mb-1">
                          <h3 className="text-base font-semibold text-gray-900 dark:text-white">
                            Add New Colours
                          </h3>
                        </div>
                        <p className="text-xs text-gray-500 dark:text-gray-400 mb-3">
                          New colours are saved when you click Save and are automatically synced to the linked product.
                        </p>
                        <ColorVariantManager
                          colorVariants={newColorVariants}
                          onColorVariantsChange={setNewColorVariants}
                        />
                      </div>
                    </>
                  ) : (
                    <ColorVariantManager
                      colorVariants={colorVariants}
                      onColorVariantsChange={setColorVariants}
                    />
                  )}
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
                      {canUploadShoes && <option value="shoes">Shoes</option>}
                      {canUploadRepair && <option value="cleaning_materials">Cleaning Materials</option>}
                    </select>
                  </div>

                  {isShoesMode && (
                    <>
                      <div>
                        <label htmlFor="stock-brand" className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Brand</label>
                        <input
                          id="stock-brand"
                          type="text"
                          value={formData.brand}
                          onChange={(event) => setFormData((prev) => ({ ...prev, brand: event.target.value }))}
                          className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-white"
                          placeholder="e.g., Nike, Adidas"
                        />
                      </div>
                      <div>
                        <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Shoe Type</label>
                        <button
                          type="button"
                          onClick={() => setIsShoeTypePickerOpen(true)}
                          className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-900 text-left text-gray-500 dark:text-gray-400 text-sm"
                        >
                          {selectedShoeTypes.length > 0 ? `${selectedShoeTypes.length} type(s) selected` : 'Select types...'}
                        </button>
                        {selectedShoeTypes.length > 0 && (
                          <div className="mt-2 flex flex-wrap gap-1.5">
                            {selectedShoeTypes.map((v) => (
                              <span
                                key={v}
                                className="inline-flex items-center gap-1 rounded-full bg-blue-100 px-2.5 py-1 text-xs font-medium text-blue-800 dark:bg-blue-900/30 dark:text-blue-200"
                              >
                                {shoeTypeLabelByValue[v] || v}
                                <button
                                  type="button"
                                  onClick={() => toggleShoeType(v)}
                                  className="ml-0.5 hover:text-blue-600"
                                  aria-label={`Remove ${v}`}
                                >
                                  ×
                                </button>
                              </span>
                            ))}
                          </div>
                        )}
                      </div>
                    </>
                  )}

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

                  {isShoesMode ? (
                    /* Shoes: show auto-calculated quantity as a read-only summary */
                    <div className="rounded-xl bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 p-4 flex items-center justify-between md:col-span-1">
                      <div>
                        <p className="text-sm font-medium text-blue-800 dark:text-blue-200">Total Stock (auto)</p>
                        <p className="text-xs text-blue-600 dark:text-blue-400 mt-0.5">Summed from all sizes above</p>
                      </div>
                      <span className="text-2xl font-bold text-blue-700 dark:text-blue-300">{shoesAutoQuantity} pairs</span>
                    </div>
                  ) : (
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
                  )}

                  {!isShoesMode && (
                    <div>
                      <label htmlFor="stock-unit" className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Unit</label>
                      <select
                        id="stock-unit"
                        title="Unit of measurement"
                        value={formData.unit}
                        onChange={(event) => setFormData((prev) => ({ ...prev, unit: event.target.value }))}
                        className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-white"
                      >
                        <option value="pcs">pcs (pieces)</option>
                        <option value="bottles">bottles</option>
                        <option value="sets">sets</option>
                        <option value="liters">liters</option>
                        <option value="kg">kg</option>
                        <option value="rolls">rolls</option>
                        <option value="meters">meters</option>
                        <option value="tubes">tubes</option>
                        <option value="boxes">boxes</option>
                        <option value="pairs">pairs</option>
                      </select>
                    </div>
                  )}

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

              {isShoeTypePickerOpen && (
                <div className="fixed inset-0 z-[1000001] bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
                  <div className="bg-white dark:bg-gray-800 rounded-xl w-full max-w-lg shadow-2xl border border-gray-200 dark:border-gray-700">
                    <div className="p-4 border-b border-gray-200 dark:border-gray-700">
                      <h3 className="text-lg font-semibold text-gray-900 dark:text-white">Select Shoe Types</h3>
                      <p className="text-xs text-gray-600 dark:text-gray-400 mt-1">Choose one or more types for this shoe (e.g. Men + Running).</p>
                    </div>
                    <div className="p-4 grid grid-cols-2 gap-3">
                      {shoeTypeOptions.map((option) => (
                        <label key={option.value} className="flex items-center gap-2 text-sm text-gray-800 dark:text-gray-200 cursor-pointer">
                          <input
                            type="checkbox"
                            checked={selectedShoeTypes.includes(option.value)}
                            onChange={() => toggleShoeType(option.value)}
                            className="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                          />
                          {option.label}
                        </label>
                      ))}
                    </div>
                    <div className="p-4 border-t border-gray-200 dark:border-gray-700 flex justify-end">
                      <button
                        type="button"
                        onClick={() => setIsShoeTypePickerOpen(false)}
                        className="h-9 px-5 rounded-lg bg-black text-sm font-semibold text-white hover:bg-gray-800"
                      >
                        Done
                      </button>
                    </div>
                  </div>
                </div>
              )}

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
