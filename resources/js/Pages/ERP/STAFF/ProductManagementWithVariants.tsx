 import React, { useState, useEffect } from 'react';
import { createPortal } from 'react-dom';
import { Head, Link } from '@inertiajs/react';
import AppLayoutERP from '../../../layout/AppLayout_ERP';
import Swal from 'sweetalert2';
import { ColorVariantManager, ColorVariant } from '@/components/variants/ColorVariantManager';

// Types
type Variant = {
  id?: number;
  size: string[];
  color: string;
  image: string;
  quantity: number;
  sku?: string;
  imageFile?: File | null;
  imagePreview?: string;
  imageGroups?: Array<{ id: string; file: File | null; preview: string }>;
};

type Product = {
  id: number;
  name: string;
  description: string | null;
  price: number;
  compare_at_price: number | null;
  brand: string | null;
  category: string;
  stock_quantity: number;
  is_active: boolean;
  main_image: string | null;
  additional_images: string[] | null;
  sizes_available: string[] | null;
  colors_available: string[] | null;
  sales_count: number;
  created_at: string;
  variants?: Variant[];
};

type ShowroomEntitlement = {
  business_type: string;
  is_eligible: boolean;
  status: string;
  has_active_subscription: boolean;
  plan_name: string | null;
  showroom_slot_limit: number;
  used_slots: number;
  remaining_slots: number;
  context_product_featured: boolean;
  can_upload_360: boolean;
};

type ColorVariantUploadResult = {
  firstColorVariantId: number | null;
  firstColorVariantImageCount: number;
};

type ExistingShowroomFrame = {
  imageId: number;
  colorVariantId: number;
  url: string;
  alt_text?: string;
  sort_order: number;
};

type SizeSystem = 'US' | 'UK' | 'EU' | 'AU' | 'CN';

const resolveImagePreviewUrl = (pathOrUrl?: string | null): string => {
  if (!pathOrUrl) return '';
  if (pathOrUrl.startsWith('http://') || pathOrUrl.startsWith('https://')) return pathOrUrl;
  if (pathOrUrl.startsWith('/storage/')) return pathOrUrl;
  if (pathOrUrl.startsWith('storage/')) return `/${pathOrUrl}`;
  return `/storage/${pathOrUrl.replace(/^\/+/, '')}`;
};

// Icon Components  
const ArrowUpIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 10l7-7m0 0l7 7m-7-7v18" />
  </svg>
);

const ArrowDownIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 14l-7 7m0 0l-7-7m7 7V3" />
  </svg>
);

const ShoppingCartIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
  </svg>
);

const CheckCircleIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
  </svg>
);

const ExclamationIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4v2m0 6H7a2 2 0 01-2-2V5a2 2 0 012-2h10a2 2 0 012 2v15a2 2 0 01-2 2z" />
  </svg>
);

const TrendingUpIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
  </svg>
);

const BulbIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path
      strokeLinecap="round"
      strokeLinejoin="round"
      strokeWidth={2}
      d="M12 3a6 6 0 00-3.6 10.8c.38.28.6.72.6 1.2V16a1 1 0 001 1h4a1 1 0 001-1v-1c0-.48.22-.92.6-1.2A6 6 0 0012 3zm-2 16h4m-3 2h2"
    />
  </svg>
);

// Metric Card Component
type MetricCardProps = {
  title: string;
  value: number | string;
  change?: number;
  changeType?: "increase" | "decrease";
  description?: string;
  color?: "success" | "error" | "warning" | "info";
  icon: React.FC<{ className?: string }>;
};

const MetricCard: React.FC<MetricCardProps> = ({
  title,
  value,
  change,
  changeType,
  icon: Icon,
  color,
  description,
}) => {
  const getColorClasses = () => {
    switch (color) {
      case "success": return "from-green-500 to-emerald-600";
      case "error": return "from-red-500 to-rose-600";
      case "warning": return "from-yellow-500 to-orange-600";
      case "info": return "from-blue-500 to-indigo-600";
      default: return "from-gray-500 to-gray-600";
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
          {change !== undefined && (
            <div className={`flex items-center gap-1 px-3 py-1 rounded-full text-xs font-semibold transition-all duration-300 ${
              changeType === "increase"
                ? "bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400"
                : "bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400"
            }`}>
              {changeType === "increase" ? <ArrowUpIcon className="size-3" /> : <ArrowDownIcon className="size-3" />}
              {Math.abs(change)}%
            </div>
          )}
        </div>
        <div className="space-y-2">
          <p className="text-sm font-medium text-gray-600 dark:text-gray-400">{title}</p>
          <h3 className="text-3xl font-bold text-gray-900 dark:text-white transition-colors duration-300">
            {typeof value === 'number' ? value.toLocaleString() : value}
          </h3>
          {description && <p className="text-xs text-gray-500 dark:text-gray-400">{description}</p>}
        </div>
      </div>
    </div>
  );
};

export default function ProductManagement() {
  const [products, setProducts] = useState<Product[]>([]);
  const [loading, setLoading] = useState(true);
  const [currentPage, setCurrentPage] = useState(1);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [editingProduct, setEditingProduct] = useState<Product | null>(null);
  const getCsrfToken = () =>
    typeof window !== 'undefined'
      ? (document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '')
      : '';
  const [tutorialVisited, setTutorialVisited] = useState(() => {
    if (typeof window === 'undefined') return false;
    try {
      const stored = JSON.parse(sessionStorage.getItem('tutorial_visited') ?? 'null');
      return stored?.v === true && stored?.c === getCsrfToken();
    } catch {
      return false;
    }
  });
  const allowed3DModelExtensions = ['jpg', 'jpeg', 'png', 'webp'];
  const accepted3DModelsInput = '.jpg,.jpeg,.png,.webp';
  const MAX_SHOWROOM_360_FILES = 120;
  const IMAGE_UPLOAD_CONCURRENCY = 4;
  const SHOWROOM_FRAME_UPLOAD_CONCURRENCY = 3;

  const runWithConcurrency = async <T, R>(
    items: T[],
    concurrency: number,
    task: (item: T, index: number) => Promise<R>
  ): Promise<R[]> => {
    if (items.length === 0) return [];

    const cappedConcurrency = Math.max(1, Math.min(concurrency, items.length));
    const results = new Array<R>(items.length);
    let nextIndex = 0;

    const worker = async () => {
      while (true) {
        const currentIndex = nextIndex;
        nextIndex += 1;
        if (currentIndex >= items.length) break;
        results[currentIndex] = await task(items[currentIndex], currentIndex);
      }
    };

    await Promise.all(Array.from({ length: cappedConcurrency }, () => worker()));
    return results;
  };

  const categoryOptions = [
    { label: 'SHOES', value: 'shoes' },
    { label: 'Women', value: 'women' },
    { label: 'Men', value: 'men' },
    { label: 'Kids', value: 'kids' },
    { label: 'Running', value: 'running' },
    { label: 'Basketball', value: 'basketball' },
    { label: 'Training', value: 'training' },
    { label: 'Casual', value: 'casual' },
    { label: 'Football', value: 'football' },
    { label: 'Slides', value: 'slides' },
    { label: 'Tennis', value: 'tennis' },
    { label: 'Loafers', value: 'loafers' },
    { label: 'Lifestyle', value: 'lifestyle' },
    { label: 'Sports', value: 'sports' },
  ];
  const categoryLabelByValue = categoryOptions.reduce<Record<string, string>>((acc, option) => {
    acc[option.value] = option.label;
    return acc;
  }, {});
  const [formData, setFormData] = useState({
    name: '',
    description: '',
    price: '',
    brand: '',
    category: 'shoes',
  });
  const [isCategoryModalOpen, setIsCategoryModalOpen] = useState(false);
  const [selectedCategories, setSelectedCategories] = useState<string[]>(['shoes']);
  
  // Variant Management (manual add)
  const [variants, setVariants] = useState<Variant[]>([
    {
      size: [],
      color: '',
      image: '',
      quantity: 0,
      imageFile: null,
      imagePreview: '',
      imageGroups: [{ id: '0', file: null, preview: '' }],
    },
  ]);
  
  // Color Variant Management (Adidas-style)
  const [colorVariants, setColorVariants] = useState<ColorVariant[]>([]);
  const [variantMode, setVariantMode] = useState<'legacy' | 'color-first'>('color-first');
  const [product3DFiles, setProduct3DFiles] = useState<File[]>([]);
  const [show3DShoeModels, setShow3DShoeModels] = useState(false);
  const [showroomEntitlement, setShowroomEntitlement] = useState<ShowroomEntitlement | null>(null);
  const [loadingShowroomEntitlement, setLoadingShowroomEntitlement] = useState(false);
  const canUse360Uploader = !!showroomEntitlement?.can_upload_360;
  const [existingShowroomFrameCount, setExistingShowroomFrameCount] = useState(0);
  const [existingShowroomFrames, setExistingShowroomFrames] = useState<ExistingShowroomFrame[]>([]);
  const [removedShowroomFrameKeys, setRemovedShowroomFrameKeys] = useState<string[]>([]);
  const hasExistingShowroomFrames = existingShowroomFrames.length > 0;
  const canToggle360Viewer = canUse360Uploader || hasExistingShowroomFrames;
  
  const [uploading, setUploading] = useState(false);
  const allowedSizeSystems = new Set<SizeSystem>(['US', 'UK', 'EU', 'AU', 'CN']);

  const normalizeSizeSystem = (sizeSystem?: string | null): SizeSystem => {
    const normalized = String(sizeSystem || '').trim().toUpperCase();
    return allowedSizeSystems.has(normalized as SizeSystem) ? (normalized as SizeSystem) : 'US';
  };

  const parseSizeWithSystem = (
    rawSize: string,
    explicitSystem?: string | null,
  ): { size: string; size_system: SizeSystem } => {
    const normalizedRaw = String(rawSize || '').trim();
    const matched = normalizedRaw.match(/^(US|UK|EU|AU|CN)\s*[:\-]?\s*(.+)$/i);

    if (matched) {
      return {
        size_system: normalizeSizeSystem(matched[1]),
        size: String(matched[2] || '').trim(),
      };
    }

    return {
      size_system: normalizeSizeSystem(explicitSystem),
      size: normalizedRaw,
    };
  };

  const formatVariantSizeForStorage = (size: string, sizeSystem?: string | null): string => {
    const normalizedSize = String(size || '').trim();
    const normalizedSystem = normalizeSizeSystem(sizeSystem);

    if (!normalizedSize) return normalizedSize;
    return normalizedSystem === 'US' ? normalizedSize : `${normalizedSystem} ${normalizedSize}`;
  };

  const isShowroomFrameImageType = (imageType?: string | null) => {
    const normalized = String(imageType || '').trim().toLowerCase();
    return ['showroom_360', 'virtual_showroom', 'showroom', '360'].includes(normalized);
  };

  const getShowroomFrameKey = (frame: ExistingShowroomFrame) => `${frame.colorVariantId}:${frame.imageId}`;

  const isShowroomFrameMarkedForRemoval = (frame: ExistingShowroomFrame) =>
    removedShowroomFrameKeys.includes(getShowroomFrameKey(frame));

  const markShowroomFrameForRemoval = (frame: ExistingShowroomFrame) => {
    const key = getShowroomFrameKey(frame);
    setRemovedShowroomFrameKeys((prev) => (prev.includes(key) ? prev : [...prev, key]));
  };

  const restoreShowroomFrame = (frame: ExistingShowroomFrame) => {
    const key = getShowroomFrameKey(frame);
    setRemovedShowroomFrameKeys((prev) => prev.filter((existingKey) => existingKey !== key));
  };

  // Inventory stock picker
  const [isStockPickerOpen, setIsStockPickerOpen] = useState(false);
  const [inventoryStocks, setInventoryStocks] = useState<any[]>([]);
  const [isLoadingStocks, setIsLoadingStocks] = useState(false);
  const [stockSearch, setStockSearch] = useState('');
  const [selectedInventoryItem, setSelectedInventoryItem] = useState<any | null>(null);

  useEffect(() => {
    fetchProducts();
    fetchShowroomEntitlement();
  }, []);

  useEffect(() => {
    if (!canUse360Uploader) {
      setProduct3DFiles([]);
      if (show3DShoeModels && !hasExistingShowroomFrames) {
        setShow3DShoeModels(false);
      }
    }
  }, [canUse360Uploader, show3DShoeModels, hasExistingShowroomFrames]);

  useEffect(() => {
    setFormData((prev) => ({
      ...prev,
      category: selectedCategories.join(','),
    }));
  }, [selectedCategories]);

  const fetchProducts = async () => {
    try {
      setLoading(true);
      const productsResponse = await fetch('/api/products/my/products', {
        credentials: 'include',
        headers: { 'Accept': 'application/json' }
      });

      if (!productsResponse.ok) throw new Error('Failed to fetch products');

      const productsData = await productsResponse.json();
      setProducts(productsData.products || []);
    } catch (error) {
      console.error('Error fetching products:', error);
      Swal.fire({
        title: 'Error',
        text: 'Failed to load products',
        icon: 'error',
        confirmButtonColor: '#000000',
      });
    } finally {
      setLoading(false);
    }
  };

  const fetchShowroomEntitlement = async (productId?: number) => {
    try {
      setLoadingShowroomEntitlement(true);
      const query = productId ? `?product_id=${productId}` : '';
      const response = await fetch(`/api/products/meta/showroom-entitlement${query}`, {
        credentials: 'include',
        headers: { 'Accept': 'application/json' },
      });

      const data = await response.json();

      if (!response.ok) {
        setShowroomEntitlement(null);
        return;
      }

      setShowroomEntitlement(data.entitlement || null);
    } catch (error) {
      console.error('Error fetching showroom entitlement:', error);
      setShowroomEntitlement(null);
    } finally {
      setLoadingShowroomEntitlement(false);
    }
  };

  const fetchInventoryStocks = async () => {
    setIsLoadingStocks(true);
    try {
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
      const response = await fetch('/api/erp/inventory/items?category=shoes&per_page=200&available_for_product=1', {
        credentials: 'include',
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken || '' },
      });
      if (!response.ok) throw new Error('Failed to fetch inventory');
      const data = await response.json();
      setInventoryStocks(data.data || []);
    } catch (error) {
      console.error('Error fetching inventory stocks:', error);
      setInventoryStocks([]);
    } finally {
      setIsLoadingStocks(false);
    }
  };

  const handleOpenModal = async (product?: Product) => {
    setShow3DShoeModels(false);
    setProduct3DFiles([]);
    setExistingShowroomFrameCount(0);
    setExistingShowroomFrames([]);
    setRemovedShowroomFrameKeys([]);
    await fetchShowroomEntitlement(product?.id);

    if (product) {
      setEditingProduct(product);
      const parsedCategories = (product.category || '')
        .split(',')
        .map((value) => value.trim().toLowerCase())
        .filter(Boolean);
      setFormData({
        name: product.name,
        description: product.description || '',
        price: product.price.toString(),
        brand: product.brand || '',
        category: product.category,
      });
      setSelectedCategories(parsedCategories.length > 0 ? parsedCategories : ['shoes']);
      
      // Load color variants for this product
      try {
        const colorVariantsResponse = await fetch(`/api/products/${product.id}/color-variants`, {
          credentials: 'include',
          headers: { 'Accept': 'application/json' }
        });
        
        if (colorVariantsResponse.ok) {
          const colorVariantsData = await colorVariantsResponse.json();
          const loadedColorVariants = colorVariantsData.color_variants || [];

          const loadedShowroomFrames = loadedColorVariants.reduce((sum: number, cv: any) => {
            const frameCount = (cv.images || []).filter((img: any) => isShowroomFrameImageType(img.image_type)).length;
            return sum + frameCount;
          }, 0);
          setExistingShowroomFrameCount(loadedShowroomFrames);

          const extractedShowroomFrames: ExistingShowroomFrame[] = loadedColorVariants
            .flatMap((cv: any) => {
              return (cv.images || [])
                .filter((img: any) => isShowroomFrameImageType(img.image_type))
                .map((img: any) => ({
                  imageId: Number(img.id),
                  colorVariantId: Number(cv.id),
                  url: resolveImagePreviewUrl(img.image_url || img.image_path),
                  alt_text: img.alt_text || '',
                  sort_order: Number(img.sort_order || 0),
                }));
            })
            .filter((frame: ExistingShowroomFrame) => !!frame.imageId && !!frame.colorVariantId && !!frame.url)
            .sort((a, b) => a.sort_order - b.sort_order);

          setExistingShowroomFrames(extractedShowroomFrames);
          setRemovedShowroomFrameKeys([]);
          if (extractedShowroomFrames.length > 0) {
            setShow3DShoeModels(true);
          }
          
          if (loadedColorVariants.length > 0) {
            // Product has color variants - use color-first mode
            setVariantMode('color-first');
            
            // Transform loaded color variants to the expected format
            setColorVariants(loadedColorVariants.map((cv: any) => ({
              id: cv.id?.toString() || Date.now().toString(),
              color_name: cv.color_name,
              color_code: cv.color_code,
              images: (cv.images || [])
                .filter((img: any) => !isShowroomFrameImageType(img.image_type))
                .map((img: any) => ({
                  id: img.id?.toString() || Date.now().toString(),
                  file: null,
                  preview: resolveImagePreviewUrl(img.image_url || img.image_path),
                  uploaded_path: img.image_path || '',
                  is_thumbnail: img.is_thumbnail || false,
                  sort_order: img.sort_order || 0,
                  alt_text: img.alt_text || '',
                  image_type: img.image_type || 'product',
                })),
              sizes: (cv.sizes || []).map((size: any) => ({
                ...parseSizeWithSystem(size.size?.toString() || '', size.size_system),
                id: size.id?.toString() || Date.now().toString(),
                quantity: size.quantity || 0,
                sku: size.sku || '',
              })),
              isExpanded: false,
            })));
          } else {
            // No color variants - try loading legacy variants
            setVariantMode('legacy');
            await loadLegacyVariants(product.id);
          }
        } else {
          // Fallback to legacy mode
          setVariantMode('legacy');
          await loadLegacyVariants(product.id);
        }
      } catch (error) {
        console.error('Error loading color variants:', error);
        // Fallback to legacy mode
        setVariantMode('legacy');
        await loadLegacyVariants(product.id);
      }
    } else {
      // New products must be seeded from an uploaded inventory stock item
      setEditingProduct(null);
      setSelectedInventoryItem(null);
      setColorVariants([]);
      setExistingShowroomFrameCount(0);
      setExistingShowroomFrames([]);
      setRemovedShowroomFrameKeys([]);
      setStockSearch('');
      fetchInventoryStocks();
      setIsStockPickerOpen(true);
      return; // stock picker opens; isModalOpen stays false until a stock is chosen
    }

    setIsModalOpen(true);
  };

  const handleSelectStock = (stock: any) => {
    setSelectedInventoryItem(stock);
    const shoeTypes = (stock.description || '').split(',').map((s: string) => s.trim()).filter(Boolean);
    const derivedCategories: string[] = ['shoes', ...shoeTypes];
    setFormData({
      name: stock.name,
      description: '',
      price: '',
      brand: stock.brand || '',
      category: derivedCategories.join(','),
    });
    setSelectedCategories(derivedCategories);

    // Map inventory color variants → ColorVariant[] for the product form
    const mappedColorVariants: ColorVariant[] = (stock.color_variants ?? []).map((v: any) => ({
      id: String(v.id),
      color_name: v.color_name,
      color_code: v.color_code ?? '#000000',
      isExpanded: true,
      images: (v.images ?? []).map((img: any) => ({
        id: String(img.id),
        file: null,
        preview: img.image_path ? `/storage/${img.image_path}` : '',
        is_thumbnail: img.is_thumbnail ?? false,
        sort_order: img.sort_order ?? 0,
        uploaded_path: img.image_path,
      })),
      // Sizes are stored at item level and are shared across all color variants
      sizes: (stock.sizes ?? []).map((s: any) => ({
        ...parseSizeWithSystem(String(s.size), s.size_system),
        id: String(s.id),
        quantity: s.quantity,
        sku: '',
      })),
    }));

    setColorVariants(mappedColorVariants);
    setIsStockPickerOpen(false);
    setIsModalOpen(true);
  };

  // Helper function to load legacy variants
  const loadLegacyVariants = async (productId: number) => {
    try {
      const response = await fetch(`/api/products/${productId}/variants`, {
        credentials: 'include',
        headers: { 'Accept': 'application/json' }
      });
      
      if (response.ok) {
        const data = await response.json();
        const loadedVariants = data.variants || [];
        
        setVariants(loadedVariants.map((v: Variant, idx: number) => ({
          ...v,
          size: Array.isArray(v.size)
            ? v.size
            : (v.size || '')
                .split(',')
                .map((s) => s.trim())
                .filter(Boolean),
          imageFile: null,
          imagePreview: v.image,
          imageGroups: v.image
            ? [{ id: `${idx}-0`, file: null, preview: v.image }]
            : [{ id: `${idx}-0`, file: null, preview: '' }],
        })));
      }
    } catch (error) {
      console.error('Error loading variants:', error);
    }
  };

  const toggleCategorySelection = (value: string) => {
    setSelectedCategories((prev) => {
      if (prev.includes(value)) {
        if (prev.length === 1) {
          return prev;
        }
        return prev.filter((item) => item !== value);
      }
      return [...prev, value];
    });
  };

  const getFileExtension = (fileName: string) => {
    const parts = fileName.toLowerCase().split('.');
    return parts.length > 1 ? parts[parts.length - 1] : '';
  };

  const formatSizeChipLabel = (rawSize: string, quantity: number, rawSystem?: string | null) => {
    const parsed = parseSizeWithSystem(rawSize, rawSystem);
    const sizeSystem = parsed.size_system;
    const sizeValue = parsed.size || '-';
    const pairLabel = quantity === 1 ? 'pair' : 'pairs';

    return `Size ${sizeValue} (${sizeSystem}) • ${quantity} ${pairLabel}`;
  };

  const isAllowed3DModelFile = (file: File) => {
    const extension = getFileExtension(file.name);
    return allowed3DModelExtensions.includes(extension);
  };

  const formatBytes = (bytes: number) => {
    if (bytes === 0) return '0 Bytes';
    const units = ['Bytes', 'KB', 'MB', 'GB'];
    const exponent = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
    const value = bytes / Math.pow(1024, exponent);
    return `${value.toFixed(exponent === 0 ? 0 : 2)} ${units[exponent]}`;
  };

  const handle3DModelFilesChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    if (!canUse360Uploader) {
      Swal.fire({
        title: 'Premium Required',
        text: 'Shoe Spin Viewer uploads are currently unavailable.',
        icon: 'warning',
        confirmButtonColor: '#000000',
      });
      e.target.value = '';
      return;
    }

    const pickedFiles = Array.from(e.target.files || []);

    if (pickedFiles.length === 0) return;

    const validFiles = pickedFiles.filter(isAllowed3DModelFile);
    const invalidFiles = pickedFiles.filter((file) => !isAllowed3DModelFile(file));

    if (invalidFiles.length > 0) {
      Swal.fire({
        title: 'Unsupported File Format',
        text: `Only image sequence files (${allowed3DModelExtensions.join(', ').toUpperCase()}) are allowed.`,
        icon: 'warning',
        confirmButtonColor: '#000000',
      });
    }

    if (validFiles.length > 0) {
      const existingKeys = new Set(product3DFiles.map((file) => `${file.name}-${file.size}-${file.lastModified}`));
      const newUniqueFiles = validFiles.filter(
        (file) => !existingKeys.has(`${file.name}-${file.size}-${file.lastModified}`)
      );

      const remainingSlots = Math.max(MAX_SHOWROOM_360_FILES - product3DFiles.length, 0);

      if (remainingSlots === 0) {
        Swal.fire({
          title: 'Frame Limit Reached',
          text: `You can upload up to ${MAX_SHOWROOM_360_FILES} spin frames.`,
          icon: 'warning',
          confirmButtonColor: '#000000',
        });
      } else {
        if (newUniqueFiles.length > remainingSlots) {
          Swal.fire({
            title: 'Frame Limit Applied',
            text: `Only ${remainingSlots} more frame(s) can be added (max ${MAX_SHOWROOM_360_FILES}).`,
            icon: 'info',
            confirmButtonColor: '#000000',
          });
        }

        setProduct3DFiles((prev) => [...prev, ...newUniqueFiles.slice(0, remainingSlots)]);
      }
    }

    e.target.value = '';
  };

  const remove3DModelFile = (index: number) => {
    setProduct3DFiles((prev) => prev.filter((_, fileIndex) => fileIndex !== index));
  };

  const addVariantRow = () => {
    setVariants(prev => [...prev, {
      size: [],
      color: '',
      image: '',
      quantity: 0,
      imageFile: null,
      imagePreview: '',
      imageGroups: [{ id: Date.now().toString(), file: null, preview: '' }],
    }]);
  };

  const removeVariantRow = (index: number) => {
    setVariants(prev => prev.filter((_, i) => i !== index));
  };

  const updateVariant = (index: number, updates: Partial<Variant>) => {
    setVariants(prev => prev.map((v, i) => (i === index ? { ...v, ...updates } : v)));
  };

  const sizeOptions = Array.from({ length: 25 }, (_, i) => (3 + i * 0.5))
    .map((n) => (Number.isInteger(n) ? n.toFixed(0) : n.toFixed(1)));
  const [openSizePickerIndex, setOpenSizePickerIndex] = useState<number | null>(null);

  const toggleSizeOption = (variantIndex: number, sizeValue: string) => {
    setVariants(prev => prev.map((v, i) => {
      if (i !== variantIndex) return v;
      const exists = v.size.includes(sizeValue);
      const nextSizes = exists
        ? v.size.filter((s) => s !== sizeValue)
        : [...v.size, sizeValue];
      return { ...v, size: nextSizes };
    }));
  };

  const handleVariantImageChange = (variantIndex: number, imageId: string, e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (file) {
      const reader = new FileReader();
      reader.onloadend = () => {
        setVariants(prev => prev.map((v, i) => {
          if (i !== variantIndex) return v;
          const groups = (v.imageGroups || []).map(group =>
            group.id === imageId ? { ...group, file, preview: reader.result as string } : group
          );
          return { ...v, imageGroups: groups };
        }));
      };
      reader.readAsDataURL(file);
    }
  };

  const addVariantImage = (variantIndex: number) => {
    setVariants(prev => prev.map((v, i) => {
      if (i !== variantIndex) return v;
      const groups = v.imageGroups || [];
      return {
        ...v,
        imageGroups: [...groups, { id: `${variantIndex}-${Date.now()}`, file: null, preview: '' }],
      };
    }));
  };

  const removeVariantImage = (variantIndex: number, imageId: string) => {
    setVariants(prev => prev.map((v, i) => {
      if (i !== variantIndex) return v;
      const groups = (v.imageGroups || []).filter(group => group.id !== imageId);
      return { ...v, imageGroups: groups.length ? groups : [{ id: `${variantIndex}-0`, file: null, preview: '' }] };
    }));
  };

  const handleVariantQuantityChange = (index: number, quantity: number) => {
    updateVariant(index, { quantity: Math.max(0, quantity) });
  };

  const uploadVariantImages = async (): Promise<Map<number, string[]>> => {
    const uploadedPaths = new Map<number, string[]>();
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

    for (const [index, variant] of variants.entries()) {
      const groupPaths: string[] = [];
      const groups = variant.imageGroups || [];

      for (const group of groups) {
        if (group.file) {
          try {
            const uploadData = new FormData();
            uploadData.append('image', group.file);

            const response = await fetch('/api/products/upload-image', {
              method: 'POST',
              credentials: 'include',
              headers: {
                'X-CSRF-TOKEN': csrfToken || '',
              },
              body: uploadData,
            });

            const data = await response.json();
            if (response.ok) {
              groupPaths.push(data.path);
            }
          } catch (error) {
            console.error(`Error uploading image for variant ${index}:`, error);
          }
        } else if (group.preview) {
          groupPaths.push(group.preview);
        }
      }

      if (groupPaths.length > 0) {
        uploadedPaths.set(index, groupPaths);
      }
    }

    return uploadedPaths;
  };

  // Upload color variant images and create color variants via API
  const uploadColorVariantImages = async (productId: number): Promise<ColorVariantUploadResult> => {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    let firstColorFirstImage: string | null = null;
    let isFirstColor = true;
    const uploadResult: ColorVariantUploadResult = {
      firstColorVariantId: null,
      firstColorVariantImageCount: 0,
    };

    // If editing, only delete colors removed from the form. Keep existing colors/images intact.
    if (editingProduct) {
      try {
        const existingResponse = await fetch(`/api/products/${productId}/color-variants`, {
          credentials: 'include',
          headers: { 'Accept': 'application/json' }
        });
        
        if (existingResponse.ok) {
          const existingData = await existingResponse.json();
          const existingVariants = existingData.color_variants || [];
          const keptColorNames = new Set(
            colorVariants.map((variant) => String(variant.color_name || '').trim().toLowerCase()).filter(Boolean)
          );
          
          for (const variant of existingVariants) {
            const variantColorName = String(variant?.color_name || '').trim().toLowerCase();
            const shouldDelete = !!variant.id && variantColorName && !keptColorNames.has(variantColorName);

            if (shouldDelete) {
              await fetch(`/api/products/${productId}/color-variants/${variant.id}`, {
                method: 'DELETE',
                credentials: 'include',
                headers: {
                  'Accept': 'application/json',
                  'X-CSRF-TOKEN': csrfToken || '',
                },
              });
            }
          }
        }
      } catch (error) {
        console.error('Error deleting existing color variants:', error);
      }
    }

    for (const colorVariant of colorVariants) {
      try {
        // First upload all NEW images for this color (skip already uploaded ones)
        const uploadedImages: any[] = [];
        let isFirstImageInColor = true;

        const queuedUploads: Array<{ image: any; imageIndex: number }> = colorVariant.images
          .map((image: any, imageIndex: number) => ({ image, imageIndex }))
          .filter(({ image }: { image: any }) => !!image.file);

        const uploadResponses = await runWithConcurrency<
          { image: any; imageIndex: number },
          { imageIndex: number; payload: any } | null
        >(
          queuedUploads,
          IMAGE_UPLOAD_CONCURRENCY,
          async ({ image, imageIndex }: { image: any; imageIndex: number }) => {
            const uploadData = new FormData();
            uploadData.append('image', image.file as File);

            const response = await fetch('/api/products/upload-image', {
              method: 'POST',
              credentials: 'include',
              headers: {
                'X-CSRF-TOKEN': csrfToken || '',
              },
              body: uploadData,
            });

            const data = await response.json();

            if (!response.ok) {
              console.error(`Failed to upload image: ${data.message || 'Unknown error'}`);
              return null;
            }

            return {
              imageIndex,
              payload: {
                path: data.path,
                alt_text: image.alt_text || `${colorVariant.color_name} ${colorVariant.color_name}`,
                is_thumbnail: image.is_thumbnail,
                sort_order: image.sort_order,
                image_type: (image as any).image_type || 'product',
              },
            };
          }
        );

        const uploadedByImageIndex = new Map<number, any>();
        uploadResponses.forEach((entry: { imageIndex: number; payload: any } | null) => {
          if (entry?.imageIndex !== undefined && entry?.payload) {
            uploadedByImageIndex.set(entry.imageIndex, entry.payload);
          }
        });
        
        for (const [imageIndex, image] of colorVariant.images.entries()) {
          // Skip if image is already uploaded (has uploaded_path but no new file)
          if (image.uploaded_path && !image.file) {
            // Normalize path: ensure /storage/ prefix so main_image resolves correctly
            const normalizedPath =
              image.uploaded_path.startsWith('http') || image.uploaded_path.startsWith('/storage/')
                ? image.uploaded_path
                : `/storage/${image.uploaded_path}`;

            // During edit, keep already-uploaded images on backend to avoid duplicates and frame loss.
            if (!editingProduct) {
              uploadedImages.push({
                path: normalizedPath,
                alt_text: image.alt_text || '',
                is_thumbnail: image.is_thumbnail,
                sort_order: image.sort_order,
                image_type: (image as any).image_type || 'product',
              });
            }

            // Save the first image of the first color as main_image
            if (isFirstColor) {
              if (image.is_thumbnail) {
                firstColorFirstImage = normalizedPath;
              } else if (!firstColorFirstImage && isFirstImageInColor) {
                firstColorFirstImage = normalizedPath;
              }
            }
            
            isFirstImageInColor = false;
          }
          // Only upload if there's a new file
          else if (image.file) {
            const uploadedPayload = uploadedByImageIndex.get(imageIndex);
            if (uploadedPayload) {
              uploadedImages.push(uploadedPayload);

              // Save the first image of the first color as main_image
              // Priority: thumbnail from first color > first image of first color
              if (isFirstColor) {
                if (image.is_thumbnail) {
                  firstColorFirstImage = uploadedPayload.path;
                } else if (!firstColorFirstImage && isFirstImageInColor) {
                  firstColorFirstImage = uploadedPayload.path;
                }
              }

              isFirstImageInColor = false;
            }
          }
        }
        
        isFirstColor = false;

        // Skip if no images and no sizes (completely empty variant)
        if (uploadedImages.length === 0 && colorVariant.sizes.length === 0) {
          console.warn(`Empty color variant ${colorVariant.color_name}, skipping...`);
          continue;
        }

        // For variants with sizes but no images, show a warning but continue
        if (uploadedImages.length === 0) {
          console.warn(`Color variant ${colorVariant.color_name} has no images but has sizes. Creating variant anyway.`);
        }

        // Create color variant with images
        const colorVariantData = {
          color_name: colorVariant.color_name,
          color_code: colorVariant.color_code,
          is_active: true,
          sort_order: 0,
          images: uploadedImages,
        };

        const cvResponse = await fetch(`/api/products/${productId}/color-variants`, {
          method: 'POST',
          credentials: 'include',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrfToken || '',
          },
          body: JSON.stringify(colorVariantData),
        });

        const cvResponseData = await cvResponse.json();

        if (!cvResponse.ok) {
          console.error(`Failed to create color variant: ${cvResponseData.message || 'Unknown error'}`);
        } else if (!uploadResult.firstColorVariantId && cvResponseData?.color_variant?.id) {
          uploadResult.firstColorVariantId = cvResponseData.color_variant.id;
          uploadResult.firstColorVariantImageCount = Array.isArray(cvResponseData?.color_variant?.images)
            ? cvResponseData.color_variant.images.length
            : uploadedImages.length;
        }

        // Create size variants (for backward compatibility with existing ProductVariant system)
        for (const sizeVariant of colorVariant.sizes) {
          const variantData = {
            size: sizeVariant.size,
            color: colorVariant.color_name,
            quantity: sizeVariant.quantity,
            image: uploadedImages[0]?.path || '', // Use first image as variant image
            sku: sizeVariant.sku || null,
          };

          // This will be handled by the product creation/update API
        }

      } catch (error) {
        console.error(`Error creating color variant for ${colorVariant.color_name}:`, error);
      }
    }

    // Update product main_image with the first color's first image (or thumbnail if marked)
    if (firstColorFirstImage) {
      try {
        await fetch(`/api/products/${productId}`, {
          method: 'PUT',
          credentials: 'include',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrfToken || '',
          },
          body: JSON.stringify({
            main_image: firstColorFirstImage,
          }),
        });
      } catch (error) {
        console.error('Error updating product main_image:', error);
      }
    }

    return uploadResult;
  };

  const uploadShowroom360Images = async (
    productId: number,
    colorVariantId: number,
  ) => {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    let existingColorImagesCount = 0;

    try {
      const colorVariantsResponse = await fetch(`/api/products/${productId}/color-variants`, {
        credentials: 'include',
        headers: { 'Accept': 'application/json' },
      });

      if (colorVariantsResponse.ok) {
        const colorVariantsData = await colorVariantsResponse.json();
        const matchedColorVariant = (colorVariantsData.color_variants || []).find(
          (variant: any) => Number(variant.id) === Number(colorVariantId),
        );
        existingColorImagesCount = Array.isArray(matchedColorVariant?.images)
          ? matchedColorVariant.images.length
          : 0;
      }
    } catch (error) {
      console.error('Error loading current color-variant image count:', error);
    }

    const remainingVariantSlots = Math.max(MAX_SHOWROOM_360_FILES - existingColorImagesCount, 0);

    if (remainingVariantSlots <= 0) {
      throw new Error('No image slots left in the selected color variant for Shoe Spin Viewer frames.');
    }

    if (product3DFiles.length > remainingVariantSlots) {
      throw new Error(`Only ${remainingVariantSlots} frame(s) can be uploaded for Shoe Spin Viewer in this product setup.`);
    }

    await runWithConcurrency(
      product3DFiles,
      SHOWROOM_FRAME_UPLOAD_CONCURRENCY,
      async (frameFile, frameIndex) => {
        const formData = new FormData();
        formData.append('image', frameFile);
        formData.append('alt_text', `Showroom 360 frame ${frameIndex + 1}`);
        formData.append('image_type', 'showroom_360');
        formData.append('assign_to_showroom', '1');
        formData.append('sort_order', String(frameIndex));

        const response = await fetch(`/api/products/${productId}/color-variants/${colorVariantId}/images`, {
          method: 'POST',
          credentials: 'include',
          headers: {
            'X-CSRF-TOKEN': csrfToken || '',
            'Accept': 'application/json',
          },
          body: formData,
        });

        const data = await response.json();
        if (!response.ok) {
          throw new Error(data.message || `Failed to upload Shoe Spin Viewer frame ${frameIndex + 1}`);
        }

        return data;
      }
    );
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    // Validation
    if (!formData.name || (!editingProduct && !formData.price)) {
      Swal.fire({
        title: 'Missing Information',
        text: editingProduct ? 'Please fill in product name' : 'Please fill in product name and price',
        icon: 'warning',
        confirmButtonColor: '#000000',
      });
      return;
    }

    // Validate color variants
    if (colorVariants.length === 0) {
      Swal.fire({
        title: 'Missing Color Variants',
        text: 'Please add at least one color variant',
        icon: 'warning',
        confirmButtonColor: '#000000',
      });
      return;
    }

    const hasImagesIssue = colorVariants.some(cv => cv.images.length === 0);
    if (hasImagesIssue) {
      Swal.fire({
        title: 'Missing Images',
        text: 'Each color must have at least one image',
        icon: 'warning',
        confirmButtonColor: '#000000',
      });
      return;
    }

    const hasSizeIssue = colorVariants.some(cv => cv.sizes.length === 0);
    if (hasSizeIssue) {
      Swal.fire({
        title: 'Missing Sizes',
        text: 'Each color must have at least one size with quantity',
        icon: 'warning',
        confirmButtonColor: '#000000',
      });
      return;
    }

    const totalStock = colorVariants.reduce((sum, cv) => 
      sum + cv.sizes.reduce((s, size) => s + size.quantity, 0), 0
    );

    if (totalStock === 0) {
      Swal.fire({
        title: 'No Stock',
        text: 'Please set quantity for at least one size variant',
        icon: 'warning',
        confirmButtonColor: '#000000',
      });
      return;
    }

    if (show3DShoeModels && product3DFiles.length > 0 && !canUse360Uploader) {
      Swal.fire({
        title: 'Premium Required',
        text: 'Shoe Spin Viewer uploads are currently unavailable.',
        icon: 'warning',
        confirmButtonColor: '#000000',
      });
      return;
    }

    try {
      setUploading(true);

      let productData;
      let createdProductId: number | null = null;

      // Color-first approach (only mode now)
      const totalStock = colorVariants.reduce((sum, cv) => 
        sum + cv.sizes.reduce((s, size) => s + size.quantity, 0), 0
      );

      const uniqueSizes = [
        ...new Set(
          colorVariants.flatMap((cv) =>
            cv.sizes.map((s) => formatVariantSizeForStorage(s.size, s.size_system))
          )
        )
      ];
      const uniqueColors = colorVariants.map(cv => cv.color_name);

      // Prepare variant data for backward compatibility
      const variantData = colorVariants.flatMap(cv => 
        cv.sizes.map(size => ({
          size: formatVariantSizeForStorage(size.size, size.size_system),
          color: cv.color_name,
          quantity: size.quantity,
          image: '', // Will be set after color variant creation
          sku: size.sku || null,
        }))
      );

      const baseProductData = {
        name: formData.name,
        description: formData.description || null,
        brand: formData.brand || null,
        category: formData.category,
        stock_quantity: totalStock,
        inventory_item_id: !editingProduct && selectedInventoryItem ? selectedInventoryItem.id : undefined,
        sizes_available: uniqueSizes,
        colors_available: uniqueColors,
        main_image: null, // Will be set from color variants
        additional_images: null,
        variants: variantData,
      };

      productData = editingProduct
        ? baseProductData
        : {
            ...baseProductData,
            price: parseFloat(formData.price),
          };

      const url = editingProduct
        ? `/api/products/${editingProduct.id}`
        : '/api/products/';

      const method = editingProduct ? 'PUT' : 'POST';
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

      const response = await fetch(url, {
        method,
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrfToken || '',
        },
        body: JSON.stringify(productData),
      });

      if (!response.ok) {
        const error = await response.json();
        throw new Error(error.message || 'Failed to save product');
      }

      const result = await response.json();
      createdProductId = result.product?.id || editingProduct?.id;

      // Upload color variants with images
      let colorVariantUploadResult: ColorVariantUploadResult | null = null;
      if (createdProductId) {
        colorVariantUploadResult = await uploadColorVariantImages(createdProductId);
      }

      if (createdProductId && editingProduct && removedShowroomFrameKeys.length > 0) {
        const framesToDelete = existingShowroomFrames.filter((frame) =>
          removedShowroomFrameKeys.includes(getShowroomFrameKey(frame))
        );

        for (const frame of framesToDelete) {
          const deleteResponse = await fetch(
            `/api/products/${createdProductId}/color-variants/${frame.colorVariantId}/images/${frame.imageId}`,
            {
              method: 'DELETE',
              credentials: 'include',
              headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken || '',
              },
            }
          );

          let deletePayload: any = null;
          try {
            deletePayload = await deleteResponse.json();
          } catch (_e) {
            deletePayload = null;
          }

          if (!deleteResponse.ok) {
            throw new Error(deletePayload?.message || 'Failed to remove saved Shoe Spin frames.');
          }
        }
      }

      if (createdProductId && show3DShoeModels && product3DFiles.length > 0) {
        if (!canUse360Uploader) {
          throw new Error('Shoe Spin Viewer uploads are currently unavailable.');
        }

        if (!colorVariantUploadResult?.firstColorVariantId) {
          throw new Error('Please add at least one color variant before uploading Shoe Spin Viewer frames.');
        }

        await uploadShowroom360Images(
          createdProductId,
          colorVariantUploadResult.firstColorVariantId,
        );
      }

      await Swal.fire({
        title: 'Success!',
        text: `Product ${editingProduct ? 'updated' : 'created'} successfully`,
        icon: 'success',
        confirmButtonColor: '#000000',
      });

      if (!editingProduct && selectedInventoryItem) {
        setInventoryStocks((prev) => prev.filter((s) => s.id !== selectedInventoryItem.id));
      }

      setIsModalOpen(false);
      setSelectedInventoryItem(null);
      fetchProducts();
    } catch (error: any) {
      console.error('Error saving product:', error);
      Swal.fire({
        title: 'Error',
        text: error.message || 'Failed to save product',
        icon: 'error',
        confirmButtonColor: '#000000',
      });
    } finally {
      setUploading(false);
    }
  };

  const handleDelete = async (id: number) => {
    const result = await Swal.fire({
      title: 'Delete Product?',
      text: 'This will also delete all product variants. This action cannot be undone',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#dc2626',
      cancelButtonColor: '#6b7280',
      confirmButtonText: 'Yes, delete it',
      cancelButtonText: 'Cancel',
    });

    if (!result.isConfirmed) return;

    try {
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

      const response = await fetch(`/api/products/${id}`, {
        method: 'DELETE',
        credentials: 'include',
        headers: { 
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrfToken || '',
        },
      });

      if (!response.ok) throw new Error('Failed to delete product');

      await Swal.fire({
        title: 'Deleted!',
        text: 'Product has been deleted',
        icon: 'success',
        confirmButtonColor: '#000000',
      });

      fetchProducts();
    } catch (error) {
      Swal.fire({
        title: 'Error',
        text: 'Failed to delete product',
        icon: 'error',
        confirmButtonColor: '#000000',
      });
    }
  };

  const calculateTotalStock = () => {
    return variants.reduce((sum, v) => sum + v.quantity, 0);
  };

  const itemsPerPage = 8;
  const totalPages = Math.max(1, Math.ceil(products.length / itemsPerPage));
  const safeCurrentPage = Math.min(currentPage, totalPages);
  const startIndex = (safeCurrentPage - 1) * itemsPerPage;
  const paginatedProducts = products.slice(startIndex, startIndex + itemsPerPage);

  useEffect(() => {
    if (currentPage > totalPages) {
      setCurrentPage(totalPages);
    }
  }, [currentPage, totalPages]);

  return (
    <>
      <AppLayoutERP>
        <Head title="Product Management" />

        <div className="space-y-6">
          {/* Header */}
          <div className="flex items-center justify-between">
            <div>
              <h1 className="text-3xl font-bold text-gray-900 dark:text-white">Product Management</h1>
              <p className="text-gray-600 dark:text-gray-400 mt-1">
                Manage your shoe inventory with variant-based stock control
              </p>
            </div>
            <div className="flex items-center gap-3">
              <Link
                href="/services/product-image-spin-tutorial?from=product-uploader"
                onClick={() => { setTutorialVisited(true); sessionStorage.setItem('tutorial_visited', JSON.stringify({ v: true, c: getCsrfToken() })); }}
                className="group relative inline-flex h-10 w-10 items-center justify-center rounded-xl border border-[#16233b]/30 bg-[#16233b] text-white shadow-md transition-all duration-200 hover:-translate-y-0.5 hover:bg-[#213257] hover:shadow-lg active:translate-y-0 active:scale-95 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16233b] focus-visible:ring-offset-2"
                title="Product Image Spin Tutorial"
                aria-label="Open Product Image Spin Tutorial"
              >
                <BulbIcon className="h-5 w-5 transition-transform duration-200 group-hover:scale-110 group-active:scale-95" />
                {!tutorialVisited && (
                  <span className="absolute -right-1.5 -top-1.5 flex h-4 w-4 items-center justify-center rounded-full bg-red-500 text-[9px] font-bold text-white ring-2 ring-white">!</span>
                )}
              </Link>
              <button
                onClick={() => handleOpenModal()}
                className="px-5 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-medium"
              >
                + Add New Product
              </button>
            </div>
          </div>

          {/* Stats */}
          <div className="grid grid-cols-1 md:grid-cols-4 gap-6">
            <MetricCard
              title="Total Products"
              value={products.length}
              color="info"
              icon={ShoppingCartIcon}
            />
            <MetricCard
              title="Active Products"
              value={products.filter(p => p.is_active).length}
              color="success"
              icon={CheckCircleIcon}
            />
            <MetricCard
              title="Out of Stock"
              value={products.filter(p => p.stock_quantity === 0).length}
              color="error"
              icon={ExclamationIcon}
            />
            <MetricCard
              title="Total Sales"
              value={products.reduce((sum, p) => sum + p.sales_count, 0)}
              color="warning"
              icon={TrendingUpIcon}
            />
          </div>

          {/* Products Table */}
          <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm overflow-hidden">
            <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
              <thead className="bg-gray-50 dark:bg-gray-900">
                <tr>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Product
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Price
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Stock
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Sales
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Status
                  </th>
                  <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Actions
                  </th>
                </tr>
              </thead>
              <tbody className="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                {loading ? (
                  <tr>
                    <td colSpan={6} className="px-6 py-12 text-center text-gray-500 dark:text-gray-400">
                      Loading products...
                    </td>
                  </tr>
                ) : products.length === 0 ? (
                  <tr>
                    <td colSpan={6} className="px-6 py-12 text-center text-gray-500 dark:text-gray-400">
                      No products yet. Create your first product!
                    </td>
                  </tr>
                ) : (
                  paginatedProducts.map(product => (
                    <tr key={product.id} className="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="flex items-center gap-3">
                          {product.main_image ? (
                            <img
                              src={product.main_image}
                              alt={product.name}
                              className="size-12 rounded-lg object-cover"
                            />
                          ) : (
                            <div className="size-12 rounded-lg bg-gray-200 dark:bg-gray-700 flex items-center justify-center">
                              <span className="text-xs text-gray-500">No image</span>
                            </div>
                          )}
                          <div>
                            <p className="font-medium text-gray-900 dark:text-white">{product.name}</p>
                            <p className="text-sm text-gray-500 dark:text-gray-400">{product.brand}</p>
                          </div>
                        </div>
                      </td>
                      <td className="px-6 py-4">
                        <div className="space-y-1">
                          <p className="text-gray-900 dark:text-white font-medium">₱{product.price.toLocaleString()}</p>
                          {product.pending_price_request && (
                            <div className="flex items-center gap-1.5 mt-1">
                              <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400">
                                <svg className="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                                  <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clipRule="evenodd" />
                                </svg>
                                Pending: ₱{product.pending_price_request.proposed_price.toLocaleString()}
                              </span>
                            </div>
                          )}
                        </div>
                      </td>
                      <td className="px-6 py-4">
                        <span className={`px-2 py-1 text-xs font-medium rounded-full ${
                          product.stock_quantity === 0
                            ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'
                            : product.stock_quantity < 10
                            ? 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400'
                            : 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
                        }`}>
                          {product.stock_quantity} units
                        </span>
                      </td>
                      <td className="px-6 py-4 text-gray-900 dark:text-white">
                        {product.sales_count}
                      </td>
                      <td className="px-6 py-4">
                        <span className={`px-2 py-1 text-xs font-medium rounded-full ${
                          product.is_active
                            ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
                            : 'bg-gray-100 text-gray-700 dark:bg-gray-900/30 dark:text-gray-400'
                        }`}>
                          {product.is_active ? 'Active' : 'Inactive'}
                        </span>
                      </td>
                      <td className="px-6 py-4 text-right flex items-center justify-end gap-2">
                        <button
                          onClick={() => handleOpenModal(product)}
                          className="p-2 text-blue-600 hover:text-blue-700 dark:hover:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg transition-colors"
                          title="Edit product"
                        >
                          <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                          </svg>
                        </button>
                        <button
                          onClick={() => handleDelete(product.id)}
                          className="p-2 text-red-600 hover:text-red-700 dark:hover:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition-colors"
                          title="Delete product"
                        >
                          <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                          </svg>
                        </button>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>

            {!loading && products.length > 0 && (
              <div className="px-6 py-4 border-t border-gray-200 dark:border-gray-700 flex items-center justify-between">
                <p className="text-sm text-gray-600 dark:text-gray-400">
                  Showing {startIndex + 1} to {Math.min(startIndex + itemsPerPage, products.length)} of {products.length} products
                </p>
                <div className="flex items-center gap-2">
                  <button
                    type="button"
                    onClick={() => setCurrentPage((prev) => Math.max(prev - 1, 1))}
                    disabled={safeCurrentPage === 1}
                    className="p-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                    title="Previous page"
                  >
                    <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
                    </svg>
                  </button>
                  <button
                    type="button"
                    onClick={() => setCurrentPage((prev) => Math.min(prev + 1, totalPages))}
                    disabled={safeCurrentPage === totalPages}
                    className="p-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                    title="Next page"
                  >
                    <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                    </svg>
                  </button>
                </div>
              </div>
            )}
          </div>
        </div>
      </AppLayoutERP>

      {/* Add/Edit Product Modal with Variant Management */}
      {isModalOpen && createPortal(
        <div className="fixed inset-0 z-[999999] bg-black/60 backdrop-blur-sm flex items-center justify-center p-2">
          <div className="bg-white dark:bg-gray-800 rounded-xl max-w-7xl w-full shadow-2xl relative flex flex-col" style={{ height: 'calc(100vh - 1rem)' }}>
            <div className="sticky top-0 p-6 border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 rounded-t-xl z-10">
              <div className="flex items-start justify-between gap-4">
                <div>
                  <h2 className="text-2xl font-bold text-gray-900 dark:text-white">
                    {editingProduct ? 'Edit Product' : 'Add New Product'}
                  </h2>
                  <p className="text-sm text-gray-600 dark:text-gray-400 mt-1">
                    Configure product details and manage inventory by size and color variants
                  </p>
                </div>

                {loadingShowroomEntitlement ? (
                  <span className="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-400">
                    Checking Shoe Spin Viewer access...
                  </span>
                ) : canToggle360Viewer ? (
                  <button
                    type="button"
                    onClick={() => setShow3DShoeModels((prev) => !prev)}
                    aria-label="Toggle Shoe Spin Viewer"
                    title="Toggle Shoe Spin Viewer"
                    className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-800"
                  >
                    <span>Shoe Spin Viewer</span>
                    {!canUse360Uploader && hasExistingShowroomFrames && (
                      <span className="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-800 dark:bg-amber-900/30 dark:text-amber-300">
                        Existing only
                      </span>
                    )}
                    <span
                      className={`relative inline-flex h-5 w-10 items-center rounded-full transition-colors ${
                        show3DShoeModels ? 'bg-green-600' : 'bg-gray-300 dark:bg-gray-600'
                      }`}
                    >
                      <span
                        className={`inline-block h-4 w-4 transform rounded-full bg-white transition-transform ${
                          show3DShoeModels ? 'translate-x-5' : 'translate-x-1'
                        }`}
                      />
                    </span>
                  </button>
                ) : null}
              </div>
              {editingProduct && existingShowroomFrameCount > 0 && (
                <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
                  {existingShowroomFrameCount} saved Shoe Spin frame(s) detected. Toggle Shoe Spin Viewer to preview/manage existing frames.
                </p>
              )}
            </div>

            <form onSubmit={handleSubmit} className="flex flex-col flex-1 overflow-hidden">
              <div className="flex-1 overflow-y-auto flex flex-col gap-6 p-6 pr-2">
              
              {/* Color Variants — read-only when sourced from inventory stock, editable when editing an existing product */}
              <div className="order-2 bg-gray-50 dark:bg-gray-900/50 rounded-lg p-6">
                {selectedInventoryItem && !editingProduct ? (
                  <div>
                    <div className="flex items-center justify-between mb-4">
                      <h3 className="text-base font-semibold text-gray-900 dark:text-white">Color Variants &amp; Sizes</h3>
                    </div>
                    <p className="mb-3 text-xs text-gray-500 dark:text-gray-400">
                      Sizes are shown as: Size value (US/UK/EU/AU/CN) • stock pairs. If no size system is provided, US is used by default.
                    </p>
                    {colorVariants.length === 0 ? (
                      <p className="text-sm text-gray-500 dark:text-gray-400">No color variants found in this stock item.</p>
                    ) : (
                      <div className="space-y-3">
                        {colorVariants.map((cv) => (
                          <div key={cv.id} className="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4">
                            <div className="flex items-center gap-3 mb-3">
                              <div className="h-5 w-5 rounded-full border border-gray-300 dark:border-gray-600 flex-shrink-0" style={{ backgroundColor: cv.color_code }} />
                              <span className="font-medium text-gray-900 dark:text-white text-sm">{cv.color_name}</span>
                              <span className="text-xs text-gray-500 dark:text-gray-400">{cv.images.length} image{cv.images.length !== 1 ? 's' : ''}</span>
                            </div>
                            {cv.images.length > 0 && (
                              <div className="flex gap-2 mb-3 flex-wrap">
                                {cv.images.slice(0, 5).map((img) => (
                                  <img key={img.id} src={img.preview} alt="" className="h-14 w-14 rounded-lg object-cover border border-gray-200 dark:border-gray-700" />
                                ))}
                                {cv.images.length > 5 && (
                                  <div className="h-14 w-14 rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-100 dark:bg-gray-800 flex items-center justify-center text-xs text-gray-500 dark:text-gray-400">
                                    +{cv.images.length - 5}
                                  </div>
                                )}
                              </div>
                            )}
                            <div className="flex flex-wrap gap-2">
                              {cv.sizes.map((s) => (
                                <span key={s.id} className="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-gray-100 dark:bg-gray-800 text-xs font-medium text-gray-700 dark:text-gray-300">
                                  {formatSizeChipLabel(String(s.size), Number(s.quantity || 0), s.size_system)}
                                </span>
                              ))}
                            </div>
                          </div>
                        ))}
                      </div>
                    )}
                  </div>
                ) : (
                  <ColorVariantManager
                    colorVariants={colorVariants}
                    onColorVariantsChange={setColorVariants}
                    isEditing={!!editingProduct}
                    lockStockEditing={!!editingProduct}
                  />
                )}
              </div>

              {show3DShoeModels && (canUse360Uploader || hasExistingShowroomFrames) && (
                <div className="order-3 bg-gray-50 dark:bg-gray-900/50 rounded-lg p-4">
                  <div className="rounded-lg border border-dashed border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 p-4">
                    <div className="flex items-center justify-between gap-3 mb-2">
                      <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Shoe Spin Viewer
                      </label>
                      <span className="text-xs text-gray-500 dark:text-gray-400">
                        JPG, JPEG, PNG, WEBP (Image Sequence, max {MAX_SHOWROOM_360_FILES})
                      </span>
                    </div>

                    {canUse360Uploader ? (
                      <input
                        type="file"
                        multiple
                        accept={accepted3DModelsInput}
                        onChange={handle3DModelFilesChange}
                        title="Upload image sequence files"
                        aria-label="Upload image sequence files"
                        className="block w-full text-sm text-gray-700 dark:text-gray-200 file:mr-4 file:rounded-md file:border-0 file:bg-black file:px-3 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-gray-800"
                      />
                    ) : (
                      <p className="mb-3 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-700/40 dark:bg-amber-900/20 dark:text-amber-300">
                        Uploading new Shoe Spin frames is disabled for this company type. Existing saved frames remain visible and can still be managed.
                      </p>
                    )}

                    {editingProduct && existingShowroomFrames.length > 0 && (
                      <div className="mt-3 rounded-md border border-gray-200 dark:border-gray-700 p-3">
                        <div className="mb-2 flex items-center justify-between">
                          <p className="text-xs font-medium text-gray-700 dark:text-gray-300">
                            Saved Shoe Spin frames
                          </p>
                          {removedShowroomFrameKeys.length > 0 && (
                            <button
                              type="button"
                              onClick={() => setRemovedShowroomFrameKeys([])}
                              className="text-xs font-medium text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300"
                            >
                              Undo removals ({removedShowroomFrameKeys.length})
                            </button>
                          )}
                        </div>

                        <div className="grid grid-cols-5 gap-2 max-h-56 overflow-y-auto pr-1">
                          {existingShowroomFrames.map((frame, frameIndex) => {
                            const frameMarkedForRemoval = isShowroomFrameMarkedForRemoval(frame);

                            return (
                              <div
                                key={getShowroomFrameKey(frame)}
                                className={`relative overflow-hidden rounded-md border ${
                                  frameMarkedForRemoval
                                    ? 'border-red-300 opacity-50'
                                    : 'border-gray-200 dark:border-gray-700'
                                }`}
                              >
                                <img
                                  src={frame.url}
                                  alt={frame.alt_text || `Saved showroom frame ${frameIndex + 1}`}
                                  className="h-20 w-full object-cover"
                                />
                                <div className="absolute left-1 top-1 rounded bg-black/70 px-1.5 py-0.5 text-[10px] text-white">
                                  #{frameIndex + 1}
                                </div>
                                <button
                                  type="button"
                                  onClick={() =>
                                    frameMarkedForRemoval
                                      ? restoreShowroomFrame(frame)
                                      : markShowroomFrameForRemoval(frame)
                                  }
                                  className={`absolute right-1 top-1 rounded px-1.5 py-0.5 text-[10px] font-medium ${
                                    frameMarkedForRemoval
                                      ? 'bg-blue-600 text-white hover:bg-blue-700'
                                      : 'bg-red-600 text-white hover:bg-red-700'
                                  }`}
                                >
                                  {frameMarkedForRemoval ? 'Undo' : 'Remove'}
                                </button>
                              </div>
                            );
                          })}
                        </div>

                        <p className="mt-2 text-[11px] text-gray-500 dark:text-gray-400">
                          Frame removals are applied when you click Update Product.
                        </p>
                      </div>
                    )}

                    {product3DFiles.length > 0 && (
                      <div className="mt-3 space-y-2">
                        {product3DFiles.map((file, index) => (
                          <div
                            key={`${file.name}-${file.lastModified}-${index}`}
                            className="flex items-center justify-between rounded-md border border-gray-200 dark:border-gray-700 px-3 py-2"
                          >
                            <div className="min-w-0">
                              <p className="truncate text-sm font-medium text-gray-900 dark:text-white">{file.name}</p>
                              <p className="text-xs text-gray-500 dark:text-gray-400">{formatBytes(file.size)}</p>
                            </div>
                            <button
                              type="button"
                              onClick={() => remove3DModelFile(index)}
                              title="Remove 3D model"
                              aria-label="Remove 3D model"
                              className="ml-3 inline-flex h-8 w-8 items-center justify-center rounded-md border border-gray-300 text-red-600 transition-colors hover:bg-red-50 dark:border-gray-600 dark:text-red-400 dark:hover:bg-red-900/20"
                            >
                              <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                              </svg>
                            </button>
                          </div>
                        ))}
                      </div>
                    )}
                  </div>
                </div>
              )}

              {/* Basic Information */}
              <div className="order-4 bg-gray-50 dark:bg-gray-900/50 rounded-lg p-4">
                <h3 className="text-lg font-semibold text-gray-900 dark:text-white mb-4">Basic Information</h3>
                
                <div className="grid grid-cols-2 gap-4">
                  <div className="col-span-2">
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                      Product Name *
                    </label>
                    <input
                      type="text"
                      value={formData.name}
                      onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                      className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-white"
                      placeholder="e.g., Nike Air Force 1 '07"
                      required
                    />
                  </div>

                  <div className="col-span-2">
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                      Description *
                    </label>
                    <textarea
                      value={formData.description}
                      onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                      rows={3}
                      className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-white"
                      placeholder="Describe the product..."
                      required
                    />
                  </div>

                  <div>
                    <div className="flex items-center justify-between mb-2">
                      <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Price *
                      </label>
                      {editingProduct && (
                        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400">
                          Managed in Shoe Pricing
                        </span>
                      )}
                    </div>
                    <input
                      type="number"
                      step="0.01"
                      value={formData.price}
                      onChange={(e) => setFormData({ ...formData, price: e.target.value })}
                      className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-white transition-all"
                      placeholder="0.00"
                      required
                      disabled={!!editingProduct}
                    />
                    {editingProduct && (
                      <p className="mt-1 text-xs text-gray-600 dark:text-gray-400">
                        Price updates are handled in the Shoe Pricing page.
                      </p>
                    )}
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                      Brand *
                    </label>
                    <input
                      type="text"
                      value={formData.brand}
                      onChange={(e) => setFormData({ ...formData, brand: e.target.value })}
                      className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-white"
                      placeholder="e.g., Nike, Adidas"
                      required
                    />
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                      Category *
                    </label>
                    <button
                      type="button"
                      onClick={() => setIsCategoryModalOpen(true)}
                      className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-900 text-left text-gray-900 dark:text-white"
                    >
                      Select categories
                    </button>
                    <div className="mt-2 flex flex-wrap gap-2">
                      {selectedCategories.map((value) => (
                        <span
                          key={value}
                          className="inline-flex items-center rounded-full bg-gray-200 px-2 py-1 text-xs font-medium text-gray-800 dark:bg-gray-700 dark:text-gray-100"
                        >
                          {categoryLabelByValue[value] || value}
                        </span>
                      ))}
                    </div>
                  </div>

                </div>
              </div>
              </div>

              {/* Actions - Fixed at bottom */}
              <div className="grid grid-cols-2 gap-3 p-6 border-t border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 flex-shrink-0">
                <button
                  type="button"
                  onClick={() => { setIsModalOpen(false); setSelectedInventoryItem(null); }}
                  className="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm font-semibold text-gray-700 shadow-sm transition-colors hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-900"
                  disabled={uploading}
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={uploading}
                  className="h-11 w-full rounded-lg bg-black px-4 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-50"
                >
                  {uploading ? 'Saving...' : editingProduct ? 'Update Product' : 'Create Product'}
                </button>
              </div>
            </form>

            {isCategoryModalOpen && (
              <div className="fixed inset-0 z-[1000000] bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
                <div className="bg-white dark:bg-gray-800 rounded-xl w-full max-w-lg shadow-2xl border border-gray-200 dark:border-gray-700">
                  <div className="p-4 border-b border-gray-200 dark:border-gray-700">
                    <h3 className="text-lg font-semibold text-gray-900 dark:text-white">Select Categories</h3>
                    <p className="text-xs text-gray-600 dark:text-gray-400 mt-1">
                      Choose one or more categories for this product.
                    </p>
                  </div>
                  <div className="p-4 grid grid-cols-2 gap-3">
                    {categoryOptions.map((option) => (
                      <label key={option.value} className="flex items-center gap-2 text-sm text-gray-800 dark:text-gray-200">
                        <input
                          type="checkbox"
                          checked={selectedCategories.includes(option.value)}
                          onChange={() => toggleCategorySelection(option.value)}
                          className="h-4 w-4 rounded border-gray-300 text-black focus:ring-black"
                        />
                        {option.label}
                      </label>
                    ))}
                  </div>
                  <div className="p-4 border-t border-gray-200 dark:border-gray-700 flex justify-end gap-2">
                    <button
                      type="button"
                      onClick={() => setIsCategoryModalOpen(false)}
                      className="h-9 px-4 rounded-lg border border-gray-300 bg-white text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-900"
                    >
                      Done
                    </button>
                  </div>
                </div>
              </div>
            )}
          </div>
        </div>,
        document.body
      )}

      {/* Inventory Stock Picker — staff must choose an uploaded shoe stock item to create a product */}
      {isStockPickerOpen && createPortal(
        <div className="fixed inset-0 z-[999999] bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-white dark:bg-gray-800 rounded-xl max-w-4xl w-full shadow-2xl flex flex-col" style={{ maxHeight: 'calc(100vh - 2rem)' }}>

            {/* Header */}
            <div className="p-6 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between flex-shrink-0">
              <div>
                <h2 className="text-2xl font-bold text-gray-900 dark:text-white">Select Inventory Stock</h2>
                <p className="text-sm text-gray-500 dark:text-gray-400 mt-1">
                  Choose an uploaded shoe stock item to create a product listing from.
                </p>
              </div>
              <button
                type="button"
                onClick={() => setIsStockPickerOpen(false)}
                aria-label="Close stock picker"
                className="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors p-1 rounded-lg"
              >
                <svg className="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>

            {/* Search */}
            <div className="p-4 border-b border-gray-200 dark:border-gray-700 flex-shrink-0">
              <input
                type="text"
                value={stockSearch}
                onChange={(e) => setStockSearch(e.target.value)}
                placeholder="Search by name or SKU..."
                className="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-700 rounded-xl bg-white dark:bg-gray-900 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
                autoFocus
              />
            </div>

            {/* Stock Grid */}
            <div className="flex-1 overflow-y-auto p-4">
              {isLoadingStocks ? (
                <div className="flex flex-col items-center justify-center py-16 text-gray-500 dark:text-gray-400">
                  <div className="animate-spin rounded-full h-10 w-10 border-b-2 border-blue-600 mb-3"></div>
                  <p className="text-sm">Loading inventory stocks...</p>
                </div>
              ) : (() => {
                const filtered = inventoryStocks.filter(s =>
                  s.name.toLowerCase().includes(stockSearch.toLowerCase()) ||
                  (s.sku || '').toLowerCase().includes(stockSearch.toLowerCase())
                );
                if (filtered.length === 0) return (
                  <div className="flex flex-col items-center justify-center py-16 text-gray-500 dark:text-gray-400">
                    <svg className="size-14 mb-3 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10" />
                    </svg>
                    <p className="font-medium">No shoe stock items found</p>
                    <p className="text-xs mt-1 text-center">Upload inventory stock first before creating product listings.</p>
                  </div>
                );
                return (
                  <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    {filtered.map((stock) => {
                      const thumb = stock.main_image
                        ? `/storage/${stock.main_image}`
                        : stock.color_variants?.[0]?.images?.[0]?.image_path
                          ? `/storage/${stock.color_variants[0].images[0].image_path}`
                          : null;
                      const colorCount = (stock.color_variants ?? []).length;
                      const qty = stock.available_quantity ?? 0;
                      return (
                        <button
                          key={stock.id}
                          type="button"
                          onClick={() => handleSelectStock(stock)}
                          className="text-left rounded-xl border-2 border-gray-200 dark:border-gray-700 hover:border-blue-500 dark:hover:border-blue-400 bg-white dark:bg-gray-900 p-4 transition-all duration-200 hover:shadow-md group focus:outline-none focus:ring-2 focus:ring-blue-500"
                        >
                          <div className="h-36 w-full rounded-lg overflow-hidden bg-gray-100 dark:bg-gray-800 mb-3 flex items-center justify-center">
                            {thumb ? (
                              <img src={thumb} alt={stock.name} className="h-full w-full object-cover group-hover:scale-105 transition-transform duration-200" />
                            ) : (
                              <svg className="size-10 text-gray-300 dark:text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                              </svg>
                            )}
                          </div>
                          <p className="font-semibold text-gray-900 dark:text-white text-sm leading-snug mb-1 group-hover:text-blue-600 dark:group-hover:text-blue-400 transition-colors line-clamp-2">
                            {stock.name}
                          </p>
                          <p className="text-xs text-gray-400 dark:text-gray-500 mb-2 font-mono">{stock.sku}</p>
                          <div className="flex items-center justify-between">
                            <span className="text-xs text-gray-500 dark:text-gray-400">
                              {colorCount} color{colorCount !== 1 ? 's' : ''}
                            </span>
                            <span className={`inline-flex px-2 py-0.5 rounded-full text-xs font-semibold ${
                              qty === 0
                                ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'
                                : qty < 10
                                ? 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400'
                                : 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
                            }`}>
                              {qty} units
                            </span>
                          </div>
                        </button>
                      );
                    })}
                  </div>
                );
              })()}
            </div>
          </div>
        </div>,
        document.body
      )}

      {/* Upload Loading Overlay */}
      {uploading && createPortal(
        <div className="fixed inset-0 z-[9999999] flex items-center justify-center bg-black/80 backdrop-blur-sm">
          <div className="flex flex-col items-center gap-6">
            <div className="relative flex items-center justify-center">
              <span className="absolute inline-block h-36 w-36 rounded-full border-8 border-white/25" />
              <span className="inline-block h-36 w-36 animate-spin rounded-full border-8 border-transparent border-t-white" style={{ animationDuration: '0.42s' }} />
              <span className="absolute inline-block h-20 w-20 animate-spin rounded-full border-8 border-transparent border-t-white/70" style={{ animationDuration: '0.68s', animationDirection: 'reverse' }} />
            </div>
            <p className="text-white text-2xl font-semibold tracking-wide animate-pulse">Uploading</p>
          </div>
        </div>,
        document.body
      )}
    </>
  );
}