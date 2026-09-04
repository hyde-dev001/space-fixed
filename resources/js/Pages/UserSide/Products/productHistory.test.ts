import { describe, expect, it } from 'vitest';

import {
  RECENTLY_VIEWED_STORAGE_KEY,
  registerRecentlyViewed,
  type ProductRailItem,
} from './productHistory';

const product = (id: number): ProductRailItem => ({
  id,
  name: `Product ${id}`,
  url: `/products/product-${id}`,
  image: `/storage/products/product-${id}.jpg`,
  price: `₱${id},000`,
  compare_at_price: null,
  brand: 'SoleSpace',
  category: 'shoes',
});

const memoryStorage = (initial: unknown = []) => {
  let value = JSON.stringify(initial);

  return {
    getItem: () => value,
    setItem: (_key: string, nextValue: string) => {
      value = nextValue;
    },
    read: () => JSON.parse(value) as ProductRailItem[],
  };
};

describe('registerRecentlyViewed', () => {
  it('returns prior products and persists the current product first without duplicates', () => {
    const storage = memoryStorage([product(2), product(2), product(1), product(3)]);

    const visibleItems = registerRecentlyViewed(storage, product(1));

    expect(visibleItems.map(({ id }) => id)).toEqual([2, 3]);
    expect(storage.read().map(({ id }) => id)).toEqual([1, 2, 3]);
  });

  it('caps persisted history at eight items including the current product', () => {
    const storage = memoryStorage(Array.from({ length: 10 }, (_, index) => product(index + 1)));

    const visibleItems = registerRecentlyViewed(storage, product(11));

    expect(storage.read()).toHaveLength(8);
    expect(storage.read()[0].id).toBe(11);
    expect(visibleItems.map(({ id }) => id)).toEqual([1, 2, 3, 4, 5, 6, 7]);
  });

  it('ignores malformed JSON and invalid item shapes', () => {
    const malformedStorage = {
      getItem: () => '{not-json',
      setItem: () => undefined,
    };
    const invalidStorage = memoryStorage([{ id: 1 }, null, 'bad']);

    expect(registerRecentlyViewed(malformedStorage, product(2))).toEqual([]);
    expect(registerRecentlyViewed(invalidStorage, product(2))).toEqual([]);
  });

  it('rejects stored links outside the product route', () => {
    const unsafeProduct = { ...product(1), url: 'javascript:alert(1)' };
    const storage = memoryStorage([unsafeProduct]);

    expect(registerRecentlyViewed(storage, product(2))).toEqual([]);
  });

  it('removes history entries whose product slug is no longer in the active catalog', () => {
    const storage = memoryStorage([product(2), product(1)]);

    const visibleItems = registerRecentlyViewed(storage, product(3), ['product-1', 'product-3']);

    expect(visibleItems.map(({ id }) => id)).toEqual([1]);
    expect(storage.read().map(({ id }) => id)).toEqual([3, 1]);
  });

  it('handles blocked reads and writes without breaking the page', () => {
    const blockedRead = {
      getItem: () => {
        throw new Error('blocked');
      },
      setItem: () => undefined,
    };
    const blockedWrite = {
      getItem: (key: string) => key === RECENTLY_VIEWED_STORAGE_KEY
        ? JSON.stringify([product(1)])
        : null,
      setItem: () => {
        throw new Error('quota');
      },
    };

    expect(registerRecentlyViewed(blockedRead, product(2))).toEqual([]);
    expect(registerRecentlyViewed(blockedWrite, product(2))).toEqual([product(1)]);
  });
});
