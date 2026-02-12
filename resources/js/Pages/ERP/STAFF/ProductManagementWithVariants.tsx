 import React, { useState, useEffect } from 'react';
import { createPortal } from 'react-dom';
import { Head } from '@inertiajs/react';
import AppLayoutERP from '../../../layout/AppLayout_ERP';
import Swal from 'sweetalert2';
import { ColorVariantManager, ColorVariant } from './ColorVariantManager';

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
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [editingProduct, setEditingProduct] = useState<Product | null>(null);
  const categoryOptions = [
    { label: 'SHOES', value: 'shoes' },
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
    compare_at_price: '',
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
  
  const [uploading, setUploading] = useState(false);

  useEffect(() => {
    fetchProducts();
  }, []);

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

  const handleOpenModal = async (product?: Product) => {
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
        compare_at_price: product.compare_at_price?.toString() || '',
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
          
          if (loadedColorVariants.length > 0) {
            // Product has color variants - use color-first mode
            setVariantMode('color-first');
            
            // Transform loaded color variants to the expected format
            setColorVariants(loadedColorVariants.map((cv: any) => ({
              id: cv.id?.toString() || Date.now().toString(),
              color_name: cv.color_name,
              color_code: cv.color_code,
              images: (cv.images || []).map((img: any) => ({
                id: img.id?.toString() || Date.now().toString(),
                file: null,
                preview: img.image_path || img.image_url,
                uploaded_path: img.image_path || img.image_url,
                is_thumbnail: img.is_thumbnail || false,
                sort_order: img.sort_order || 0,
                alt_text: img.alt_text || '',
              })),
              sizes: (cv.sizes || []).map((size: any) => ({
                id: size.id?.toString() || Date.now().toString(),
                size: size.size?.toString() || '',
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
      setEditingProduct(null);
      setFormData({
        name: '',
        description: '',
        price: '',
        compare_at_price: '',
        brand: '',
        category: 'shoes',
      });
      setSelectedCategories(['shoes']);
      setVariants([
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
      setColorVariants([]);
    }
    
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
  const uploadColorVariantImages = async (productId: number) => {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    let firstColorFirstImage: string | null = null;
    let isFirstColor = true;

    // If editing, delete existing color variants first to avoid duplicates
    if (editingProduct) {
      try {
        // Get existing color variants
        const existingResponse = await fetch(`/api/products/${productId}/color-variants`, {
          credentials: 'include',
          headers: { 'Accept': 'application/json' }
        });
        
        if (existingResponse.ok) {
          const existingData = await existingResponse.json();
          const existingVariants = existingData.color_variants || [];
          
          // Delete each existing color variant
          for (const variant of existingVariants) {
            if (variant.id) {
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
        const uploadedImages = [];
        let isFirstImageInColor = true;
        
        for (const image of colorVariant.images) {
          // Skip if image is already uploaded (has uploaded_path but no new file)
          if (image.uploaded_path && !image.file) {
            uploadedImages.push({
              path: image.uploaded_path,
              alt_text: image.alt_text || '',
              is_thumbnail: image.is_thumbnail,
              sort_order: image.sort_order,
              image_type: 'product',
            });

            // Save the first image of the first color as main_image
            if (isFirstColor) {
              if (image.is_thumbnail) {
                firstColorFirstImage = image.uploaded_path;
              } else if (!firstColorFirstImage && isFirstImageInColor) {
                firstColorFirstImage = image.uploaded_path;
              }
            }
            
            isFirstImageInColor = false;
          }
          // Only upload if there's a new file
          else if (image.file) {
            const uploadData = new FormData();
            uploadData.append('image', image.file);
            
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
              uploadedImages.push({
                path: data.path,
                alt_text: image.alt_text || `${colorVariant.color_name} ${colorVariant.color_name}`,
                is_thumbnail: image.is_thumbnail,
                sort_order: image.sort_order,
                image_type: 'product',
              });

              // Save the first image of the first color as main_image
              // Priority: thumbnail from first color > first image of first color
              if (isFirstColor) {
                if (image.is_thumbnail) {
                  firstColorFirstImage = data.path;
                } else if (!firstColorFirstImage && isFirstImageInColor) {
                  firstColorFirstImage = data.path;
                }
              }
              
              isFirstImageInColor = false;
            } else {
              console.error(`Failed to upload image: ${data.message || 'Unknown error'}`);
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

        if (!cvResponse.ok) {
          const errorData = await cvResponse.json();
          console.error(`Failed to create color variant: ${errorData.message || 'Unknown error'}`);
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

    try {
      setUploading(true);

      let productData;
      let createdProductId: number | null = null;

      // Color-first approach (only mode now)
      const totalStock = colorVariants.reduce((sum, cv) => 
        sum + cv.sizes.reduce((s, size) => s + size.quantity, 0), 0
      );

      const uniqueSizes = [...new Set(colorVariants.flatMap(cv => cv.sizes.map(s => s.size)))];
      const uniqueColors = colorVariants.map(cv => cv.color_name);

      // Prepare variant data for backward compatibility
      const variantData = colorVariants.flatMap(cv => 
        cv.sizes.map(size => ({
          size: size.size,
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
            compare_at_price: formData.compare_at_price ? parseFloat(formData.compare_at_price) : null,
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
      if (createdProductId) {
        await uploadColorVariantImages(createdProductId);
      }

      await Swal.fire({
        title: 'Success!',
        text: `Product ${editingProduct ? 'updated' : 'created'} successfully`,
        icon: 'success',
        confirmButtonColor: '#000000',
      });

      setIsModalOpen(false);
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
            <button
              onClick={() => handleOpenModal()}
              className="px-5 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-medium"
            >
              + Add New Product
            </button>
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
                  products.map(product => (
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
                          {product.compare_at_price && (
                            <p className="text-sm text-gray-500 line-through">₱{product.compare_at_price.toLocaleString()}</p>
                          )}
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
          </div>
        </div>
      </AppLayoutERP>

      {/* Add/Edit Product Modal with Variant Management */}
      {isModalOpen && createPortal(
        <div className="fixed inset-0 z-[999999] bg-black/60 backdrop-blur-sm flex items-center justify-center p-2">
          <div className="bg-white dark:bg-gray-800 rounded-xl max-w-7xl w-full shadow-2xl relative flex flex-col" style={{ height: 'calc(100vh - 1rem)' }}>
            <div className="sticky top-0 p-6 border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 rounded-t-xl z-10">
              <h2 className="text-2xl font-bold text-gray-900 dark:text-white">
                {editingProduct ? 'Edit Product' : 'Add New Product'}
              </h2>
              <p className="text-sm text-gray-600 dark:text-gray-400 mt-1">
                Configure product details and manage inventory by size and color variants
              </p>
            </div>

            <form onSubmit={handleSubmit} className="flex flex-col flex-1 overflow-hidden">
              <div className="flex-1 overflow-y-auto flex flex-col gap-6 p-6 pr-2">
              
              {/* Color-First Variant Management */}
              <div className="order-2 bg-gray-50 dark:bg-gray-900/50 rounded-lg p-6">
                  <ColorVariantManager
                    colorVariants={colorVariants}
                    onColorVariantsChange={setColorVariants}
                  />
              </div>

              {/* Basic Information */}
              <div className="order-3 bg-gray-50 dark:bg-gray-900/50 rounded-lg p-4">
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
                      Compare at Price
                    </label>
                    <input
                      type="number"
                      step="0.01"
                      value={formData.compare_at_price}
                      onChange={(e) => setFormData({ ...formData, compare_at_price: e.target.value })}
                      className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-white"
                      placeholder="Original price (optional)"
                      disabled={!!editingProduct}
                    />
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
                  onClick={() => setIsModalOpen(false)}
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
    </>
  );
}