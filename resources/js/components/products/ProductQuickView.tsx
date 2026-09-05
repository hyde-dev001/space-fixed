import React, { useEffect, useMemo, useRef, useState } from 'react';
import { Link } from '@inertiajs/react';
import AddToCartButton from '../CartActions';

export type ProductQuickViewColorVariantImage = {
  id?: number;
  image_path?: string | null;
  image_url?: string | null;
  is_thumbnail?: boolean;
  sort_order?: number | null;
};

export type ProductQuickViewColorVariant = {
  id: number;
  color_name: string;
  images?: ProductQuickViewColorVariantImage[] | null;
};

export type ProductQuickViewProduct = {
  id: number;
  name: string;
  slug: string;
  price: number;
  compare_at_price?: number | null;
  main_image: string | null;
  gallery_images?: string[];
  brand?: string | null;
  stock_quantity: number;
  sizes_available?: unknown[] | null;
  colors_available?: unknown[] | null;
  color_variants?: ProductQuickViewColorVariant[] | null;
  shop_owner?: {
    id: number;
    name?: string;
    business_name?: string;
  };
};

type ProductQuickViewProps = {
  product: ProductQuickViewProduct;
  detailsHref: string;
  triggerRef?: React.RefObject<HTMLButtonElement | null>;
  onClose: () => void;
};

const normalizeOptions = (value: unknown): string[] => {
  if (!Array.isArray(value)) return [];

  return Array.from(
    new Set(
      value
        .filter((option) => option !== null && option !== undefined)
        .map((option) => String(option).trim())
        .filter(Boolean),
    ),
  );
};

const normalizeImageUrl = (value: unknown): string | null => {
  if (typeof value !== 'string') return null;

  const imageUrl = value.trim();
  return imageUrl.length > 0 ? imageUrl : null;
};

const getColorVariantImages = (variant: ProductQuickViewColorVariant): string[] => {
  if (!Array.isArray(variant.images)) return [];

  const sortedImages = [...variant.images].sort((left, right) => {
    if (Boolean(left.is_thumbnail) !== Boolean(right.is_thumbnail)) {
      return left.is_thumbnail ? -1 : 1;
    }

    return Number(left.sort_order ?? 0) - Number(right.sort_order ?? 0);
  });

  return Array.from(
    new Set(
      sortedImages
        .map((image) => normalizeImageUrl(image.image_url) ?? normalizeImageUrl(image.image_path))
        .filter((image): image is string => image !== null),
    ),
  );
};

const formatPrice = (value: number): string => {
  const numericValue = Number(value);
  return `₱${(Number.isFinite(numericValue) ? numericValue : 0).toLocaleString('en-PH', {
    maximumFractionDigits: 2,
  })}`;
};

const ChevronIcon = ({ direction }: { direction: 'left' | 'right' }) => (
  <svg
    className="h-5 w-5"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    strokeWidth="1.75"
    aria-hidden="true"
  >
    <path d={direction === 'left' ? 'm14.5 5-7 7 7 7' : 'm9.5 5 7 7-7 7'} strokeLinecap="round" strokeLinejoin="round" />
  </svg>
);

const QuantityIcon = ({ type }: { type: 'minus' | 'plus' }) => (
  <svg
    className="h-4 w-4"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    strokeWidth="1.75"
    aria-hidden="true"
  >
    <path d="M5 12h14" strokeLinecap="round" />
    {type === 'plus' && <path d="M12 5v14" strokeLinecap="round" />}
  </svg>
);

const ProductQuickView: React.FC<ProductQuickViewProps> = ({ product, detailsHref, triggerRef, onClose }) => {
  const closeButtonRef = useRef<HTMLButtonElement>(null);
  const [selectedImageIndex, setSelectedImageIndex] = useState(0);
  const [failedImage, setFailedImage] = useState<string | null>(null);
  const [quantity, setQuantity] = useState(1);

  const legacyImages = useMemo(() => {
    const candidates = [product.main_image, ...(product.gallery_images ?? [])];

    return Array.from(
      new Set(
        candidates.filter(
          (image): image is string => typeof image === 'string' && image.trim().length > 0,
        ),
      ),
    );
  }, [product.gallery_images, product.main_image]);

  const colorVariants = useMemo(
    () =>
      (product.color_variants ?? []).filter(
        (variant) => typeof variant?.color_name === 'string' && variant.color_name.trim().length > 0,
      ),
    [product.color_variants],
  );

  const sizes = useMemo(() => normalizeOptions(product.sizes_available), [product.sizes_available]);
  const colors = useMemo(() => {
    if (colorVariants.length > 0) {
      return colorVariants.map((variant) => ({
        id: String(variant.id),
        name: variant.color_name.trim(),
        image: getColorVariantImages(variant)[0] ?? null,
      }));
    }

    return normalizeOptions(product.colors_available).map((color) => ({
      id: color,
      name: color,
      image: null,
    }));
  }, [colorVariants, product.colors_available]);
  const [selectedSize, setSelectedSize] = useState<string | null>(sizes[0] ?? null);
  const [selectedColor, setSelectedColor] = useState<string | null>(colors[0]?.name ?? null);

  const selectedColorVariant = useMemo(
    () =>
      colorVariants.find(
        (variant) => variant.color_name.trim().toLowerCase() === selectedColor?.trim().toLowerCase(),
      ) ?? null,
    [colorVariants, selectedColor],
  );
  const selectedColorImages = useMemo(
    () => (selectedColorVariant ? getColorVariantImages(selectedColorVariant) : []),
    [selectedColorVariant],
  );
  const hasColorVariantImages = useMemo(
    () => colorVariants.some((variant) => getColorVariantImages(variant).length > 0),
    [colorVariants],
  );
  const images = useMemo(
    () =>
      selectedColorVariant && hasColorVariantImages
        ? selectedColorImages
        : legacyImages,
    [hasColorVariantImages, legacyImages, selectedColorImages, selectedColorVariant],
  );

  const stockQuantity = Number.isFinite(Number(product.stock_quantity))
    ? Math.max(0, Math.floor(Number(product.stock_quantity)))
    : 0;
  const quantityLimit = Math.max(1, stockQuantity);
  const selectedImage = images[selectedImageIndex] ?? null;
  const hasRequiredSelections =
    (sizes.length === 0 || selectedSize !== null) &&
    (colors.length === 0 || selectedColor !== null);
  const isAddToCartDisabled = stockQuantity <= 0 || !hasRequiredSelections;
  const compareAtPrice = Number(product.compare_at_price);
  const hasCompareAtPrice = Number.isFinite(compareAtPrice) && compareAtPrice > Number(product.price);

  useEffect(() => {
    const previousOverflow = document.body.style.overflow;
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onClose();
    };

    document.body.style.overflow = 'hidden';
    document.addEventListener('keydown', closeOnEscape);
    closeButtonRef.current?.focus();

    return () => {
      document.body.style.overflow = previousOverflow;
      document.removeEventListener('keydown', closeOnEscape);
      triggerRef?.current?.focus();
    };
  }, [onClose, triggerRef]);

  const changeQuantity = (delta: number) => {
    setQuantity((currentQuantity) =>
      Math.min(quantityLimit, Math.max(1, currentQuantity + delta)),
    );
  };

  const moveImage = (direction: number) => {
    setSelectedImageIndex((currentIndex) =>
      Math.min(Math.max(0, currentIndex + direction), Math.max(0, images.length - 1)),
    );
  };

  const selectColor = (color: string) => {
    setSelectedColor(color);
    setSelectedImageIndex(0);
    setFailedImage(null);
  };

  return (
    <div
      className="fixed inset-0 z-[60] flex items-center justify-center overflow-x-hidden overflow-y-auto bg-slate-950/60 p-3 transition-opacity duration-200 motion-reduce:transition-none sm:p-6"
      onClick={(event) => {
        if (event.target === event.currentTarget) onClose();
      }}
    >
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby="quick-view-title"
        className="relative my-0 grid max-h-[calc(100vh-1.5rem)] w-full max-w-5xl overflow-x-hidden overflow-y-auto bg-white shadow-2xl transition-transform duration-200 motion-reduce:transition-none sm:my-4 sm:max-h-[calc(100vh-3rem)] lg:grid-cols-2"
      >
        <button
          ref={closeButtonRef}
          type="button"
          onClick={onClose}
          aria-label="Close quick view"
          className="absolute right-2 top-2 z-10 inline-flex h-11 w-11 items-center justify-center text-slate-700 transition-colors hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-slate-950 focus:ring-offset-2 motion-reduce:transition-none sm:right-3 sm:top-3"
        >
          <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" aria-hidden="true">
            <path d="m6 6 12 12M18 6 6 18" strokeLinecap="round" />
          </svg>
        </button>

        <section className="h-fit self-start bg-white p-4 sm:p-6" aria-label="Product images">
          <div className="relative flex min-h-64 items-center justify-center overflow-hidden bg-white">
            {selectedImage && selectedImage !== failedImage ? (
              <img
                src={selectedImage}
                alt={`${product.name} image ${selectedImageIndex + 1}`}
                className="block h-auto w-auto max-h-[min(70vh,40rem)] max-w-full object-contain"
                onError={() => setFailedImage(selectedImage)}
              />
            ) : (
              <div className="flex h-full items-center justify-center px-6 text-center text-sm text-slate-500">
                No image available
              </div>
            )}

            {images.length > 1 && (
              <>
                <button
                  type="button"
                  onClick={() => moveImage(-1)}
                  disabled={selectedImageIndex === 0}
                  aria-label="Previous product image"
                  className="absolute left-3 top-1/2 inline-flex h-11 w-11 -translate-y-1/2 items-center justify-center bg-white/90 text-slate-900 shadow-sm transition-colors hover:bg-white focus:outline-none focus:ring-2 focus:ring-slate-950 disabled:cursor-not-allowed disabled:opacity-40 motion-reduce:transition-none"
                >
                  <ChevronIcon direction="left" />
                </button>
                <button
                  type="button"
                  onClick={() => moveImage(1)}
                  disabled={selectedImageIndex === images.length - 1}
                  aria-label="Next product image"
                  className="absolute right-3 top-1/2 inline-flex h-11 w-11 -translate-y-1/2 items-center justify-center bg-white/90 text-slate-900 shadow-sm transition-colors hover:bg-white focus:outline-none focus:ring-2 focus:ring-slate-950 disabled:cursor-not-allowed disabled:opacity-40 motion-reduce:transition-none"
                >
                  <ChevronIcon direction="right" />
                </button>
              </>
            )}
          </div>

          {images.length > 1 && (
            <div className="mt-3 flex flex-wrap justify-center gap-2" aria-label="Product image thumbnails">
              {images.map((image, index) => (
                <button
                  key={image}
                  type="button"
                  onClick={() => setSelectedImageIndex(index)}
                  aria-label={`View product image ${index + 1}`}
                  aria-pressed={selectedImageIndex === index}
                  className="h-16 w-16 shrink-0 overflow-hidden border border-transparent bg-white focus:outline-none focus:ring-2 focus:ring-slate-950 focus:ring-offset-1 aria-[pressed=true]:border-slate-950"
                >
                  <img src={image} alt="" className="h-full w-full object-contain" loading="lazy" />
                </button>
              ))}
            </div>
          )}
        </section>

        <section className="flex min-w-0 flex-col p-5 sm:p-8">
          <div className="border-b border-slate-200 pb-5 pr-10">
            <p className="text-xs font-medium uppercase tracking-[0.18em] text-slate-500">
              {product.brand || 'SoleSpace'}
            </p>
            <h2 id="quick-view-title" className="mt-2 font-serif text-2xl leading-tight text-slate-950 sm:text-3xl">
              {product.name}
            </h2>
            <div className="mt-4 flex flex-wrap items-baseline gap-3">
              <p className="text-lg font-semibold text-slate-950">{formatPrice(product.price)}</p>
              {hasCompareAtPrice && (
                <p className="text-sm text-slate-500 line-through">{formatPrice(compareAtPrice)}</p>
              )}
            </div>
          </div>

          <div className="flex flex-1 flex-col gap-6 py-6">
            {colors.length > 0 && (
              <fieldset>
                <legend className="text-xs font-medium uppercase tracking-[0.16em] text-slate-600">Color</legend>
                <div className="mt-3 flex flex-wrap gap-2">
                  {colors.map((color) => (
                    <button
                      key={color.id}
                      type="button"
                      onClick={() => selectColor(color.name)}
                      aria-label={`Color ${color.name}`}
                      aria-pressed={selectedColor === color.name}
                      title={color.name}
                      className="relative h-16 w-16 overflow-hidden rounded-md border border-slate-300 bg-white p-1 text-sm text-slate-800 transition-colors hover:border-slate-950 focus:outline-none focus:ring-2 focus:ring-slate-950 focus:ring-offset-1 aria-[pressed=true]:border-slate-950 aria-[pressed=true]:ring-1 aria-[pressed=true]:ring-slate-950 motion-reduce:transition-none"
                    >
                      {color.image ? (
                        <img src={color.image} alt={color.name} className="h-full w-full object-contain" loading="lazy" />
                      ) : (
                        <span className="block px-1 text-xs leading-tight">{color.name}</span>
                      )}
                      {selectedColor === color.name && (
                        <span className="absolute right-1 top-1 inline-flex h-4 w-4 items-center justify-center rounded-full bg-slate-950 text-white" aria-hidden="true">
                          <svg className="h-3 w-3" viewBox="0 0 20 20" fill="currentColor">
                            <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293z" clipRule="evenodd" />
                          </svg>
                        </span>
                      )}
                    </button>
                  ))}
                </div>
              </fieldset>
            )}

            {sizes.length > 0 && (
              <fieldset>
                <legend className="text-xs font-medium uppercase tracking-[0.16em] text-slate-600">Size</legend>
                <div className="mt-3 flex flex-wrap gap-2">
                  {sizes.map((size) => (
                    <button
                      key={size}
                      type="button"
                      onClick={() => setSelectedSize(size)}
                      aria-label={`Size ${size}`}
                      aria-pressed={selectedSize === size}
                      className="min-h-11 min-w-16 border border-slate-300 px-4 text-sm text-slate-800 transition-colors hover:border-slate-950 focus:outline-none focus:ring-2 focus:ring-slate-950 focus:ring-offset-1 aria-[pressed=true]:border-slate-950 aria-[pressed=true]:bg-slate-950 aria-[pressed=true]:text-white motion-reduce:transition-none"
                    >
                      {size}
                    </button>
                  ))}
                </div>
              </fieldset>
            )}

            <div>
              <p id="quick-view-quantity-label" className="text-xs font-medium uppercase tracking-[0.16em] text-slate-600">
                Quantity
              </p>
              <div
                className="mt-3 inline-flex items-center border border-slate-300"
                id="quick-view-quantity"
                role="group"
                aria-labelledby="quick-view-quantity-label"
              >
                <button
                  type="button"
                  onClick={() => changeQuantity(-1)}
                  disabled={quantity <= 1}
                  aria-label="Decrease quantity"
                  className="inline-flex h-11 w-11 items-center justify-center text-slate-800 transition-colors hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-slate-950 disabled:cursor-not-allowed disabled:opacity-40 motion-reduce:transition-none"
                >
                  <QuantityIcon type="minus" />
                </button>
                <output className="min-w-10 text-center text-sm font-medium text-slate-950" aria-live="polite">
                  {quantity}
                </output>
                <button
                  type="button"
                  onClick={() => changeQuantity(1)}
                  disabled={quantity >= quantityLimit}
                  aria-label="Increase quantity"
                  className="inline-flex h-11 w-11 items-center justify-center text-slate-800 transition-colors hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-slate-950 disabled:cursor-not-allowed disabled:opacity-40 motion-reduce:transition-none"
                >
                  <QuantityIcon type="plus" />
                </button>
              </div>
              <p className="mt-3 text-sm text-slate-500" role="status">
                {stockQuantity > 0 ? `${stockQuantity} available` : 'Out of stock'}
              </p>
            </div>

            <AddToCartButton
              productId={product.id}
              product={{
                ...product,
                size: selectedSize ?? undefined,
                color: selectedColor ?? undefined,
                qty: quantity,
                selectedImage,
              }}
              label="Add to Cart"
              stockQuantity={stockQuantity}
              disabled={isAddToCartDisabled}
              onAdded={onClose}
              className="min-h-11 w-full bg-slate-950 px-5 text-sm font-medium uppercase tracking-[0.12em] text-white transition-colors hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-950 focus:ring-offset-2 disabled:cursor-not-allowed disabled:bg-slate-400 motion-reduce:transition-none"
            />
          </div>

          {product.shop_owner && (
            <p className="border-t border-slate-200 pt-4 text-sm text-slate-500">
              Sold by {product.shop_owner.business_name || product.shop_owner.name || 'SoleSpace seller'}
            </p>
          )}

          <Link
            href={detailsHref}
            onClick={onClose}
            className="mt-6 inline-flex min-h-11 items-center justify-center text-center text-xs font-medium uppercase tracking-[0.14em] text-slate-700 underline decoration-slate-300 underline-offset-4 transition-colors hover:text-slate-950 focus:outline-none focus:ring-2 focus:ring-slate-950 focus:ring-offset-2 motion-reduce:transition-none"
          >
            View product details
          </Link>
        </section>
      </div>
    </div>
  );
};

export default ProductQuickView;
