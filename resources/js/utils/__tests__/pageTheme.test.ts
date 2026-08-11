import { beforeEach, describe, expect, it } from 'vitest';
import { isLightOnlyComponent, syncPageTheme } from '../pageTheme';

describe('page theme scope', () => {
  beforeEach(() => {
    document.documentElement.className = 'dark';
    localStorage.clear();
  });

  it('identifies registration and customer notifications as light-only pages', () => {
    expect(isLightOnlyComponent('UserSide/Auth/ShopOwnerRegistration')).toBe(true);
    expect(isLightOnlyComponent('Notifications/CustomerNotifications')).toBe(true);
    expect(isLightOnlyComponent('Notifications/ShopOwnerNotifications')).toBe(false);
  });

  it('removes dark mode from light-only pages even when dark is saved', () => {
    localStorage.setItem('theme', 'dark');

    syncPageTheme('Notifications/CustomerNotifications');

    expect(document.documentElement).toHaveClass('light-page');
    expect(document.documentElement).not.toHaveClass('dark');
  });

  it('restores the saved dark theme on dark-enabled pages', () => {
    localStorage.setItem('theme', 'dark');
    syncPageTheme('Notifications/ShopOwnerNotifications');

    expect(document.documentElement).not.toHaveClass('light-page');
    expect(document.documentElement).toHaveClass('dark');
  });
});
