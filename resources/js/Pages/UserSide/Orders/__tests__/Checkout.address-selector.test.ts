import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const checkoutSource = readFileSync(
  resolve(process.cwd(), 'resources/js/Pages/UserSide/Orders/Checkout.tsx'),
  'utf8',
);

const addressSelectorStart = checkoutSource.indexOf('{/* Address Selection Modal */}');
const addAddressModalStart = checkoutSource.indexOf('{/* Add Address Modal */}');
const addressSelectorSource = checkoutSource.slice(addressSelectorStart, addAddressModalStart);

describe('Checkout saved address selector styling', () => {
  it('keeps the selected address white with monochrome controls', () => {
    expect(addressSelectorSource).toContain(
      "? 'border-gray-950 bg-white dark:border-gray-300 dark:bg-gray-900'",
    );
    expect(addressSelectorSource).toContain(
      "selectedAddressId === address.id ? 'border-gray-950' : 'border-gray-300'",
    );
    expect(addressSelectorSource).toContain('className="h-3 w-3 rounded-full bg-gray-950 dark:bg-white"');
    expect(addressSelectorSource).not.toContain('border-blue-600 bg-blue-50');
    expect(addressSelectorSource).not.toContain('dark:border-blue-500 dark:bg-blue-500/10');
    expect(addressSelectorSource).not.toContain('text-blue-600 dark:text-blue-400');
    expect(addressSelectorSource).not.toContain('text-blue-700');
  });
});
