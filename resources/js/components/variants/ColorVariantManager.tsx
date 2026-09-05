import React, { useState } from 'react';
import { ColorVariantImageUploader, ColorVariantImage } from './ColorVariantImageUploader';
import Swal from 'sweetalert2';

export type SizeVariant = {
  id: string;
  size: string;
  size_system?: SizeSystem;
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

type SizeSystem = 'US' | 'UK' | 'EU' | 'AU' | 'CN';

type ColorVariantManagerProps = {
  colorVariants: ColorVariant[];
  onColorVariantsChange: (variants: ColorVariant[]) => void;
  blockedColorNames?: string[];
  /** When true the "+ Add Color" button is hidden (e.g. when editing a product
   *  whose colours must come from inventory). */
  isEditing?: boolean;
  /** When true, stock-affecting controls (size add/remove and quantity edits) are disabled. */
  lockStockEditing?: boolean;
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

const normalizeColorToken = (value: string) =>
  value.trim().toLowerCase().replace(/\s+/g, ' ');

const splitColorTokens = (value: string): string[] =>
  value
    .split('+')
    .map((token) => token.trim())
    .filter((token) => token.length > 0);

const normalizeColorIdentity = (value: string): string => {
  const tokens = splitColorTokens(value).map(normalizeColorToken);
  const uniqueSorted = Array.from(new Set(tokens)).sort((a, b) => a.localeCompare(b));
  return uniqueSorted.join('+');
};

const buildCombinedColorName = (tokens: string[]): string => {
  const byNormalized = new Map<string, string>();

  tokens.forEach((token) => {
    const normalized = normalizeColorToken(token);
    if (!normalized || byNormalized.has(normalized)) return;

    const cleaned = token.trim().replace(/\s+/g, ' ');
    byNormalized.set(normalized, cleaned);
  });

  return Array.from(byNormalized.values()).join(' + ');
};

const getPresetColorCode = (colorName: string): string | undefined => {
  const normalized = normalizeColorToken(colorName);
  return PREDEFINED_COLORS.find((entry) => normalizeColorToken(entry.name) === normalized)?.code;
};

const getSwatchGradient = (colorVariant: ColorVariant): React.CSSProperties => {
  const tokens = splitColorTokens(colorVariant.color_name);
  if (tokens.length <= 1) {
    return { backgroundColor: colorVariant.color_code };
  }

  const swatches = tokens
    .slice(0, 3)
    .map((token) => getPresetColorCode(token) ?? colorVariant.color_code ?? '#9CA3AF');

  if (swatches.length === 2) {
    return {
      background: `linear-gradient(90deg, ${swatches[0]} 0%, ${swatches[0]} 50%, ${swatches[1]} 50%, ${swatches[1]} 100%)`,
    };
  }

  return {
    background: `linear-gradient(90deg, ${swatches[0]} 0%, ${swatches[0]} 33.33%, ${swatches[1]} 33.33%, ${swatches[1]} 66.66%, ${swatches[2]} 66.66%, ${swatches[2]} 100%)`,
  };
};

export const ColorVariantManager: React.FC<ColorVariantManagerProps> = ({
  colorVariants,
  onColorVariantsChange,
  blockedColorNames = [],
  isEditing = false,
  lockStockEditing = false,
}) => {
  const [showColorPicker, setShowColorPicker] = useState(false);
  const [showSizePickerForColorId, setShowSizePickerForColorId] = useState<string | null>(null);
  const [selectedSizes, setSelectedSizes] = useState<string[]>([]);
  const [sizeSystem, setSizeSystem] = useState<SizeSystem>('US');
  const [customSizeInput, setCustomSizeInput] = useState('');
  const [customColorName, setCustomColorName] = useState('');
  const [customColorCode, setCustomColorCode] = useState('#000000');
  const [combinedQuickColors, setCombinedQuickColors] = useState<string[]>([]);

  const formatSizeBySystem = (sizeValue: string) => {
    const parsed = Number(sizeValue);
    if (Number.isNaN(parsed)) return sizeValue;

    let converted = parsed;
    switch (sizeSystem) {
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

  const getSizeLabel = (sizeValue: string) => `${sizeSystem} ${formatSizeBySystem(sizeValue)}`;

  const getStoredSizeLabel = (sizeVariant: SizeVariant) => {
    const rawSize = String(sizeVariant.size ?? '').trim();
    const matched = rawSize.match(/^(US|UK|EU|AU|CN)\s*[:\-]?\s*(.+)$/i);

    if (matched) {
      return `${matched[1].toUpperCase()} ${matched[2].trim()}`;
    }

    const system = sizeVariant.size_system ?? 'US';
    return `${system} ${rawSize}`;
  };

  const addColorVariant = (colorName: string, colorCode: string) => {
    const combinedName = buildCombinedColorName(splitColorTokens(colorName));
    const safeColorName = combinedName || colorName.trim();
    const normalizedColorIdentity = normalizeColorIdentity(safeColorName);
    const resolvedColorCode = getPresetColorCode(safeColorName) ?? colorCode;
    const isDuplicateInCurrent = colorVariants.some(
      (cv) => normalizeColorIdentity(cv.color_name) === normalizedColorIdentity,
    );
    const isDuplicateInBlocked = blockedColorNames.some(
      (name) => normalizeColorIdentity(name) === normalizedColorIdentity,
    );

    // Check if color already exists
    if (isDuplicateInCurrent || isDuplicateInBlocked) {
      void Swal.fire({
        icon: 'warning',
        title: 'Duplicate combined color',
        text: `${safeColorName} already exists. Use a different color combination.`,
      });
      return;
    }

    const newVariant: ColorVariant = {
      id: Date.now().toString(),
      color_name: safeColorName,
      color_code: resolvedColorCode,
      images: [],
      sizes: [],
      isExpanded: true,
    };

    onColorVariantsChange([...colorVariants, newVariant]);
    setShowColorPicker(false);
    setCustomColorName('');
    setCombinedQuickColors([]);
  };

  const handleQuickColorSelection = (colorName: string) => {
    setCombinedQuickColors((prev) =>
      prev.includes(colorName)
        ? prev.filter((name) => name !== colorName)
        : [...prev, colorName],
    );
  };

  const addSelectedQuickVariant = () => {
    if (combinedQuickColors.length === 0) {
      void Swal.fire({
        icon: 'warning',
        title: 'No color selected',
        text: 'Select at least one quick color.',
      });
      return;
    }

    const combinedName = buildCombinedColorName(combinedQuickColors);
    const firstColorCode = getPresetColorCode(combinedQuickColors[0]) ?? customColorCode;
    addColorVariant(combinedName, firstColorCode);
  };

  const handleAddFromPicker = () => {
    // Priority: if quick-select has chosen colors, add those directly (single or combined).
    if (combinedQuickColors.length > 0) {
      addSelectedQuickVariant();
      return;
    }

    // Otherwise, add from custom name + color code.
    if (!customColorName.trim()) {
      void Swal.fire({
        icon: 'warning',
        title: 'Missing color name',
        text: 'Please enter a color name',
      });
      return;
    }

    addColorVariant(customColorName.trim(), customColorCode);
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

  const addSizesToColor = (colorId: string, sizesToAdd: string[]) => {
    if (lockStockEditing) return;

    const colorVariant = colorVariants.find(cv => cv.id === colorId);
    if (!colorVariant || sizesToAdd.length === 0) return;

    const existingSizes = new Set(
      colorVariant.sizes.map((s) => `${s.size_system ?? 'US'}::${s.size}`)
    );

    const uniqueNewSizes = sizesToAdd
      .map((size) => ({
        normalizedSize: formatSizeBySystem(size),
        system: sizeSystem,
      }))
      .filter((entry) => !existingSizes.has(`${entry.system}::${entry.normalizedSize}`));

    if (uniqueNewSizes.length === 0) {
      void Swal.fire({
        icon: 'warning',
        title: 'Duplicate size',
        text: 'Selected sizes already exist for this color.',
        confirmButtonColor: '#000000',
      });
      return;
    }

    const newSizes: SizeVariant[] = uniqueNewSizes.map((entry, index) => ({
      id: `${Date.now()}-${index}-${entry.system}-${entry.normalizedSize}`,
      size: entry.normalizedSize,
      size_system: entry.system,
      quantity: 0,
    }));

    updateColorVariant(colorId, {
      sizes: [...colorVariant.sizes, ...newSizes],
    });
  };

  const openSizePicker = (colorId: string) => {
    if (lockStockEditing) return;

    const colorVariant = colorVariants.find(cv => cv.id === colorId);
    if (!colorVariant) return;

    setSelectedSizes([]);
    setCustomSizeInput('');
    setShowSizePickerForColorId(colorId);
  };

  const toggleSizeSelection = (size: string) => {
    setSelectedSizes((prev) =>
      prev.includes(size) ? prev.filter((s) => s !== size) : [...prev, size],
    );
  };

  const addCustomSizeToSelection = () => {
    const normalizedInput = customSizeInput.trim().replace(/\s+/g, ' ');
    if (!normalizedInput) return;

    if (selectedSizes.includes(normalizedInput)) {
      void Swal.fire({
        icon: 'warning',
        title: 'Duplicate size',
        text: 'This size is already selected.',
        confirmButtonColor: '#000000',
      });
      return;
    }

    setSelectedSizes((prev) => [...prev, normalizedInput]);
    setCustomSizeInput('');
  };

  const applySelectedSizes = () => {
    if (!showSizePickerForColorId || selectedSizes.length === 0) {
      setShowSizePickerForColorId(null);
      setSelectedSizes([]);
      setCustomSizeInput('');
      return;
    }

    addSizesToColor(showSizePickerForColorId, selectedSizes);
    setShowSizePickerForColorId(null);
    setSelectedSizes([]);
    setCustomSizeInput('');
  };

  const updateSizeQuantityByStep = (colorId: string, sizeId: string, delta: number) => {
    if (lockStockEditing) return;

    const colorVariant = colorVariants.find(cv => cv.id === colorId);
    if (!colorVariant) return;

    const sizeVariant = colorVariant.sizes.find((s) => s.id === sizeId);
    if (!sizeVariant) return;

    const nextValue = Math.max(0, sizeVariant.quantity + delta);
    updateSize(colorId, sizeId, { quantity: nextValue });
  };

  const updateSizeQuantityFromInput = (colorId: string, sizeId: string, rawValue: string) => {
    if (lockStockEditing) return;

    // Allow only digits so quantity always remains a non-negative integer.
    if (!/^\d*$/.test(rawValue)) return;

    const nextValue = rawValue === '' ? 0 : parseInt(rawValue, 10);
    updateSize(colorId, sizeId, { quantity: nextValue });
  };

  const removeSizeFromColor = (colorId: string, sizeId: string) => {
    if (lockStockEditing) return;

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
            {lockStockEditing ? 'Variants' : 'Add Variants'}
          </h3>
          <p className="text-sm text-gray-600 dark:text-gray-400 mt-0.5">
            {lockStockEditing
              ? 'Variants are managed from inventory. Staff can view variant details only.'
              : 'Add colors, then upload 5-10 images per color'}
          </p>
        </div>
        {!isEditing && (
          <button
            type="button"
            onClick={() => setShowColorPicker(true)}
            className="px-4 py-2 bg-black hover:bg-gray-800 text-white rounded-lg text-sm font-medium transition-colors inline-flex items-center gap-2"
          >
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
            </svg>
            Add Color
          </button>
        )}
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
                title="Close color picker"
                aria-label="Close color picker"
              >
                <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>

            {/* Quick Select */}
            <div className="mb-6">
              <h5 className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                Quick Select
              </h5>
              <div className="grid grid-cols-4 sm:grid-cols-6 gap-3">
                {PREDEFINED_COLORS.map((color) => (
                  <button
                    key={color.name}
                    type="button"
                    onClick={() => handleQuickColorSelection(color.name)}
                    className={`group relative flex flex-col items-center gap-2 p-3 rounded-lg border-2 transition-all hover:shadow-md ${
                      combinedQuickColors.includes(color.name)
                        ? 'border-gray-900 bg-gray-100 dark:border-gray-300 dark:bg-gray-700'
                        : 'border-gray-200 dark:border-gray-700 hover:border-gray-500 dark:hover:border-gray-400'
                    }`}
                  >
                    {combinedQuickColors.includes(color.name) && (
                      <span className="absolute right-2 top-2 inline-flex h-4 w-4 items-center justify-center rounded-full bg-black text-[10px] font-bold text-white">
                        ✓
                      </span>
                    )}
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
              <div className="mt-4 min-h-6 flex items-center gap-2">
                {combinedQuickColors.length > 0 && (
                  <>
                    <p className="text-xs text-gray-700 dark:text-gray-300">
                      {combinedQuickColors.length > 1
                        ? `${combinedQuickColors.length} colors selected (will add as combined when you click Add)`
                        : `${combinedQuickColors[0]} selected (click Add to use this color)`}
                    </p>
                    <button
                      type="button"
                      onClick={() => setCombinedQuickColors([])}
                      className="px-3 py-1.5 rounded-lg border border-gray-300 dark:border-gray-600 text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700"
                    >
                      Clear
                    </button>
                  </>
                )}
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
                  title="Pick custom color"
                />
                <button
                  type="button"
                  onClick={handleAddFromPicker}
                  className="px-4 py-2 bg-black hover:bg-gray-800 text-white rounded-lg font-medium transition-colors"
                >
                  Add
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Size Picker Modal */}
      {showSizePickerForColorId && (
        <div className="fixed inset-0 z-[1000002] bg-black/50 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-white dark:bg-gray-800 rounded-xl max-w-2xl w-full p-6 shadow-2xl max-h-[85vh] flex flex-col">
            <div className="flex items-center justify-between mb-4">
              <div>
                <h4 className="text-xl font-semibold text-gray-900 dark:text-white">Select Sizes</h4>
                <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">
                  Choose your size system before selecting.
                </p>
              </div>
              <button
                type="button"
                onClick={() => {
                  setShowSizePickerForColorId(null);
                  setSelectedSizes([]);
                  setCustomSizeInput('');
                }}
                className="text-gray-500 hover:text-gray-700 dark:hover:text-gray-300"
                title="Close size picker"
                aria-label="Close size picker"
              >
                <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>

            <p className="text-sm text-gray-600 dark:text-gray-400 mb-3">
              You can select multiple sizes at once.
            </p>

            <div className="mb-3 flex items-center justify-between gap-3">
              <div className="inline-flex flex-wrap rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 p-1 gap-1">
                <button
                  type="button"
                  onClick={() => setSizeSystem('US')}
                  className={`px-3 py-1.5 rounded-md text-xs font-semibold transition-colors ${
                    sizeSystem === 'US'
                      ? 'bg-black text-white dark:bg-white dark:text-gray-900'
                      : 'text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-800'
                  }`}
                >
                  US
                </button>
                <button
                  type="button"
                  onClick={() => setSizeSystem('UK')}
                  className={`px-3 py-1.5 rounded-md text-xs font-semibold transition-colors ${
                    sizeSystem === 'UK'
                      ? 'bg-black text-white dark:bg-white dark:text-gray-900'
                      : 'text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-800'
                  }`}
                >
                  UK
                </button>
                <button
                  type="button"
                  onClick={() => setSizeSystem('EU')}
                  className={`px-3 py-1.5 rounded-md text-xs font-semibold transition-colors ${
                    sizeSystem === 'EU'
                      ? 'bg-black text-white dark:bg-white dark:text-gray-900'
                      : 'text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-800'
                  }`}
                >
                  EU
                </button>
                <button
                  type="button"
                  onClick={() => setSizeSystem('AU')}
                  className={`px-3 py-1.5 rounded-md text-xs font-semibold transition-colors ${
                    sizeSystem === 'AU'
                      ? 'bg-black text-white dark:bg-white dark:text-gray-900'
                      : 'text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-800'
                  }`}
                >
                  AU
                </button>
                <button
                  type="button"
                  onClick={() => setSizeSystem('CN')}
                  className={`px-3 py-1.5 rounded-md text-xs font-semibold transition-colors ${
                    sizeSystem === 'CN'
                      ? 'bg-black text-white dark:bg-white dark:text-gray-900'
                      : 'text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-800'
                  }`}
                >
                  CN
                </button>
              </div>
              <span className="text-xs text-gray-500 dark:text-gray-400">
                Saved size value remains in US standard.
              </span>
            </div>

            <div className="flex-1 overflow-y-auto border border-gray-200 dark:border-gray-700 rounded-lg p-3">
              <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2">
                {SIZE_OPTIONS.map((size) => {
                  const colorVariant = colorVariants.find((cv) => cv.id === showSizePickerForColorId);
                  const isExisting = !!colorVariant?.sizes.some((s) => s.size === size);
                  const isSelected = selectedSizes.includes(size);

                  return (
                    <label
                      key={size}
                      className={`flex items-center gap-2 px-3 py-2 rounded-lg border text-sm ${
                        isExisting
                          ? 'border-gray-200 bg-gray-100 text-gray-400 cursor-not-allowed dark:border-gray-700 dark:bg-gray-900/50 dark:text-gray-500'
                          : isSelected
                            ? 'border-gray-900 bg-gray-100 text-gray-900 dark:border-gray-300 dark:bg-gray-700 dark:text-gray-100'
                            : 'border-gray-300 text-gray-700 hover:border-gray-500 dark:border-gray-600 dark:text-gray-200'
                      }`}
                    >
                      <input
                        type="checkbox"
                        checked={isSelected}
                        disabled={isExisting}
                        onChange={() => toggleSizeSelection(size)}
                        className="h-4 w-4 rounded border-gray-300 accent-black text-gray-900 focus:ring-gray-900"
                        title={`Select ${getSizeLabel(size)}`}
                      />
                      <span>{getSizeLabel(size)}</span>
                    </label>
                  );
                })}
              </div>

              <div className="mt-3 pt-3 border-t border-gray-200 dark:border-gray-700">
                <p className="text-xs text-gray-600 dark:text-gray-400 mb-2">
                  Other size (not in list)
                </p>
                <div className="flex items-center gap-2">
                  <input
                    type="text"
                    value={customSizeInput}
                    onChange={(e) => setCustomSizeInput(e.target.value)}
                    onKeyDown={(e) => {
                      if (e.key === 'Enter') {
                        e.preventDefault();
                        addCustomSizeToSelection();
                      }
                    }}
                    placeholder="e.g., 2.5, 16, Kids 4"
                    className="flex-1 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-900 text-sm text-gray-900 dark:text-white"
                  />
                  <button
                    type="button"
                    onClick={addCustomSizeToSelection}
                    className="px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-sm text-gray-900 dark:text-white hover:bg-gray-50 dark:hover:bg-gray-800"
                  >
                    Add Other
                  </button>
                </div>
              </div>
            </div>

            <div className="mt-4 flex items-center justify-end gap-3">
              <button
                type="button"
                onClick={() => {
                  setShowSizePickerForColorId(null);
                  setSelectedSizes([]);
                  setCustomSizeInput('');
                }}
                className="px-4 py-2 rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700"
              >
                Cancel
              </button>
              <button
                type="button"
                onClick={applySelectedSizes}
                className="px-4 py-2 rounded-lg bg-black text-white hover:bg-gray-800"
              >
                Add Selected ({selectedSizes.length})
              </button>
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
                  style={getSwatchGradient(colorVariant)}
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
                {!lockStockEditing && (
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
                )}
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
                    readOnly={lockStockEditing}
                  />
                </div>

                {/* Size & Quantity */}
                <div>
                  <div className="flex items-center justify-between mb-2">
                    <h5 className="text-sm font-medium text-gray-700 dark:text-gray-300">
                      Size & Stock
                    </h5>
                    {!lockStockEditing && (
                      <button
                        type="button"
                        onClick={() => openSizePicker(colorVariant.id)}
                        className="px-3 py-1.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-white text-sm hover:bg-gray-50 dark:hover:bg-gray-800"
                      >
                        + Add Size
                      </button>
                    )}
                  </div>

                  {lockStockEditing && (
                    <p className="mb-2 text-xs text-amber-700 dark:text-amber-300">
                      Stock editing is disabled while updating this product.
                    </p>
                  )}

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
                              {getStoredSizeLabel(sizeVariant)}
                            </span>
                            <button
                              type="button"
                              onClick={() => removeSizeFromColor(colorVariant.id, sizeVariant.id)}
                              className="text-red-600 hover:text-red-700 dark:hover:text-red-400 disabled:cursor-not-allowed disabled:opacity-40"
                              title="Remove size"
                              disabled={lockStockEditing}
                            >
                              <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                              </svg>
                            </button>
                          </div>
                          <div className="flex items-center justify-center gap-2">
                            <button
                              type="button"
                              onClick={() => updateSizeQuantityByStep(colorVariant.id, sizeVariant.id, -1)}
                              className="h-10 w-10 rounded-full border border-gray-300 bg-gray-100 text-xl leading-none text-gray-700 hover:bg-gray-200 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                              aria-label={`Decrease quantity for ${getStoredSizeLabel(sizeVariant)}`}
                              disabled={lockStockEditing}
                            >
                              -
                            </button>
                            <input
                              type="text"
                              inputMode="numeric"
                              pattern="[0-9]*"
                              value={String(sizeVariant.quantity)}
                              onChange={(e) => updateSizeQuantityFromInput(colorVariant.id, sizeVariant.id, e.target.value)}
                              onKeyDown={(e) => {
                                const allowedKeys = [
                                  'Backspace',
                                  'Delete',
                                  'ArrowLeft',
                                  'ArrowRight',
                                  'Tab',
                                  'Home',
                                  'End',
                                ];

                                if (allowedKeys.includes(e.key)) return;
                                if (/^\d$/.test(e.key)) return;

                                e.preventDefault();
                              }}
                              className="h-10 w-14 px-2 rounded-full border border-gray-300 bg-white text-center text-sm font-semibold text-gray-900 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
                              title={`Quantity for ${getStoredSizeLabel(sizeVariant)}`}
                              disabled={lockStockEditing}
                            />
                            <button
                              type="button"
                              onClick={() => updateSizeQuantityByStep(colorVariant.id, sizeVariant.id, 1)}
                              className="h-10 w-10 rounded-full border border-gray-300 bg-gray-100 text-xl leading-none text-gray-700 hover:bg-gray-200 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                              aria-label={`Increase quantity for ${getStoredSizeLabel(sizeVariant)}`}
                              disabled={lockStockEditing}
                            >
                              +
                            </button>
                          </div>
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
        <div className="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-lg p-4">
          <div className="flex items-start gap-3">
            <svg className="w-5 h-5 text-gray-900 dark:text-gray-200 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <div className="flex-1">
              <p className="text-sm font-medium text-gray-900 dark:text-white">
                Product Summary
              </p>
              <div className="grid grid-cols-3 gap-4 mt-2 text-sm text-gray-700 dark:text-gray-300">
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
