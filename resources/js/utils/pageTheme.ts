export type Theme = 'light' | 'dark';

const LIGHT_ONLY_COMPONENTS = new Set<string>();

const USER_SIDE_COMPONENTS = new Set([
  'Auth/SetupPassword',
  'Notifications/CustomerNotifications',
]);

export const isLightOnlyComponent = (componentName: string): boolean =>
  LIGHT_ONLY_COMPONENTS.has(componentName);

export const isUserSideComponent = (componentName: string): boolean =>
  USER_SIDE_COMPONENTS.has(componentName) || componentName.startsWith('UserSide/');

export const applyThemeClass = (theme: Theme): void => {
  const root = document.documentElement;
  const isUserSidePage = root.classList.contains('userside-theme');
  const isDarkTheme = theme === 'dark' && !root.classList.contains('light-page');

  root.classList.toggle('dark', isDarkTheme);
  root.classList.toggle('userside-dark', isDarkTheme && isUserSidePage);
};

export const syncPageTheme = (componentName: string): void => {
  const root = document.documentElement;
  const isLightOnly = isLightOnlyComponent(componentName);
  const isUserSidePage = isUserSideComponent(componentName);

  root.classList.toggle('light-page', isLightOnly);
  root.classList.toggle('userside-theme', isUserSidePage);

  const savedTheme = localStorage.getItem('theme');
  applyThemeClass(savedTheme === 'dark' ? 'dark' : 'light');
};
