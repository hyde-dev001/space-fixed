export type Theme = 'light' | 'dark';

const LIGHT_ONLY_COMPONENTS = new Set([
  'UserSide/Auth/ShopOwnerRegistration',
  'Notifications/CustomerNotifications',
]);

export const isLightOnlyComponent = (componentName: string): boolean =>
  LIGHT_ONLY_COMPONENTS.has(componentName);

export const applyThemeClass = (theme: Theme): void => {
  const root = document.documentElement;

  if (theme === 'dark' && !root.classList.contains('light-page')) {
    root.classList.add('dark');
    return;
  }

  root.classList.remove('dark');
};

export const syncPageTheme = (componentName: string): void => {
  const root = document.documentElement;
  const isLightOnly = isLightOnlyComponent(componentName);

  root.classList.toggle('light-page', isLightOnly);

  const savedTheme = localStorage.getItem('theme');
  applyThemeClass(savedTheme === 'dark' ? 'dark' : 'light');
};
