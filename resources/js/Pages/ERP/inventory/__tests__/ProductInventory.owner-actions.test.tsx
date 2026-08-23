import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const productInventorySource = readFileSync(
  resolve(process.cwd(), 'resources/js/Pages/ERP/inventory/ProductInventory.tsx'),
  'utf8',
);
const uploadInventorySource = readFileSync(
  resolve(process.cwd(), 'resources/js/Pages/ERP/inventory/UploadInventory.tsx'),
  'utf8',
);

describe('owner inventory action boundary', () => {
  it('does not expose the matrix-denied Upload Stocks action from Product Inventory', () => {
    expect(productInventorySource).toContain('const ownerMode = auth?.erpActor?.ownerMode === true;');
    expect(productInventorySource).not.toContain('Upload Stocks');
    expect(productInventorySource).not.toContain('upload-stocks');
  });

  it('keeps the legacy upload form and its image surface employee-only', () => {
    expect(uploadInventorySource).toContain('const ownerMode = auth?.erpActor?.ownerMode === true;');
    expect(uploadInventorySource).toContain('if (ownerMode) return;');
    expect(uploadInventorySource).toContain('{!ownerMode && (');
  });
});
