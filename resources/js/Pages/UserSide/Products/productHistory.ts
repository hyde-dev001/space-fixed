export type ProductRailItem = {
  id: number;
  name: string;
  url: string;
  image: string | null;
  price: string;
  compare_at_price: string | null;
  brand: string | null;
  category: string | null;
};

type ProductHistoryStorage = Pick<Storage, 'getItem' | 'setItem'>;

export const RECENTLY_VIEWED_STORAGE_KEY = 'solespace:recently-viewed-products:v1';

const MAX_HISTORY_ITEMS = 8;

const isNullableString = (value: unknown): value is string | null => (
  value === null || typeof value === 'string'
);

const isProductRailItem = (value: unknown): value is ProductRailItem => {
  if (!value || typeof value !== 'object') {
    return false;
  }

  const item = value as Record<string, unknown>;

  return Number.isInteger(item.id)
    && typeof item.name === 'string'
    && typeof item.url === 'string'
    && item.url.startsWith('/products/')
    && isNullableString(item.image)
    && typeof item.price === 'string'
    && isNullableString(item.compare_at_price)
    && isNullableString(item.brand)
    && isNullableString(item.category);
};

const readHistory = (storage: ProductHistoryStorage): ProductRailItem[] => {
  try {
    const rawHistory = storage.getItem(RECENTLY_VIEWED_STORAGE_KEY);
    const parsedHistory: unknown = rawHistory ? JSON.parse(rawHistory) : [];

    return Array.isArray(parsedHistory) ? parsedHistory.filter(isProductRailItem) : [];
  } catch {
    return [];
  }
};

export const registerRecentlyViewed = (
  storage: ProductHistoryStorage,
  currentProduct: ProductRailItem,
): ProductRailItem[] => {
  const seenProductIds = new Set([currentProduct.id]);
  const previousProducts: ProductRailItem[] = [];

  for (const product of readHistory(storage)) {
    if (seenProductIds.has(product.id)) {
      continue;
    }

    seenProductIds.add(product.id);
    previousProducts.push(product);

    if (previousProducts.length === MAX_HISTORY_ITEMS - 1) {
      break;
    }
  }

  try {
    storage.setItem(
      RECENTLY_VIEWED_STORAGE_KEY,
      JSON.stringify([currentProduct, ...previousProducts]),
    );
  } catch {
    // Storage can be disabled or full; the product page remains usable.
  }

  return previousProducts;
};
