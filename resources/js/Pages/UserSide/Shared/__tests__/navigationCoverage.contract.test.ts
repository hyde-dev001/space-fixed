import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const pageSources = [
  'resources/js/Pages/UserSide/Shared/articles.tsx',
  'resources/js/Pages/Notifications/CustomerNotifications.tsx',
  'resources/js/Pages/Notifications/CustomerPreferences.tsx',
  'resources/js/Pages/UserSide/Orders/payment.tsx',
];
const standalonePageSources = [
  'resources/js/Pages/UserSide/Profile/VirtualShowroomPage.tsx',
];

describe('user-side page navigation coverage', () => {
  it.each(pageSources)('mounts the shared Navigation component in %s', (file) => {
    const source = readFileSync(resolve(file), 'utf8');

    expect(source).toContain('Navigation');
    expect(source).toContain('<Navigation');

    if (file.endsWith('payment.tsx')) {
      expect(source).toContain('{!isPremiumPayment && <Navigation />}');
      expect(source).not.toContain('{!isPremiumPayment && <div className="hidden xl:block"><Navigation /></div>}');
    }
  });

  it.each(standalonePageSources)('keeps standalone pages outside the shared navigation shell in %s', (file) => {
    const source = readFileSync(resolve(file), 'utf8');

    expect(source).not.toContain('Navigation');
    expect(source).not.toContain('<Navigation');
  });
});
