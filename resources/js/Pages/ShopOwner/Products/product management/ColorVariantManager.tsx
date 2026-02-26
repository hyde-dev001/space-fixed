import React, { useState } from 'react';
import { ColorVariantImageUploader, ColorVariantImage } from './ColorVariantImageUploader';
import Swal from 'sweetalert2';

export type SizeVariant = {
  id: string;
  size: string;
  quantity: number;
  sku?: string;
};

export type ColorVariant = {
  id: string;
  color_name: string;
  color_code: string;
  images: ColorVariantImage[];
  sizes: SizeVariant[];
  isExpanded: boolean;
};

type ColorVariantManagerProps = {
  colorVariants: ColorVariant[];
  onColorVariantsChange: (variants: ColorVariant[]) => void;
};

const PREDEFINED_COLORS = [
  { name: 'Black', code: '#000000' },
  { name: 'White', code: '#FFFFFF' },
  { name: 'Red', code: '#DC2626' },
  { name: 'Blue', code: '#2563EB' },
  { name: 'Green', code: '#16A34A' },
  { name: 'Yellow', code: '#EAB308' },
  { name: 'Pink', code: '#EC4899' },
  { name: 'Purple', code: '#9333EA' },
  { name: 'Orange', code: '#EA580C' },
  { name: 'Brown', code: '#92400E' },
  { name: 'Gray', code: '#6B7280' },
  { name: 'Navy', code: '#1E3A8A' },
];

const SIZE_OPTIONS = Array.from({ length: 25 }, (_, i) => {
  const size = 3 + i * 0.5;
  return Number.isInteger(size) ? size.toFixed(0) : size.toFixed(1);
});

export const ColorVariantManager: React.FC<ColorVariantManagerProps> = ({
  colorVariants,
  onColorVariantsChange,
}) => {
  const [showColorPicker, setShowColorPicker] = useState(false);
  const [customColorName, setCustomColorName] = useState('');
  const [customColorCode, setCustomColorCode] = useState('#000000');

  const addColorVariant = (colorName: string, colorCode: string) => {
    // Check if color already exists
    if (colorVariants.some(cv => cv.color_name.toLowerCase() === colorName.toLowerCase())) {
      alert(`${colorName} color already exists!`);
      return;
    }

    const newVariant: ColorVariant = {
      id: Date.now().toString(),
      color_name: colorName,
      color_code: colorCode,
      images: [],
      sizes: [],
      isExpanded: true,
    };

    onColorVariantsChange([...colorVariants, newVariant]);
    setShowColorPicker(false);
    setCustomColorName('');
  };

  const removeColorVariant = async (id: string) => {
    const result = await Swal.fire({
      title: 'Delete Color Variant?',
      text: 'This will remove this color and all its images and sizes. This action cannot be undone.',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#dc2626',
      cancelButtonColor: '#6b7280',
      confirmButtonText: 'Yes, delete it',
      cancelButtonText: 'Cancel',
    });
    
    if (!result.isConfirmed) return;
    onColorVariantsChange(colorVariants.filter(cv => cv.id !== id));
  };

  const updateColorVariant = (id: string, updates: Partial<ColorVariant>) => {
    onColorVariantsChange(
      colorVariants.map(cv => (cv.id === id ? { ...cv, ...updates } : cv))
    );
  };

  const toggleExpanded = (id: string) => {
    updateColorVariant(id, { isExpanded: !colorVariants.find(cv => cv.id === id)?.isExpanded });
  };

  const addSizeToColor = (colorId: string, size: string) => {
    const colorVariant = colorVariants.find(cv => cv.id === colorId);
    if (!colorVariant) return;

    // Check if size already exists
    if (colorVariant.sizes.some(s => s.size === size)) {
      alert(`Size ${size} already exists for this color!`);
      return;
    }

    const newSize: SizeVariant = {
      id: Date.now().toString(),
      size,
      quantity: 0,
    };

    updateColorVariant(colorId, {
      sizes: [...colorVariant.sizes, newSize],
    });
  };

  const removeSizeFromColor = (colorId: string, sizeId: string) => {
    const colorVariant = colorVariants.find(cv => cv.id === colorId);
    if (!colorVariant) return;

    updateColorVariant(colorId, {
      sizes: colorVariant.sizes.filter(s => s.id !== sizeId),
    });
  };

  const updateSize = (colorId: string, sizeId: string, updates: Partial<SizeVariant>) => {
    const colorVariant = colorVariants.find(cv => cv.id === colorId);
    if (!colorVariant) return;

    updateColorVariant(colorId, {
      sizes: colorVariant.sizes.map(s => (s.id === sizeId ? { ...s, ...updates } : s)),
    });
  };

  const getTotalQuantity = (colorVariant: ColorVariant) => {
    return colorVariant.sizes.reduce((sum, s) => sum + s.quantity, 0);
  };

  return (
    <div className="space-y-4">
      {/* Header with Add Color Button */}
      <div className="flex items-center justify-between">
        <div>
          <h3 className="text-lg font-semibold text-gray-900 dark:text-white">
            Add Variants
          </h3>
          <p className="text-sm text-gray-600 dark:text-gray-400 mt-0.5">
            Add colors, then upload 5-10 images per color
          </p>
        </div>
        <button
          type="button"
          onClick={() => setShowColorPicker(true)}
          className="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition-colors inline-flex items-center gap-2"
        >
          <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
          </svg>
          Add Color
        </button>
      </div>

      {/* Color Picker Modal */}
      {showColorPicker && (
        <div className="fixed inset-0 z-[99999] bg-black/50 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-white dark:bg-gray-800 rounded-xl max-w-2xl w-full p-6 shadow-2xl">
            <div className="flex items-center justify-between mb-4">
              <h4 className="text-xl font-semibold text-gray-900 dark:text-white">
                Select Color
              </h4>
              <button
                type="button"
                onClick={() => setShowColorPicker(false)}
                className="text-gray-500 hover:text-gray-700 dark:hover:text-gray-300"
              >
                <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>

            {/* Predefined Colors */}
            <div className="mb-6">
              <h5 className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                Quick Select
              </h5>
              <div className="grid grid-cols-4 sm:grid-cols-6 gap-3">
                {PREDEFINED_COLORS.map((color) => (
                  <button
                    key={color.name}
                    type="button"
                    onClick={() => addColorVariant(color.name, color.code)}
                    className="group flex flex-col items-center gap-2 p-3 rounded-lg border-2 border-gray-200 dark:border-gray-700 hover:border-blue-500 dark:hover:border-blue-400 transition-all hover:shadow-md"
                  >
                    <div
                      className="w-12 h-12 rounded-full border-2 border-gray-300 dark:border-gray-600 group-hover:scale-110 transition-transform"
                      style={{ backgroundColor: color.code }}
                    />
                    <span className="text-xs font-medium text-gray-700 dark:text-gray-300">
                      {color.name}
                    </span>
                  </button>
                ))}
              </div>
            </div>

            {/* Custom Color */}
            <div className="pt-4 border-t border-gray-200 dark:border-gray-700">
              <h5 className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                Custom Color
              </h5>
              <div className="flex gap-3">
                <input
                  type="text"
                  value={customColorName}
                  onChange={(e) => setCustomColorName(e.target.value)}
                  placeholder="Color name (e.g., Forest Green)"
                  className="flex-1 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-white"
                />
                <input
                  type="color"
                  value={customColorCode}
                  onChange={(e) => setCustomColorCode(e.target.value)}
                  className="w-16 h-10 rounded-lg border border-gray-300 dark:border-gray-600 cursor-pointer"
                />
                <button
                  type="button"
                  onClick={() => {
                    if (!customColorName.trim()) {
                      alert('Please enter a color name');
                      return;
                    }
                    addColorVariant(customColorName.trim(), customColorCode);
                  }}
                  className="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors"
                >
                  Add
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Color Variants List */}
      <div className="space-y-3">
        {colorVariants.map((colorVariant) => (
          <div
            key={colorVariant.id}
            className="border border-gray-200 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-800/50 overflow-hidden"
          >
            {/* Color Header */}
            <div className="p-4 bg-gray-50 dark:bg-gray-800 flex items-center justify-between cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700/50 transition-colors"
              onClick={() => toggleExpanded(colorVariant.id)}
            >
              <div className="flex items-center gap-3">
                <div
                  className="w-10 h-10 rounded-full border-2 border-gray-300 dark:border-gray-600 shadow-sm"
                  style={{ backgroundColor: colorVariant.color_code }}
                />
                <div>
                  <h4 className="font-semibold text-gray-900 dark:text-white">
                    {colorVariant.color_name}
                  </h4>
                  <div className="flex items-center gap-3 text-sm text-gray-600 dark:text-gray-400 mt-0.5">
                    <span>{colorVariant.images.length} images</span>
                    <span>•</span>
                    <span>{colorVariant.sizes.length} sizes</span>
                    <span>•</span>
                    <span className="font-medium">{getTotalQuantity(colorVariant)} units</span>
                  </div>
                </div>
              </div>
              <div className="flex items-center gap-2">
                <button
                  type="button"
                  onClick={(e) => {
                    e.stopPropagation();
                    removeColorVariant(colorVariant.id);
                  }}
                  className="p-2 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition-colors"
                  title="Remove color"
                >
                  <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                  </svg>
                </button>
                <svg
                  className={`w-5 h-5 text-gray-500 transition-transform ${
                    colorVariant.isExpanded ? 'rotate-180' : ''
                  }`}
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
                </svg>
              </div>
            </div>

            {/* Expanded Content */}
            {colorVariant.isExpanded && (
              <div className="p-4 space-y-4">
                {/* Image Gallery */}
                <div>
                  <h5 className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Image Gallery (First image is thumbnail)
                  </h5>
                  <ColorVariantImageUploader
                    colorName={colorVariant.color_name}
                    images={colorVariant.images}
                    onImagesChange={(images) => updateColorVariant(colorVariant.id, { images })}
                    maxImages={10}
                  />
                </div>

                {/* Size & Quantity */}
                <div>
                  <div className="flex items-center justify-between mb-2">
                    <h5 className="text-sm font-medium text-gray-700 dark:text-gray-300">
                      Size & Stock
                    </h5>
                    <select
                      onChange={(e) => {
                        if (e.target.value) {
                          addSizeToColor(colorVariant.id, e.target.value);
                          e.target.value = '';
                        }
                      }}
                      className="px-3 py-1.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-white text-sm"
                      value=""
                    >
                      <option value="">+ Add Size</option>
                      {SIZE_OPTIONS.filter(
                        size => !colorVariant.sizes.some(s => s.size === size)
                      ).map((size) => (
                        <option key={size} value={size}>
                          Size {size}
                        </option>
                      ))}
                    </select>
                  </div>

                  {colorVariant.sizes.length === 0 ? (
                    <div className="text-center py-4 text-sm text-gray-500 dark:text-gray-400 border border-dashed border-gray-300 dark:border-gray-600 rounded-lg">
                      No sizes added yet. Select sizes from the dropdown above.
                    </div>
                  ) : (
                    <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3">
                      {colorVariant.sizes.map((sizeVariant) => (
                        <div
                          key={sizeVariant.id}
                          className="border border-gray-200 dark:border-gray-700 rounded-lg p-3 bg-gray-50 dark:bg-gray-900/50"
                        >
                          <div className="flex items-center justify-between mb-2">
                            <span className="text-sm font-semibold text-gray-900 dark:text-white">
                              Size {sizeVariant.size}
                            </span>
                            <button
                              type="button"
                              onClick={() => removeSizeFromColor(colorVariant.id, sizeVariant.id)}
                              className="text-red-600 hover:text-red-700 dark:hover:text-red-400"
                              title="Remove size"
                            >
                              <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                              </svg>
                            </button>
                          </div>
                          <input
                            type="number"
                            value={sizeVariant.quantity}
                            onChange={(e) =>
                              updateSize(colorVariant.id, sizeVariant.id, {
                                quantity: Math.max(0, parseInt(e.target.value) || 0),
                              })
                            }
                            placeholder="Quantity"
                            min="0"
                            className="w-full px-2 py-1.5 border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-800 text-gray-900 dark:text-white text-sm"
                          />
                        </div>
                      ))}
                    </div>
                  )}
                </div>
              </div>
            )}
          </div>
        ))}
      </div>

      {/* Summary */}
      {colorVariants.length > 0 && (
        <div className="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
          <div className="flex items-start gap-3">
            <svg className="w-5 h-5 text-blue-600 dark:text-blue-400 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <div className="flex-1">
              <p className="text-sm font-medium text-blue-900 dark:text-blue-100">
                Product Summary
              </p>
              <div className="grid grid-cols-3 gap-4 mt-2 text-sm text-blue-800 dark:text-blue-200">
                <div>
                  <span className="font-semibold">{colorVariants.length}</span> colors
                </div>
                <div>
                  <span className="font-semibold">
                    {colorVariants.reduce((sum, cv) => sum + cv.images.length, 0)}
                  </span> total images
                </div>
                <div>
                  <span className="font-semibold">
                    {colorVariants.reduce((sum, cv) => sum + getTotalQuantity(cv), 0)}
                  </span> total units
                </div>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};
