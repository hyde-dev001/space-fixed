import { beforeEach, describe, expect, it } from 'vitest';
import { isLightOnlyComponent, isUserSideComponent, syncPageTheme } from '../pageTheme';

describe('page theme scope', () => {
  beforeEach(() => {
    document.documentElement.className = 'dark';
    localStorage.clear();
  });

  it('enables dark mode for shop owner registration alongside customer pages', () => {
    expect(isLightOnlyComponent('UserSide/Auth/ShopOwnerRegistration')).toBe(false);
    expect(isLightOnlyComponent('Notifications/CustomerNotifications')).toBe(false);
    expect(isLightOnlyComponent('Notifications/ShopOwnerNotifications')).toBe(false);
    expect(isUserSideComponent('UserSide/Products/LandingPage')).toBe(true);
    expect(isUserSideComponent('Notifications/CustomerNotifications')).toBe(true);
  });

  it('applies the saved dark theme to shop owner registration', () => {
    localStorage.setItem('theme', 'dark');

    syncPageTheme('UserSide/Auth/ShopOwnerRegistration');

    expect(document.documentElement).not.toHaveClass('light-page');
    expect(document.documentElement).toHaveClass('dark');
    expect(document.documentElement).toHaveClass('userside-theme');
    expect(document.documentElement).toHaveClass('userside-dark');
  });

  it('applies the saved dark theme to customer notifications and user-side pages', () => {
    localStorage.setItem('theme', 'dark');

    syncPageTheme('Notifications/CustomerNotifications');

    expect(document.documentElement).not.toHaveClass('light-page');
    expect(document.documentElement).toHaveClass('dark');
    expect(document.documentElement).toHaveClass('userside-theme');
    expect(document.documentElement).toHaveClass('userside-dark');
  });

  it('restores the saved dark theme on dark-enabled pages', () => {
    localStorage.setItem('theme', 'dark');
    syncPageTheme('Notifications/ShopOwnerNotifications');

    expect(document.documentElement).not.toHaveClass('light-page');
    expect(document.documentElement).toHaveClass('dark');
  });
});
