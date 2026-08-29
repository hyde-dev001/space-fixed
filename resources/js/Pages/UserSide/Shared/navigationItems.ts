export const getCustomerNavItems = (isAuthenticated: boolean) => [
  { route: 'products', label: 'Products', dropdownKey: 'shoes' },
  { route: 'products', label: 'Men', params: { category: 'men' }, dropdownKey: 'men' },
  { route: 'products', label: 'Women', params: { category: 'women' }, dropdownKey: 'women' },
  { route: 'products', label: 'Kids', params: { category: 'kids' }, dropdownKey: 'kids' },
  { route: 'products', label: 'Sports', params: { category: 'sports' }, dropdownKey: 'sports' },
  { route: 'repair', label: 'Repair' },
  ...(isAuthenticated ? [
    { route: 'my-orders', label: 'Orders' },
    { route: 'my-repairs', label: 'Repairs' },
  ] : []),
  ...(isAuthenticated ? [] : [{ route: 'services', label: 'Services' }]),
  ...(isAuthenticated ? [] : [{ route: 'login', label: 'ACCOUNT' }]),
];
