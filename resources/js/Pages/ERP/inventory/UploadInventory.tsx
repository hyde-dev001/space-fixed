import React, { FormEvent, useEffect, useMemo, useState } from 'react';
import { createPortal } from 'react-dom';
import { Head, usePage } from '@inertiajs/react';
import Swal from 'sweetalert2';
import AppLayoutERP from '../../../layout/AppLayout_ERP';
import { ColorVariantManager, ColorVariant, SizeVariant } from '@/components/variants/ColorVariantManager';
import { ColorVariantImageUploader, ColorVariantImage } from '@/components/variants/ColorVariantImageUploader';
import { inventoryItemAPI } from '@/services/inventoryAPI';
import type { InventoryItem as ApiInventoryItem, InventoryColorVariant, InventoryImage, InventorySize } from '@/types/inventory';

type StockCategory = 'shoes' | 'repair_materials';
type StockStatus = 'In Stock' | 'Low Stock' | 'Out of Stock';
type BusinessType = 'retail' | 'repair' | 'both';
type SizeSystem = 'US' | 'UK' | 'EU' | 'AU' | 'CN';

type StockItem = {
  id: number;
  name: string;
  brand: string;
  shoeType: string;
  category: StockCategory;
  quantity: number;
  unit: string;
  notes: string;
  reorderLevel: number;
  reorderQuantity: number;
  costPrice: number | null;
  sellingPrice: number | null;
  colorVariants: ColorVariant[];
  repairImages: ColorVariantImage[];
  imageUrl?: string;
  createdAt: string;
  deleted_at?: string | null;
};

type MetricCardProps = {
  title: string;
  value: number | string;
  icon: React.FC<{ className?: string }>;
  color?: 'success' | 'error' | 'warning' | 'info';
};

const formatUploadDate = (iso: string): string => {
  const date = new Date(iso);
  if (isNaN(date.getTime())) return iso;
  return date.toLocaleString('en-PH', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
    hour12: true,
  });
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

const ArchiveBoxIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 7.5A2.5 2.5 0 016.5 5h11A2.5 2.5 0 0120 7.5v1A2.5 2.5 0 0117.5 11h-11A2.5 2.5 0 014 8.5v-1z" />
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M7 11v6.5A2.5 2.5 0 009.5 20h5a2.5 2.5 0 002.5-2.5V11" />
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 15h4" />
  </svg>
);

const ArchiveRestoreIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 7.5A2.5 2.5 0 016.5 5h11A2.5 2.5 0 0120 7.5v1A2.5 2.5 0 0117.5 11h-11A2.5 2.5 0 014 8.5v-1z" />
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M7 11v6.5A2.5 2.5 0 009.5 20h5a2.5 2.5 0 002.5-2.5V11" />
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 14l-2-2-2 2" />
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 12v4" />
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

const normalizeBusinessType = (rawBusinessType?: string): BusinessType => {
  const normalized = (rawBusinessType ?? '').toLowerCase().trim();

  if (normalized === 'retail') return 'retail';
  if (normalized === 'repair') return 'repair';
  if (normalized.includes('both')) return 'both';

  return 'both';
};

const canonicalizeCombinedColorName = (value: string): string => {
  const tokens = String(value || '')
    .split('+')
    .map((token) => token.trim())
    .filter((token) => token.length > 0);

  const byNormalized = new Map<string, string>();

  tokens.forEach((token) => {
    const normalized = token.toLowerCase().replace(/\s+/g, ' ').trim();
    if (!normalized || byNormalized.has(normalized)) return;

    const display = token
      .replace(/\s+/g, ' ')
      .trim()
      .toLowerCase()
      .replace(/\b\w/g, (char) => char.toUpperCase());

    byNormalized.set(normalized, display);
  });

  if (byNormalized.size === 0) {
    return String(value || '').trim().replace(/\s+/g, ' ');
  }

  return Array.from(byNormalized.entries())
    .sort(([left], [right]) => left.localeCompare(right))
    .map(([, display]) => display)
    .join(' + ');
};

const canUseCategoryForBusinessType = (category: StockCategory, businessType: BusinessType): boolean => {
  if (category === 'shoes') {
    return businessType === 'retail' || businessType === 'both';
  }

  return businessType === 'repair' || businessType === 'both';
};

const mapApiItemToStock = (item: ApiInventoryItem): StockItem | null => {
  const normalizedCategory: StockCategory | null = item.category === 'shoes'
    ? 'shoes'
    : item.category === 'repair_materials'
      ? 'repair_materials'
      : null;

  if (!normalizedCategory) {
    return null;
  }

  const isShoes = normalizedCategory === 'shoes';

  // Map API color variants → ColorVariant[] for the ColorVariantManager
  // Item-level images (no color_variant_id) — used as fallback for old uploads
  const itemLevelImages = (item.images ?? []).filter(
    (img: InventoryImage) => !img.inventory_color_variant_id,
  );

  const hasSingleColorVariant = (item.color_variants?.length ?? 0) === 1;

  const colorVariants: ColorVariant[] = (item.color_variants ?? []).map((v: InventoryColorVariant, vi: number) => {
    // Use variant-specific images when available, otherwise assign all item images to the first variant
    const variantImgs = v.images?.length > 0 ? v.images : vi === 0 ? itemLevelImages : [];
    const linkedItemSizes = (item.sizes ?? []).filter(
      (size) => Number(size.inventory_color_variant_id ?? 0) === Number(v.id),
    );
    const variantSizes = (v.sizes ?? []).length > 0
      ? (v.sizes ?? [])
      : linkedItemSizes.length > 0
        ? linkedItemSizes
        : (hasSingleColorVariant && vi === 0 ? (item.sizes ?? []) : []);

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
      sizes: variantSizes.map((s: InventorySize) => ({
        id: String(s.id),
        size: s.size,
        size_system: (s.size_system ?? 'US') as SizeSystem,
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
    category: normalizedCategory,
    quantity: item.available_quantity,
    unit: item.unit,
    notes: item.notes ?? '',
    reorderLevel: item.reorder_level,
    reorderQuantity: item.reorder_quantity,
    costPrice: item.cost_price ?? null,
    sellingPrice: item.price ?? null,
    colorVariants,
    repairImages,
    imageUrl: toStorageUrl(item.main_image),
    createdAt: item.created_at,
    deleted_at: item.deleted_at ?? null,
  };
};

export default function UploadInventory() {
  const { initialData, auth } = usePage().props as any;
  const allowedInventoryImageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];
  const allowedInventoryImageMimeTypes = [
    'image/jpeg',
    'image/png',
    'image/gif',
    'image/webp',
    'image/avif',
  ];
  const inventoryImageInputAccept = '.jpg,.jpeg,.png,.gif,.webp,.avif';

  const isAllowedInventoryImageFile = (file: File) => {
    const mimeType = (file.type || '').toLowerCase();
    if (mimeType && allowedInventoryImageMimeTypes.includes(mimeType)) return true;

    const extension = file.name.split('.').pop()?.toLowerCase() || '';
    return allowedInventoryImageExtensions.includes(extension);
  };

  // Resolve the shop's business_type from either shop_owner guard or user guard's shop_owner sub-object
  const rawBusinessType: string =
    auth?.shop_owner?.business_type ??
    auth?.user?.shop_owner?.business_type ??
    'both';
  const businessType: BusinessType = normalizeBusinessType(rawBusinessType);

  // Derived capability flags
  const canUploadShoes    = businessType === 'retail' || businessType === 'both';
  const canUploadRepair   = businessType === 'repair' || businessType === 'both';
  const showToggle        = businessType === 'both';

  const mapAndFilterVisibleStocks = (items: ApiInventoryItem[]): StockItem[] =>
    items
      .map(mapApiItemToStock)
      .filter((stock): stock is StockItem => !!stock && canUseCategoryForBusinessType(stock.category, businessType));

  const [stocks, setStocks] = useState<StockItem[]>(
    () => mapAndFilterVisibleStocks(initialData?.data ?? [])
  );
  const [showArchived, setShowArchived] = useState(false);
  const [categoryFilter, setCategoryFilter] = useState<'all' | StockCategory>('all');
  const [tablePage, setTablePage] = useState(1);
  const [loadingStocks, setLoadingStocks] = useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);
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
  const [existingColorSizeDrafts, setExistingColorSizeDrafts] = useState<Record<string, { size: string; sizeSystem: SizeSystem; quantity: string }>>({});
  const [editingSizeQty, setEditingSizeQty] = useState<{ sizeId: string; value: string } | null>(null);
  const [colorImageUploading, setColorImageUploading] = useState<Record<string, boolean>>({});
  const [editSizeSystem, setEditSizeSystem] = useState<SizeSystem>('US');

  const SIZE_OPTIONS = Array.from({ length: 25 }, (_, i) => {
    const size = 3 + i * 0.5;
    return Number.isInteger(size) ? size.toFixed(0) : size.toFixed(1);
  });

  const formatSizeBySystem = (sizeValue: string, system: SizeSystem): string => {
    const parsed = Number(sizeValue);
    if (Number.isNaN(parsed)) return sizeValue;

    let converted = parsed;
    switch (system) {
      case 'UK':
      case 'AU':
        converted = parsed - 1;
        break;
      case 'EU':
      case 'CN':
        converted = parsed + 33;
        break;
      case 'US':
      default:
        converted = parsed;
        break;
    }

    return Number.isInteger(converted) ? converted.toFixed(0) : converted.toFixed(1);
  };

  const getDisplaySizeLabel = (sizeValue: string, system: SizeSystem): string =>
    `${system} ${formatSizeBySystem(sizeValue, system)}`;

  const getStoredSizeLabel = (sizeVariant: SizeVariant): string => {
    const rawSize = String(sizeVariant.size ?? '').trim();
    const matched = rawSize.match(/^(US|UK|EU|AU|CN)\s*[:\-]?\s*(.+)$/i);

    if (matched) {
      return `${matched[1].toUpperCase()} ${matched[2].trim()}`;
    }

    const system = (sizeVariant.size_system ?? 'US') as SizeSystem;
    return `${system} ${rawSize}`;
  };

  const getSizeIdentity = (sizeVariant: { size: string; size_system?: SizeSystem }): string => {
    const system = (sizeVariant.size_system ?? 'US') as SizeSystem;
    return `${system}::${sizeVariant.size}`;
  };

  const [formData, setFormData] = useState({
    name: '',
    brand: '',
    category: (canUploadShoes ? 'shoes' : 'repair_materials') as StockCategory,
    quantity: '',
    unit: 'pcs',
    notes: '',
  });

  const fetchStocks = async (archived = showArchived) => {
    setLoadingStocks(true);
    try {
      const res = await inventoryItemAPI.getAll({ per_page: 50, archived });
      setStocks(mapAndFilterVisibleStocks(res.data ?? []));
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

  const handleAddSizeToExistingColor = async (colorVariantId: string) => {
    if (!editingStock) return;

    const draft = existingColorSizeDrafts[colorVariantId] ?? { size: '', sizeSystem: editSizeSystem, quantity: '' };
    const size = draft.size.trim();
    const sizeSystem = draft.sizeSystem ?? editSizeSystem;
    const normalizedSize = formatSizeBySystem(size, sizeSystem);
    const quantity = Number(draft.quantity);

    if (!size) {
      await Swal.fire({ icon: 'warning', title: 'Size required', text: 'Please select a size.' });
      return;
    }

    if (Number.isNaN(quantity) || quantity <= 0) {
      await Swal.fire({ icon: 'warning', title: 'Invalid quantity', text: 'Quantity must be greater than 0.' });
      return;
    }

    try {
      await inventoryItemAPI.addSizeToColorVariant(editingStock.id, Number(colorVariantId), {
        size: normalizedSize,
        size_system: sizeSystem,
        quantity,
      });

      setColorVariants((prev) =>
        prev.map((variant) => {
          if (variant.id !== colorVariantId) return variant;

          const incomingIdentity = `${sizeSystem}::${normalizedSize}`;
          const existingSize = variant.sizes.find((s) => getSizeIdentity(s) === incomingIdentity);
          if (existingSize) {
            return {
              ...variant,
              sizes: variant.sizes.map((s) =>
                getSizeIdentity(s) === incomingIdentity ? { ...s, quantity: s.quantity + quantity } : s
              ),
            };
          }

          return {
            ...variant,
            sizes: [
              ...variant.sizes,
              {
                id: `${Date.now()}-${sizeSystem}-${normalizedSize}`,
                size: normalizedSize,
                size_system: sizeSystem,
                quantity,
              },
            ],
          };
        })
      );

      setExistingColorSizeDrafts((prev) => ({
        ...prev,
        [colorVariantId]: { size: '', sizeSystem: editSizeSystem, quantity: '' },
      }));

      await fetchStocks();
    } catch (err: any) {
      const msg = err?.message || 'Failed to add size to this color.';
      await Swal.fire({ icon: 'error', title: 'Unable to add size', text: msg });
    }
  };

  const handleUploadColorImages = async (colorVariantId: string, files: File[]) => {
    if (!editingStock || files.length === 0) return;

    const validFiles = files.filter(isAllowedInventoryImageFile);
    if (validFiles.length !== files.length) {
      await Swal.fire({
        icon: 'warning',
        title: 'Invalid file type',
        text: 'Only JPG, JPEG, PNG, GIF, WEBP, and AVIF images are allowed.',
      });
    }

    if (validFiles.length === 0) {
      return;
    }

    setColorImageUploading((prev) => ({ ...prev, [colorVariantId]: true }));
    try {
      const result = await inventoryItemAPI.uploadImages(editingStock.id, validFiles, Number(colorVariantId));
      const newImages: ColorVariantImage[] = (result.images ?? []).map((img: any) => ({
        id: String(img.id),
        file: null,
        preview: `/storage/${img.image_path}`,
        is_thumbnail: img.is_thumbnail ?? false,
        sort_order: img.sort_order ?? 0,
        uploaded_path: img.image_path,
      }));
      setColorVariants((prev) =>
        prev.map((v) =>
          v.id === colorVariantId ? { ...v, images: [...v.images, ...newImages] } : v,
        ),
      );
    } catch (err: any) {
      const apiMessage = typeof err?.message === 'string' ? err.message : '';
      const fieldErrors = err?.errors as Record<string, string[]> | undefined;
      const firstFieldError = fieldErrors
        ? Object.values(fieldErrors).flat().find((value) => typeof value === 'string')
        : undefined;

      await Swal.fire({
        icon: 'error',
        title: 'Upload failed',
        text: firstFieldError || apiMessage || 'Could not upload images. Please try again.',
      });
    } finally {
      setColorImageUploading((prev) => ({ ...prev, [colorVariantId]: false }));
    }
  };

  const handleDeleteColorImage = async (colorVariantId: string, imageId: string) => {
    const confirm = await Swal.fire({
      icon: 'warning',
      title: 'Delete image?',
      text: 'This image will be permanently removed.',
      showCancelButton: true,
      confirmButtonColor: '#dc2626',
      confirmButtonText: 'Delete',
      cancelButtonText: 'Cancel',
    });
    if (!confirm.isConfirmed) return;
    try {
      await inventoryItemAPI.deleteImage(Number(imageId));
      setColorVariants((prev) =>
        prev.map((v) =>
          v.id === colorVariantId
            ? { ...v, images: v.images.filter((img) => img.id !== imageId) }
            : v,
        ),
      );
    } catch {
      await Swal.fire({ icon: 'error', title: 'Failed to delete image' });
    }
  };

  const handleUpdateSizeQty = async (sizeId: string, newValue: string) => {
    if (!editingStock) return;
    const qty = Number(newValue);
    if (Number.isNaN(qty) || qty < 0) {
      setEditingSizeQty(null);
      return;
    }
    try {
      await inventoryItemAPI.updateSizeQuantity(editingStock.id, Number(sizeId), qty);
      setColorVariants((prev) =>
        prev.map((v) => ({
          ...v,
          sizes: v.sizes.map((s) => (s.id === sizeId ? { ...s, quantity: qty } : s)),
        })),
      );
      await fetchStocks();
    } catch {
      await Swal.fire({ icon: 'error', title: 'Failed to update quantity' });
    } finally {
      setEditingSizeQty(null);
    }
  };

  const totalItems = useMemo(() => stocks.length, [stocks]);
  const totalUnits = useMemo(() => stocks.reduce((sum, item) => sum + item.quantity, 0), [stocks]);
  const inStock = useMemo(() => stocks.filter((item) => getStatusByQuantity(item.quantity) === 'In Stock').length, [stocks]);
  const outOfStock = useMemo(() => stocks.filter((item) => getStatusByQuantity(item.quantity) === 'Out of Stock').length, [stocks]);

  const filteredStocks = useMemo(() => {
    if (categoryFilter === 'all') return stocks;
    return stocks.filter((stock) => stock.category === categoryFilter);
  }, [stocks, categoryFilter]);

  const itemsPerPage = 8;
  const totalPages = Math.max(1, Math.ceil(filteredStocks.length / itemsPerPage));
  const safePage = Math.min(tablePage, totalPages);
  const startIndex = (safePage - 1) * itemsPerPage;
  const paginatedStocks = filteredStocks.slice(startIndex, startIndex + itemsPerPage);

  // Auto-calculate total quantity for shoes from all size variants
  const shoesAutoQuantity = useMemo(() =>
    colorVariants.reduce((total, v) => total + v.sizes.reduce((s, sz) => s + sz.quantity, 0), 0),
  [colorVariants]);

  useEffect(() => {
    setCategoryFilter('all');
    setTablePage(1);
    void fetchStocks(showArchived);
  }, [showArchived]);

  const resetForm = () => {
    setFormData({
      name: '',
      brand: '',
      category: canUploadShoes ? 'shoes' : 'repair_materials',
      quantity: '',
      unit: 'pcs',
      notes: '',
    });
    setSelectedShoeTypes([]);
    setColorVariants([]);
    setNewColorVariants([]);
    setRepairImages([]);
    setExistingColorSizeDrafts({});
    setIsShoesMode(canUploadShoes);
    setEditingStock(null);
    setEditingSizeQty(null);
    setColorImageUploading({});
    setEditSizeSystem('US');
  };

  const handleOpenModal = (stock?: StockItem) => {
    if (stock) {
      setEditingStock(stock);
      setFormData({
        name: stock.name,
        brand: stock.brand,
        category: stock.category,
        quantity: stock.quantity.toString(),
        unit: stock.unit,
        notes: stock.notes,
      });
      setSelectedShoeTypes(stock.shoeType ? stock.shoeType.split(',').filter(Boolean) : []);
      setColorVariants(stock.colorVariants || []);
      setNewColorVariants([]);
      setRepairImages(stock.repairImages || []);
      setExistingColorSizeDrafts({});
      setIsShoesMode(stock.category === 'shoes');
      setEditingSizeQty(null);
      setColorImageUploading({});
      setEditSizeSystem('US');
    } else {
      resetForm();
    }

    setIsModalOpen(true);
  };

  const handleArchive = async (id: number, productName: string) => {
    const confirmation = await Swal.fire({
      title: 'Archive Product?',
      html: `You are about to archive <strong>${productName}</strong>.<br/>It will be removed from the active list.`,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#7c3aed',
      cancelButtonColor: '#6b7280',
      confirmButtonText: 'Yes, archive it',
      cancelButtonText: 'Cancel',
    });

    if (!confirmation.isConfirmed) return;

    try {
      await inventoryItemAPI.delete(id);
      await fetchStocks(showArchived);
      await Swal.fire({
        icon: 'success',
        title: 'Archived',
        text: `${productName} was archived successfully.`,
        timer: 1500,
        showConfirmButton: false,
      });
    } catch (err) {
      console.error('Failed to archive stock item', err);
      await Swal.fire({
        icon: 'error',
        title: 'Archive Failed',
        text: 'Could not archive item. Please try again.',
      });
    }
  };

  const handleRestore = async (id: number, productName: string) => {
    const confirmation = await Swal.fire({
      title: 'Restore Product?',
      html: `You are about to restore <strong>${productName}</strong>.<br/>It will return to the active list.`,
      icon: 'question',
      showCancelButton: true,
      confirmButtonColor: '#2563eb',
      cancelButtonColor: '#6b7280',
      confirmButtonText: 'Yes, restore it',
      cancelButtonText: 'Cancel',
    });

    if (!confirmation.isConfirmed) return;

    try {
      await inventoryItemAPI.restore(id);
      await fetchStocks(showArchived);
      await Swal.fire({
        icon: 'success',
        title: 'Restored',
        text: `${productName} was restored successfully.`,
        timer: 1500,
        showConfirmButton: false,
      });
    } catch (err) {
      console.error('Failed to restore stock item', err);
      await Swal.fire({
        icon: 'error',
        title: 'Restore Failed',
        text: 'Could not restore item. Please try again.',
      });
    }
  };

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (isSubmitting) return;

    const wasEditing = Boolean(editingStock);

    const categoryToSave: StockCategory = editingStock
      ? editingStock.category
      : (isShoesMode ? 'shoes' : 'repair_materials');

    // For shoes, use auto-calculated total from size variants; for repair materials use the manual field
    const quantityAsNumber = isShoesMode
      ? shoesAutoQuantity
      : Number(formData.quantity);

    if (!editingStock && isShoesMode) {
      const allSizes = colorVariants.flatMap((v) => v.sizes);
      if (allSizes.length === 0) {
        alert('Please add at least one size before saving stock.');
        return;
      }

      if (allSizes.some((s) => Number(s.quantity) <= 0)) {
        alert('Each size must have stock greater than 0.');
        return;
      }

      if (quantityAsNumber <= 0) {
        alert('Total stock must be greater than 0.');
        return;
      }
    }

    if (!isShoesMode && (Number.isNaN(quantityAsNumber) || quantityAsNumber <= 0 || !Number.isInteger(quantityAsNumber))) {
      alert('Quantity must be a whole number greater than 0.');
      return;
    }

    const reorderLevelNumber = isShoesMode ? 5 : 10;
    const reorderQuantityNumber = isShoesMode ? 10 : 50;
    const costPriceNumber = undefined;
    const sellingPriceNumber = undefined;

    // Only require images/variants when creating — not when editing (already uploaded)
    if (!editingStock) {
      if (isShoesMode) {
        const totalVariantImages = colorVariants.reduce((sum, variant) => sum + variant.images.length, 0);
        if (colorVariants.length === 0 || totalVariantImages === 0) {
          alert('Please add at least one color variant and upload at least one image.');
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
        ? colorVariants.map((v) => {
            const canonicalColorName = canonicalizeCombinedColorName(String(v.color_name || ''));

            return {
              color_name: canonicalColorName,
              color_code: v.color_code,
              quantity: v.sizes.reduce((sum, s) => sum + s.quantity, 0),
              sizes: v.sizes.map((s) => ({
                size: s.size,
                size_system: (s.size_system ?? 'US') as SizeSystem,
                quantity: s.quantity,
              })),
              images: v.images.map((img) => img.file).filter((f): f is File => f !== null),
            };
          })
        : undefined;

    // Keep top-level sizes undefined for shoes; size rows are scoped per color variant.
    const sizesPayload = undefined;

    if (editingStock) {
      const confirmation = await Swal.fire({
        title: 'Update Stock?',
        text: 'Your changes will be saved and synced to inventory.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#2563eb',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'Yes, update',
        cancelButtonText: 'Cancel',
      });

      if (!confirmation.isConfirmed) return;
    }

    setIsSubmitting(true);
    try {
      const resolvedUnit = isShoesMode ? 'pairs' : (formData.unit || 'pcs');

      if (editingStock) {
        await inventoryItemAPI.update(editingStock.id, {
          name: formData.name,
          category: categoryToSave,
          brand: isShoesMode ? (formData.brand || undefined) : undefined,
          description: isShoesMode ? (selectedShoeTypes.join(',') || undefined) : undefined,
          unit: resolvedUnit,
          notes: formData.notes,
          available_quantity: quantityAsNumber,
          reorder_level: reorderLevelNumber,
          reorder_quantity: reorderQuantityNumber,
          cost_price: isShoesMode ? undefined : costPriceNumber,
          price: isShoesMode ? undefined : sellingPriceNumber,
        });
        // Upload any newly-added repair images when editing
        if (imageFiles.length > 0) {
          await inventoryItemAPI.uploadImages(editingStock.id, imageFiles);
        }
        // Persist any new colour variants added in edit mode
        if (isShoesMode && newColorVariants.length > 0) {
          for (const variant of newColorVariants) {
            await inventoryItemAPI.addColor(editingStock.id, {
              color_name: canonicalizeCombinedColorName(String(variant.color_name || '')),
              color_code: variant.color_code || undefined,
              images: variant.images.map((img) => img.file).filter((f): f is File => f !== null),
              sizes: variant.sizes.map((s) => ({
                size: s.size,
                size_system: (s.size_system ?? 'US') as SizeSystem,
                quantity: s.quantity,
              })),
            });
          }
        }
      } else {
        await inventoryItemAPI.create({
          name: formData.name,
          category: categoryToSave,
          brand: isShoesMode ? (formData.brand || undefined) : undefined,
          description: isShoesMode ? (selectedShoeTypes.join(',') || undefined) : undefined,
          unit: resolvedUnit,
          notes: formData.notes,
          available_quantity: quantityAsNumber,
          reorder_level: reorderLevelNumber,
          reorder_quantity: reorderQuantityNumber,
          cost_price: isShoesMode ? undefined : costPriceNumber,
          price: isShoesMode ? undefined : sellingPriceNumber,
          images: imageFiles.length > 0 ? imageFiles : undefined,
          color_variants: colorVariantsPayload,
          sizes: sizesPayload,
        });
      }
      await fetchStocks();
    } catch (err) {
      console.error('Failed to save stock entry', err);
      const fallbackText = 'Could not save stock entry. Please try again.';
      const apiMessage = typeof (err as any)?.message === 'string' ? (err as any).message : '';
      const fieldErrors = (err as any)?.errors as Record<string, string[]> | undefined;
      const firstFieldError = fieldErrors
        ? Object.values(fieldErrors).flat().find((value) => typeof value === 'string')
        : undefined;

      await Swal.fire({
        icon: 'error',
        title: 'Update failed',
        text: firstFieldError || apiMessage || fallbackText,
      });
      return;
    } finally {
      setIsSubmitting(false);
    }

    setIsModalOpen(false);
    resetForm();

    await Swal.fire({
      icon: 'success',
      title: wasEditing ? 'Stock updated' : 'Stock uploaded successfully',
      text: wasEditing
        ? 'Your stock changes were saved successfully.'
        : 'Your stock entry has been uploaded successfully.',
      timer: 1800,
      showConfirmButton: false,
    });
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
            <div className="flex items-center gap-3">
              <button
                type="button"
                onClick={() => {
                  setShowArchived((prev) => !prev);
                }}
                className={`inline-flex items-center gap-2 rounded-lg border px-4 py-2 text-sm font-medium transition-colors ${
                  showArchived
                    ? 'border-purple-300 bg-purple-50 text-purple-700 hover:bg-purple-100 dark:border-purple-700 dark:bg-purple-900/20 dark:text-purple-300'
                    : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:bg-gray-800'
                }`}
              >
                {showArchived ? (
                  <>
                    <ArchiveRestoreIcon className="size-5" />
                    Show Active
                  </>
                ) : (
                  <>
                    <ArchiveBoxIcon className="size-5" />
                    Show Archived
                  </>
                )}
              </button>
              <button
                onClick={() => handleOpenModal()}
                className="px-5 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-medium"
              >
                + Add Stock Entry
              </button>
            </div>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-4 gap-6">
            <MetricCard title="Total Stock Items" value={totalItems} color="info" icon={BoxIcon} />
            <MetricCard title="In Stock" value={inStock} color="success" icon={CheckCircleIcon} />
            <MetricCard title="Out of Stock" value={outOfStock} color="error" icon={ExclamationIcon} />
            <MetricCard title="Total Units" value={totalUnits} color="warning" icon={TrendingUpIcon} />
          </div>

          <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm overflow-hidden">
            <div className="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
              <p className="text-sm text-gray-600 dark:text-gray-400">
                {showArchived ? 'Archived items' : 'Filter by category'}
              </p>
              <div className="sm:w-56">
                <select
                  title="Filter stock category"
                  aria-label="Filter stock category"
                  value={categoryFilter}
                  onChange={(event) => {
                    setCategoryFilter(event.target.value as 'all' | StockCategory);
                    setTablePage(1);
                  }}
                  className="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
                >
                  <option value="all">All Categories</option>
                  {canUploadRepair && <option value="repair_materials">Repair Materials</option>}
                  {canUploadShoes && <option value="shoes">Shoes</option>}
                </select>
              </div>
            </div>
            <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
              <thead className="bg-gray-50 dark:bg-gray-900">
                <tr>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Item</th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Category</th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Quantity</th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Uploaded</th>
                  <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
                </tr>
              </thead>
              <tbody className="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                {loadingStocks ? (
                  <tr><td colSpan={6} className="px-6 py-12 text-center text-gray-500 dark:text-gray-400">Loading stock entries...</td></tr>
                ) : filteredStocks.length === 0 ? (
                  <tr>
                    <td colSpan={6} className="px-6 py-12 text-center text-gray-500 dark:text-gray-400">
                      No stock entries found for this category.
                    </td>
                  </tr>
                ) : (
                  paginatedStocks.map((stock) => {
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
                          {stock.category === 'shoes' ? 'Shoes' : 'Repair Materials'}
                        </td>
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
                        <td className="px-6 py-4 text-gray-900 dark:text-white whitespace-nowrap">{formatUploadDate(stock.createdAt)}</td>
                        <td className="px-6 py-4 text-right flex items-center justify-end gap-2">
                          {!showArchived ? (
                            <>
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
                                onClick={() => handleArchive(stock.id, stock.name)}
                                className="p-2 text-red-600 hover:text-red-700 dark:hover:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition-colors"
                                title="Archive stock"
                              >
                                <ArchiveBoxIcon className="w-5 h-5" />
                              </button>
                            </>
                          ) : (
                            <button
                              onClick={() => handleRestore(stock.id, stock.name)}
                              className="p-2 text-blue-600 hover:text-blue-700 dark:hover:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg transition-colors"
                              title="Restore stock"
                            >
                              <ArchiveRestoreIcon className="w-5 h-5" />
                            </button>
                          )}
                        </td>
                      </tr>
                    );
                  })
                )}
              </tbody>
            </table>

            <div className="px-6 py-4 border-t border-gray-200 dark:border-gray-700 flex items-center justify-between">
              <p className="text-sm text-gray-600 dark:text-gray-400">
                Showing {filteredStocks.length === 0 ? 0 : startIndex + 1} to {Math.min(startIndex + itemsPerPage, filteredStocks.length)} of {filteredStocks.length} items
              </p>
              <div className="flex items-center gap-2">
                <button
                  type="button"
                  onClick={() => setTablePage((prev) => Math.max(prev - 1, 1))}
                  disabled={safePage === 1}
                  className="p-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                  title="Previous page"
                >
                  <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
                  </svg>
                </button>
                <button
                  type="button"
                  onClick={() => setTablePage((prev) => Math.min(prev + 1, totalPages))}
                  disabled={safePage === totalPages}
                  className="p-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                  title="Next page"
                >
                  <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                  </svg>
                </button>
              </div>
            </div>
          </div>
        </div>
      </AppLayoutERP>

      {isModalOpen && createPortal(
        <div className="fixed inset-0 z-[999999] bg-black/60 backdrop-blur-sm flex items-center justify-center p-2">
          <div className="bg-white dark:bg-gray-800 rounded-xl max-w-6xl w-full shadow-2xl relative flex flex-col border border-gray-200 dark:border-gray-700" style={{ height: 'calc(100vh - 1rem)' }}>
            <div className="sticky top-0 p-6 border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 rounded-t-xl z-10">
              <div className="flex items-center justify-between gap-4">
                <div>
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

                {!editingStock && showToggle ? (
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
                          category: nextIsShoes ? 'shoes' : 'repair_materials',
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
                ) : null}
              </div>
            </div>

            <form onSubmit={handleSubmit} className="flex flex-col flex-1 overflow-hidden">
              <div className="flex-1 overflow-y-auto flex flex-col gap-6 p-6 pr-2">
                {isShoesMode && (
                <div className="bg-gray-50 dark:bg-gray-900/50 rounded-lg p-6 space-y-6">
                  {editingStock ? (
                    <>
                      {/* Existing colours */}
                      {colorVariants.length > 0 && (
                        <div>
                          <div className="mb-3 flex items-center justify-between gap-3">
                            <h3 className="text-base font-semibold text-gray-900 dark:text-white">
                              Existing Colours
                            </h3>
                            <div className="inline-flex flex-wrap rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 p-1 gap-1">
                              {(['US', 'UK', 'EU', 'AU', 'CN'] as SizeSystem[]).map((system) => (
                                <button
                                  key={system}
                                  type="button"
                                  onClick={() => setEditSizeSystem(system)}
                                  className={`px-2.5 py-1 rounded-md text-[11px] font-semibold transition-colors ${
                                    editSizeSystem === system
                                      ? 'bg-blue-600 text-white'
                                      : 'text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-800'
                                  }`}
                                >
                                  {system}
                                </button>
                              ))}
                            </div>
                          </div>
                          <div className="space-y-3">
                            {colorVariants.map((cv) => {
                              const draft = existingColorSizeDrafts[cv.id] ?? { size: '', sizeSystem: editSizeSystem, quantity: '' };
                              const activeSize = cv.sizes.find((s) => s.id === editingSizeQty?.sizeId);
                              const parsedDraftQty = Number(draft.quantity);
                              const canAddSize = draft.size.trim() !== '' && !Number.isNaN(parsedDraftQty) && parsedDraftQty > 0;

                              return (
                                <div
                                  key={cv.id}
                                  className="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4 shadow-sm"
                                >
                                  <div className="flex items-center gap-3 mb-4">
                                    <div
                                      className="h-5 w-5 rounded-full border border-gray-300 dark:border-gray-600 flex-shrink-0"
                                      style={{ backgroundColor: cv.color_code }}
                                    />
                                    <span className="font-semibold text-gray-900 dark:text-white text-sm">{cv.color_name}</span>
                                    <span className="text-xs font-medium bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200 px-2 py-0.5 rounded-full">
                                      {cv.sizes.length} sizes
                                    </span>
                                    <span className="ml-auto text-xs font-semibold bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300 px-2.5 py-0.5 rounded-full">
                                      {cv.sizes.reduce((sum, s) => sum + s.quantity, 0)} units
                                    </span>
                                  </div>

                                  <div className="mb-4 rounded-lg border border-gray-200 dark:border-gray-700 p-3">
                                    <div className="flex items-center justify-between mb-2">
                                      <span className="text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-400">Images ({cv.images.length})</span>
                                      <label
                                        className="cursor-pointer inline-flex h-8 w-8 items-center justify-center rounded-lg border border-gray-300 bg-white text-blue-600 hover:border-blue-300 hover:bg-blue-50 hover:text-blue-700 transition-colors dark:border-gray-600 dark:bg-gray-900 dark:text-blue-300 dark:hover:border-blue-700"
                                        title={colorImageUploading[cv.id] ? 'Uploading images...' : 'Add images'}
                                        aria-label="Add images"
                                      >
                                        {colorImageUploading[cv.id] ? (
                                          <svg className="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z" />
                                          </svg>
                                        ) : (
                                          <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 7a2 2 0 012-2h3l1.2 1.5a2 2 0 001.56.75H19a2 2 0 012 2v7a2 2 0 01-2 2H5a2 2 0 01-2-2V7z" />
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 10v4m2-2h-4" />
                                          </svg>
                                        )}
                                        <input
                                          type="file"
                                          accept={inventoryImageInputAccept}
                                          multiple
                                          className="hidden"
                                          disabled={!!colorImageUploading[cv.id]}
                                          onChange={(e) => {
                                            const files = Array.from(e.target.files ?? []);
                                            if (files.length > 0) handleUploadColorImages(cv.id, files);
                                            e.target.value = '';
                                          }}
                                        />
                                      </label>
                                    </div>
                                    {cv.images.length > 0 ? (
                                      <div className="flex flex-wrap gap-1.5">
                                        {cv.images.map((img) => (
                                          <div key={img.id} className="relative group w-14 h-14 flex-shrink-0">
                                            <img
                                              src={img.preview}
                                              alt={cv.color_name}
                                              className="w-14 h-14 object-cover rounded-lg border border-gray-200 dark:border-gray-700"
                                            />
                                            {img.is_thumbnail && (
                                              <span className="absolute bottom-0 left-0 right-0 bg-blue-600/80 text-white text-[9px] text-center rounded-b-lg leading-tight py-0.5">
                                                Thumb
                                              </span>
                                            )}
                                            <button
                                              type="button"
                                              onClick={() => handleDeleteColorImage(cv.id, img.id)}
                                              className="absolute -top-1.5 -right-1.5 hidden group-hover:flex w-4 h-4 bg-red-500 hover:bg-red-600 text-white rounded-full items-center justify-center transition-colors"
                                              title="Delete image"
                                            >
                                              <svg className="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={3} d="M6 18L18 6M6 6l12 12" />
                                              </svg>
                                            </button>
                                          </div>
                                        ))}
                                      </div>
                                    ) : (
                                      <p className="text-xs text-gray-400 dark:text-gray-500 italic">No images — click Add Images above.</p>
                                    )}
                                  </div>

                                  <div className="mb-4 rounded-lg border border-gray-200 dark:border-gray-700 p-3">
                                    <div className="flex items-center justify-between gap-2 mb-2">
                                      <span className="text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-400">Sizes</span>
                                      <span className="text-xs text-gray-500 dark:text-gray-400">Click a size chip to adjust quantity</span>
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                      {cv.sizes.length > 0 ? (
                                        cv.sizes.map((s) => {
                                          const isEditingThis = editingSizeQty?.sizeId === s.id;
                                          return (
                                            <button
                                              key={s.id}
                                              type="button"
                                              onClick={() => setEditingSizeQty({ sizeId: s.id, value: String(s.quantity) })}
                                              className={`inline-flex items-center gap-1 px-2.5 py-1 rounded-lg border text-xs font-medium transition-colors ${
                                                isEditingThis
                                                  ? 'bg-blue-50 border-blue-300 text-blue-700 dark:bg-blue-900/20 dark:border-blue-600 dark:text-blue-300'
                                                  : 'bg-gray-50 border-gray-200 text-gray-700 hover:bg-blue-50 hover:border-blue-300 hover:text-blue-700 dark:bg-gray-800 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-blue-900/20 dark:hover:border-blue-700 dark:hover:text-blue-300'
                                              }`}
                                              title="Click to edit quantity"
                                            >
                                              <span>{getStoredSizeLabel(s)}</span>
                                              <span className="text-gray-400">x</span>
                                              <span className="font-semibold">{s.quantity}</span>
                                            </button>
                                          );
                                        })
                                      ) : (
                                        <span className="text-xs text-gray-500 dark:text-gray-400">No sizes yet.</span>
                                      )}
                                    </div>

                                    {activeSize && (
                                      <div className="mt-3 rounded-lg border border-blue-200 bg-blue-50/70 dark:border-blue-800 dark:bg-blue-900/20 p-3">
                                        <div className="flex flex-wrap items-end gap-2">
                                          <div className="min-w-[120px]">
                                            <p className="text-xs text-blue-700 dark:text-blue-300 font-medium">Editing Size</p>
                                            <p className="text-sm font-semibold text-blue-900 dark:text-blue-200">{getStoredSizeLabel(activeSize)}</p>
                                          </div>
                                          <div className="flex items-center gap-2">
                                            <button
                                              type="button"
                                              onClick={() =>
                                                setEditingSizeQty((prev) => {
                                                  if (!prev) return prev;
                                                  const current = Number(prev.value);
                                                  const next = Number.isNaN(current) ? 0 : Math.max(0, current - 1);
                                                  return { ...prev, value: String(next) };
                                                })
                                              }
                                              className="h-9 w-9 rounded-lg border border-blue-300 text-blue-700 hover:bg-blue-100 dark:border-blue-700 dark:text-blue-300 dark:hover:bg-blue-900/30"
                                              title="Decrease quantity"
                                            >
                                              -
                                            </button>
                                            <input
                                              type="number"
                                              min="0"
                                              value={editingSizeQty?.value ?? ''}
                                              onChange={(e) =>
                                                setEditingSizeQty((prev) => (prev ? { ...prev, value: e.target.value } : prev))
                                              }
                                              onKeyDown={(e) => {
                                                if (e.key === 'Enter') handleUpdateSizeQty(activeSize.id, editingSizeQty?.value ?? '');
                                                if (e.key === 'Escape') setEditingSizeQty(null);
                                              }}
                                              className="h-9 w-24 rounded-lg border border-blue-300 bg-white px-3 text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-blue-700 dark:bg-gray-900 dark:text-white"
                                              title={`Quantity for size ${getStoredSizeLabel(activeSize)}`}
                                            />
                                            <button
                                              type="button"
                                              onClick={() =>
                                                setEditingSizeQty((prev) => {
                                                  if (!prev) return prev;
                                                  const current = Number(prev.value);
                                                  const next = Number.isNaN(current) ? 1 : current + 1;
                                                  return { ...prev, value: String(next) };
                                                })
                                              }
                                              className="h-9 w-9 rounded-lg border border-blue-300 text-blue-700 hover:bg-blue-100 dark:border-blue-700 dark:text-blue-300 dark:hover:bg-blue-900/30"
                                              title="Increase quantity"
                                            >
                                              +
                                            </button>
                                          </div>
                                          <div className="flex items-center gap-2 md:ml-auto">
                                            <button
                                              type="button"
                                              onClick={() => setEditingSizeQty(null)}
                                              className="h-9 px-3 rounded-lg border border-gray-300 bg-white text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200"
                                            >
                                              Cancel
                                            </button>
                                            <button
                                              type="button"
                                              onClick={() => handleUpdateSizeQty(activeSize.id, editingSizeQty?.value ?? '')}
                                              className="h-9 px-3 rounded-lg bg-blue-600 text-white text-xs font-semibold hover:bg-blue-700"
                                            >
                                              Save Quantity
                                            </button>
                                          </div>
                                        </div>
                                      </div>
                                    )}
                                  </div>

                                  <div className="rounded-lg border border-dashed border-gray-300 dark:border-gray-700 bg-gray-50/70 dark:bg-gray-800/40 p-3">
                                    <div className="flex items-center justify-between gap-2 mb-2">
                                      <label className="text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-400">Add Size</label>
                                      <span className="text-xs text-gray-500 dark:text-gray-400">If size already exists, quantity will be added</span>
                                    </div>
                                    <div className="grid grid-cols-1 md:grid-cols-[1fr_130px_auto] gap-2 items-end">
                                      <div>
                                        <label className="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Shoe Size</label>
                                        <select
                                          title="Select size for this color"
                                          value={draft.size}
                                          onChange={(e) =>
                                            setExistingColorSizeDrafts((prev) => ({
                                              ...prev,
                                              [cv.id]: {
                                                ...((prev[cv.id] ?? { size: '', sizeSystem: editSizeSystem, quantity: '' })),
                                                size: e.target.value,
                                                sizeSystem: editSizeSystem,
                                              },
                                            }))
                                          }
                                          className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-900 text-sm text-gray-900 dark:text-white"
                                        >
                                          <option value="">Select size</option>
                                          {SIZE_OPTIONS.map((size) => (
                                            <option key={size} value={size}>{getDisplaySizeLabel(size, editSizeSystem)}</option>
                                          ))}
                                        </select>
                                      </div>
                                      <div>
                                        <label className="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Quantity</label>
                                        <input
                                          type="number"
                                          min="1"
                                          value={draft.quantity}
                                          onChange={(e) =>
                                            setExistingColorSizeDrafts((prev) => ({
                                              ...prev,
                                              [cv.id]: {
                                                ...((prev[cv.id] ?? { size: '', sizeSystem: editSizeSystem, quantity: '' })),
                                                sizeSystem: editSizeSystem,
                                                quantity: e.target.value,
                                              },
                                            }))
                                          }
                                          className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-900 text-sm text-gray-900 dark:text-white"
                                          placeholder="0"
                                        />
                                      </div>
                                      <button
                                        type="button"
                                        onClick={() => handleAddSizeToExistingColor(cv.id)}
                                        disabled={!canAddSize}
                                        className="h-10 px-4 rounded-lg bg-blue-600 text-white text-sm font-medium hover:bg-blue-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                                      >
                                        Add Size
                                      </button>
                                    </div>
                                  </div>
                                </div>
                              );
                            })}
                          </div>
                        </div>
                      )}

                      <div>
                        <ColorVariantManager
                          colorVariants={newColorVariants}
                          onColorVariantsChange={setNewColorVariants}
                          blockedColorNames={colorVariants.map((cv) => cv.color_name)}
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
                          Repair Material Images (Optional)
                        </h3>
                        <p className="text-sm text-gray-600 dark:text-gray-400 mt-0.5">
                          Add photos if available. You can save repair materials without images.
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

                    </>
                  )}

                  {!isShoesMode && (
                    <div>
                      <label htmlFor="stock-quantity" className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Quantity *</label>
                      <input
                        id="stock-quantity"
                        type="number"
                        min="0"
                        step="1"
                        value={formData.quantity}
                        onKeyDown={(event) => {
                          if (['e', 'E', '+', '-', '.'].includes(event.key)) {
                            event.preventDefault();
                          }
                        }}
                        onChange={(event) => {
                          const nextValue = event.target.value;
                          if (/^\d*$/.test(nextValue)) {
                            setFormData((prev) => ({ ...prev, quantity: nextValue }));
                          }
                        }}
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
                  disabled={isSubmitting}
                  className="h-11 w-full rounded-lg bg-black px-4 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-gray-800"
                >
                  {isSubmitting
                    ? (editingStock ? 'Updating...' : 'Saving...')
                    : (editingStock ? 'Update Stock' : 'Save Stock')}
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
