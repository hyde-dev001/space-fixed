import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const repairNoticeFiles = [
  'resources/js/Pages/ERP/repairer/repairerSupport.tsx',
  'resources/js/Pages/ERP/CRM/customerSupport.tsx',
  'resources/js/Pages/ShopOwner/Customers/customer management/customerSupport.tsx',
  'resources/js/Pages/ShopOwner/Customers/customer management/repairSupport.tsx',
  'resources/js/Pages/UserSide/Communication/message.tsx',
];

describe('repair progress notice presentation', () => {
  it.each(repairNoticeFiles)('uses a light neutral surface in %s', (path) => {
    const source = readFileSync(resolve(path), 'utf8');
    const noticeIndex = Math.max(
      source.indexOf("We'll keep you updated on the progress of your repair."),
      source.indexOf("We\\'ll keep you updated on the progress of your repair."),
    );
    const noticeMarkup = source.slice(Math.max(0, noticeIndex - 800), noticeIndex);

    expect(noticeIndex, path).toBeGreaterThan(-1);
    expect(noticeMarkup, path).toContain('bg-gray-100 rounded-lg px-3 py-2.5 mb-4');
    expect(noticeMarkup, path).toContain('text-xs text-gray-900 leading-relaxed');
    expect(noticeMarkup, path).not.toContain('bg-blue-50 rounded-lg px-3 py-2.5 mb-4');
    expect(noticeMarkup, path).not.toContain('text-xs text-blue-900 leading-relaxed');
  });
});
